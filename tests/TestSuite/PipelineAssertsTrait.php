<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestSuite;

use Crustum\Mongo\ODM\Query\SelectQuery;

/**
 * Assertion helpers for Mongo aggregation pipelines and `$lookup` stages.
 */
trait PipelineAssertsTrait
{
    /**
     * Asserts that an array contains at least the keys and values from a subset.
     *
     * Recurses into nested arrays. List indices in `$subset` must exist at the
     * same index in `$array` with matching values.
     *
     * @param array<int|string, mixed> $subset The expected subset.
     * @param array<int|string, mixed> $array The actual array.
     * @param string $path Dot path used in assertion messages.
     * @return void
     */
    protected function assertArraySubset(array $subset, array $array, string $path = 'root'): void
    {
        foreach ($subset as $key => $expected) {
            $label = $path . '.' . $key;
            $this->assertArrayHasKey($key, $array, "Missing key at {$label}");
            $actual = $array[$key];
            if (is_array($expected) && is_array($actual)) {
                $this->assertArraySubset($expected, $actual, $label);
            } else {
                $this->assertSame($expected, $actual, "Mismatch at {$label}");
            }
        }
    }

    /**
     * Asserts pipeline stages contain the expected subset at each index.
     *
     * @param list<array<string, mixed>> $expectedStages Expected stage subsets.
     * @param list<array<string, mixed>> $pipeline The actual pipeline.
     * @return void
     */
    protected function assertPipelineContains(array $expectedStages, array $pipeline): void
    {
        foreach ($expectedStages as $index => $expectedStage) {
            $this->assertArrayHasKey($index, $pipeline, "Pipeline missing stage at index {$index}");
            $this->assertArraySubset($expectedStage, $pipeline[$index], "pipeline.{$index}");
        }
    }

    /**
     * Returns `$lookup` stages from a pipeline.
     *
     * @param list<array<string, mixed>> $pipeline The pipeline stages.
     * @return list<array<string, mixed>>
     */
    protected function lookupStages(array $pipeline): array
    {
        return array_values(array_filter(
            $pipeline,
            static fn(array $stage): bool => isset($stage['$lookup']),
        ));
    }

    /**
     * Asserts the nth `$lookup` stage contains the expected subset.
     *
     * @param array<string, mixed> $expectedLookup Expected `$lookup` document subset.
     * @param list<array<string, mixed>> $pipeline The pipeline stages.
     * @param int $index Zero-based index among `$lookup` stages only.
     * @return void
     */
    protected function assertLookupStage(array $expectedLookup, array $pipeline, int $index = 0): void
    {
        $lookups = $this->lookupStages($pipeline);
        $this->assertArrayHasKey($index, $lookups, "Pipeline has no \$lookup stage at index {$index}");
        $this->assertArraySubset($expectedLookup, $lookups[$index]['$lookup'], '$lookup.' . $index);
    }

    /**
     * Asserts the nth `$lookup` stage on a select query contains the expected subset.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $query The query.
     * @param array<string, mixed> $expectedLookup Expected `$lookup` document subset.
     * @param int $index Zero-based index among `$lookup` stages only.
     * @return void
     */
    protected function assertQueryLookupStage(SelectQuery $query, array $expectedLookup, int $index = 0): void
    {
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $query->clause('pipeline') ?: [];
        $this->assertLookupStage($expectedLookup, $pipeline, $index);
    }
}
