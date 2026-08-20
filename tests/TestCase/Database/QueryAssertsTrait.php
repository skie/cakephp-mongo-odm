<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database;

use MongoDB\BSON\ObjectId;

/**
 * Assertion helpers for comparing compiled Mongo query shapes and documents.
 *
 * Adapted from cake50/tests/TestCase/Database/QueryAssertsTrait.php. SQL string
 * assertions are replaced with structural assertions against the compiled
 * Mongo query array (`['type' => ..., 'filter' => ..., 'options' => ...]`).
 *
 * @rewritten-from \Cake\Test\TestCase\Database\QueryAssertsTrait
 */
trait QueryAssertsTrait
{
    /**
     * Assert a compiled query's type.
     *
     * @param string $expected The expected query type.
     * @param array<string, mixed> $compiled The compiled query.
     */
    public function assertQueryType(string $expected, array $compiled): void
    {
        $this->assertSame($expected, $compiled['type'] ?? null);
    }

    /**
     * Assert a compiled query's filter.
     *
     * @param array $expected The expected filter.
     * @param array<string, mixed> $compiled The compiled query.
     */
    public function assertFilter(array $expected, array $compiled): void
    {
        $this->assertEquals($expected, $compiled['filter'] ?? []);
    }

    /**
     * Assert a compiled query's options.
     *
     * @param array $expected The expected options subset.
     * @param array<string, mixed> $compiled The compiled query.
     */
    public function assertOptions(array $expected, array $compiled): void
    {
        $actual = $compiled['options'] ?? [];
        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $actual);
            $this->assertSame($value, $actual[$key]);
        }
    }

    /**
     * Assert a compiled query's aggregation pipeline.
     *
     * @param array $expected The expected pipeline stages.
     * @param array<string, mixed> $compiled The compiled query.
     */
    public function assertPipeline(array $expected, array $compiled): void
    {
        $this->assertEquals($expected, $compiled['pipeline'] ?? []);
    }

    /**
     * Assert a collection's contents match the given documents.
     *
     * @param string $collection The collection name.
     * @param int $count The expected number of documents.
     * @param array $rows The expected documents.
     * @param array $conditions The filter to select with.
     */
    public function assertDocuments(string $collection, int $count, array $rows, array $conditions = []): void
    {
        $collection = $this->connection->getCollection($collection);
        $results = iterator_to_array($collection->find($conditions), false);
        $this->assertCount($count, $results, 'Row count is incorrect');

        $documents = array_map(
            static fn(array $document): array => array_filter($document, static fn(mixed $value): bool => !$value instanceof ObjectId),
            $results,
        );
        $this->assertEquals($rows, array_values($documents));
    }
}
