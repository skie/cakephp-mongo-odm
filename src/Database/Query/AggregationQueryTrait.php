<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Query;

use Closure;

/**
 * Aggregation stage sugar methods for select queries.
 *
 * Each method appends a single aggregation stage to the query pipeline and
 * returns `$this`, keeping the query self-returning. Common stages are
 * reachable this way; the `AggregationBuilder` remains the escape hatch for
 * advanced composition.
 *
 * @require-extends \Crustum\Mongo\Database\Query\SelectQuery
 */
trait AggregationQueryTrait
{
    /**
     * Adds a `$lookup` stage.
     *
     * Simple form uses `localField`/`foreignField`/`as`. When a `pipeline`
     * option is supplied, the `let` + `pipeline` shape is used; a `pipeline`
     * callable receives a sub-query and its compiled stages are embedded.
     *
     * @param string $from The collection to join with.
     * @param array{localField?: string, foreignField?: string, as?: string, let?: array<string, mixed>, pipeline?: array<int, array<string, mixed>>|\Closure} $options Lookup options.
     * @return $this
     */
    public function lookup(string $from, array $options = []): static
    {
        $pipeline = $options['pipeline'] ?? null;
        if ($pipeline !== null) {
            $options['pipeline'] = $this->resolveLookupPipeline($pipeline);
            $stage = ['from' => $from, 'let' => $options['let'] ?? null, 'pipeline' => $options['pipeline'], 'as' => $options['as'] ?? null];
            $stage = array_filter($stage, static fn(mixed $value): bool => $value !== null);
        } else {
            $stage = array_filter([
                'from' => $from,
                'localField' => $options['localField'] ?? null,
                'foreignField' => $options['foreignField'] ?? null,
                'as' => $options['as'] ?? null,
            ], static fn(mixed $value): bool => $value !== null);
        }

        return $this->appendStage(['$lookup' => $stage]);
    }

    /**
     * Adds an `$unwind` stage.
     *
     * @param string $path The array field path, prefixed with `$`.
     * @param array{preserveNullAndEmptyArrays?: bool, includeArrayIndex?: string} $options Unwind options.
     * @return $this
     */
    public function unwind(string $path, array $options = []): static
    {
        $stage = ['path' => $path] + $options;

        return $this->appendStage(['$unwind' => $stage]);
    }

    /**
     * Adds an `$addFields` stage.
     *
     * @param array<string, mixed> $fields Computed fields to add.
     * @return $this
     */
    public function addFields(array $fields): static
    {
        return $this->appendStage(['$addFields' => $fields]);
    }

    /**
     * Adds a `$set` stage.
     *
     * @param array<string, mixed> $fields Fields to set.
     * @return $this
     */
    public function setFields(array $fields): static
    {
        return $this->appendStage(['$set' => $fields]);
    }

    /**
     * Adds a `$sample` stage.
     *
     * @param int $size The number of random documents.
     * @return $this
     */
    public function sample(int $size): static
    {
        return $this->appendStage(['$sample' => ['size' => $size]]);
    }

    /**
     * Adds a `$facet` stage.
     *
     * @param array<string, array<int, array<string, mixed>>> $facets Named sub-pipelines.
     * @return $this
     */
    public function facet(array $facets): static
    {
        return $this->appendStage(['$facet' => $facets]);
    }

    /**
     * Adds a `$sortByCount` stage.
     *
     * @param string $field The field to group by.
     * @return $this
     */
    public function sortByCount(string $field): static
    {
        return $this->appendStage(['$sortByCount' => '$' . ltrim($field, '$')]);
    }

    /**
     * Adds a `$replaceRoot` stage.
     *
     * @param array<string, mixed>|string $newRoot The new root document or `$path`.
     * @return $this
     */
    public function replaceRoot(array|string $newRoot): static
    {
        return $this->appendStage(['$replaceRoot' => ['newRoot' => $newRoot]]);
    }

    /**
     * Adds a `$replaceWith` stage.
     *
     * @param array<string, mixed>|string $replacement The replacement document or `$path`.
     * @return $this
     */
    public function replaceWith(array|string $replacement): static
    {
        return $this->appendStage(['$replaceWith' => ['replacement' => $replacement]]);
    }

    /**
     * Adds a `$search` stage.
     *
     * @param array<string, mixed> $options The search specification.
     * @return $this
     */
    public function search(array $options): static
    {
        return $this->appendStage(['$search' => $options]);
    }

    /**
     * Adds a `$vectorSearch` stage.
     *
     * @param array<string, mixed> $options The vector search specification.
     * @return $this
     */
    public function vectorSearch(array $options): static
    {
        return $this->appendStage(['$vectorSearch' => $options]);
    }

    /**
     * Adds a `$graphLookup` stage.
     *
     * @param string $from The collection to traverse.
     * @param array<string, mixed> $options Graph lookup options.
     * @return $this
     */
    public function graphLookup(string $from, array $options = []): static
    {
        return $this->appendStage(['$graphLookup' => ['from' => $from] + $options]);
    }

    /**
     * Adds a `$unionWith` stage.
     *
     * @param string $collection The collection to union with.
     * @param array<string, mixed> $options Union options.
     * @return $this
     */
    public function unionWith(string $collection, array $options = []): static
    {
        return $this->appendStage(['$unionWith' => ['coll' => $collection] + $options]);
    }

    /**
     * Adds a `$unset` stage.
     *
     * @param array<string>|string $fields Fields to remove.
     * @return $this
     */
    public function unsetStage(array|string $fields): static
    {
        return $this->appendStage(['$unset' => is_string($fields) ? [$fields] : $fields]);
    }

    /**
     * Appends a compiled stage to the query pipeline.
     *
     * @param array<string, mixed> $stage A single aggregation stage.
     * @return $this
     */
    protected function appendStage(array $stage): static
    {
        return $this->pipeline([$stage]);
    }

    /**
     * Resolves a `$lookup` sub-pipeline callable into compiled stages.
     *
     * @param \Closure|array<int, array<string, mixed>> $pipeline Raw stages or a builder callable.
     * @return array<int, array<string, mixed>>
     */
    private function resolveLookupPipeline(array|Closure $pipeline): array
    {
        if (!$pipeline instanceof Closure) {
            return $pipeline;
        }

        $query = new SelectQuery($this->getConnection(), $this->getCollection());
        $result = $pipeline($query);
        $query = $result instanceof SelectQuery ? $result : $query;
        $compiled = $query->compile();
        if (($compiled['type'] ?? '') === 'aggregate') {
            return $compiled['pipeline'] ?? [];
        }

        $filter = $compiled['filter'] ?? [];

        return $filter === [] ? [] : [['$match' => $filter]];
    }
}
