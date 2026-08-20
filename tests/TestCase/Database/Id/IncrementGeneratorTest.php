<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Id;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Id\IncrementGenerator;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for IncrementGenerator against a real Mongo connection.
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Tests\Id\IncrementGeneratorTest
 */
#[CoversClass(IncrementGenerator::class)]
class IncrementGeneratorTest extends TestCase
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
     * Test generate increments sequentially.
     *
     * @return void
     */
    public function testGenerateIncrements(): void
    {
        $this->connection->getCollection('counters')->deleteMany(['_id' => 'articles']);
        $generator = new IncrementGenerator($this->connection->getCollection('counters'), 'articles');

        $this->assertSame(1, $generator->generate());
        $this->assertSame(2, $generator->generate());
        $this->assertSame(3, $generator->generate());
    }

    /**
     * Test custom counter field and initial value.
     *
     * @return void
     */
    public function testCustomFieldAndInitial(): void
    {
        $this->connection->getCollection('counters')->deleteMany(['_id' => 'articles']);
        $generator = new IncrementGenerator(
            $this->connection->getCollection('counters'),
            'articles',
            'seq',
            100,
        );

        $this->assertSame(101, $generator->generate());
        $this->assertSame(102, $generator->generate());
    }

    /**
     * Test counter persists across generator instances.
     *
     * @return void
     */
    public function testCounterPersists(): void
    {
        $this->connection->getCollection('counters')->deleteMany(['_id' => 'articles']);

        $first = new IncrementGenerator($this->connection->getCollection('counters'), 'articles');
        $this->assertSame(1, $first->generate());

        $second = new IncrementGenerator($this->connection->getCollection('counters'), 'articles');
        $this->assertSame(2, $second->generate());
    }
}
