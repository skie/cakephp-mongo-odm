<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\ODM\ResultSet;
use Crustum\Mongo\ODM\ResultSetFactory;

final class ResultSetTest extends TestCase
{
    public function testFactoryHydratesNestedDocuments(): void
    {
        $resultSet = (new ResultSetFactory())->createResultSet([
            ['_id' => 'one', 'profile' => ['name' => 'Ada'], 'tags' => [['name' => 'php']]],
        ], ['source' => 'users']);

        $document = $resultSet->first();
        $this->assertInstanceOf(Document::class, $document);
        $this->assertInstanceOf(Document::class, $document->profile);
        $this->assertInstanceOf(Document::class, $document->tags[0]);
        $this->assertFalse($document->isDirty());
        $this->assertSame('users', $document->collection());
    }

    public function testFactorySupportsUnhydratedAndCollectionOperations(): void
    {
        $resultSet = (new ResultSetFactory())->createResultSet([
            ['name' => 'one'],
            ['name' => 'two'],
        ], ['hydrate' => false]);

        $this->assertSame(['name' => 'one'], $resultSet->first());
        $this->assertCount(2, $resultSet);
        $this->assertSame([['name' => 'one'], ['name' => 'two']], $resultSet->toArray());
        $this->assertSame(['ONE', 'TWO'], $resultSet->map(fn(array $row): string => strtoupper($row['name']))->toArray());
    }

    public function testResultSetSerializes(): void
    {
        $resultSet = new ResultSet([new Document(['name' => 'one'])]);
        $restored = unserialize(serialize($resultSet));

        $this->assertInstanceOf(ResultSet::class, $restored);
        $this->assertInstanceOf(Document::class, $restored->first());
    }
}
