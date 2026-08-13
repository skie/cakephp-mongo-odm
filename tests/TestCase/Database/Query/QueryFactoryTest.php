<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Query;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Query\DeleteQuery;
use Crustum\Mongo\Database\Query\InsertQuery;
use Crustum\Mongo\Database\Query\QueryFactory;
use Crustum\Mongo\Database\Query\SelectQuery;
use Crustum\Mongo\Database\Query\UpdateQuery;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the Database QueryFactory entry points.
 */
#[CoversClass(QueryFactory::class)]
class QueryFactoryTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Connection
     */
    protected Connection $connection;

    /**
     * Set up before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = ConnectionManager::get('test_mongo');
    }

    /**
     * Test select() returns a SelectQuery with fields and collection.
     *
     * @return void
     */
    public function testSelect(): void
    {
        $factory = new QueryFactory($this->connection);
        $query = $factory->select(['title'], 'articles');

        $this->assertInstanceOf(SelectQuery::class, $query);
        $this->assertSame('articles', $query->getCollection());
        $this->assertSame(['title' => 1], $query->getBuilder()->getProjection());
    }

    /**
     * Test insert() returns an InsertQuery wired through the entry points.
     *
     * @return void
     */
    public function testInsert(): void
    {
        $factory = new QueryFactory($this->connection);
        $query = $factory->insert('posts', ['title' => 'One', 'body' => 'Body']);

        $this->assertInstanceOf(InsertQuery::class, $query);
        $this->assertSame('posts', $query->compile()['collection']);
        $this->assertEquals([['title' => 'One', 'body' => 'Body']], $query->getValues());
    }

    /**
     * Test update() returns an UpdateQuery wired through the entry points.
     *
     * @return void
     */
    public function testUpdate(): void
    {
        $factory = new QueryFactory($this->connection);
        $query = $factory->update('posts', ['published' => true], ['author_id' => 1]);

        $this->assertInstanceOf(UpdateQuery::class, $query);
        $this->assertSame('posts', $query->compile()['collection']);
        $this->assertEquals(['author_id' => 1], $query->compile()['filter']);
        $this->assertEquals(['$set' => ['published' => true]], $query->compile()['update']);
    }

    /**
     * Test delete() returns a DeleteQuery wired through the entry points.
     *
     * @return void
     */
    public function testDelete(): void
    {
        $factory = new QueryFactory($this->connection);
        $query = $factory->delete('posts', ['author_id' => 1]);

        $this->assertInstanceOf(DeleteQuery::class, $query);
        $this->assertSame('posts', $query->compile()['collection']);
        $this->assertEquals(['author_id' => 1], $query->compile()['filter']);
    }

    /**
     * Test the factory methods with no arguments return usable queries.
     *
     * @return void
     */
    public function testFactoryDefaults(): void
    {
        $factory = new QueryFactory($this->connection);

        $this->assertInstanceOf(SelectQuery::class, $factory->select());
        $this->assertInstanceOf(InsertQuery::class, $factory->insert());
        $this->assertInstanceOf(UpdateQuery::class, $factory->update());
        $this->assertInstanceOf(DeleteQuery::class, $factory->delete());
    }
}
