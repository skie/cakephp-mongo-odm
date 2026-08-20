<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Closure;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Pipeline;

/**
 * Base class for aggregation pipeline stages.
 *
 * Owns the per-stage configuration and compiles itself via `getExpression()`.
 * Re-exports every `AggregationBuilder` factory so the fluent chain continues
 * off any stage (mirrors Doctrine's `Stage` class).
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Aggregation\Stage
 */
abstract class Stage
{
    /**
     * The aggregation builder this stage belongs to
     *
     * @var \Crustum\Mongo\Database\Aggregation\AggregationBuilder
     */
    protected AggregationBuilder $builder;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The aggregation builder
     */
    public function __construct(AggregationBuilder $builder)
    {
        $this->builder = $builder;
    }

    /**
     * Get the aggregation builder
     *
     * @return \Crustum\Mongo\Database\Aggregation\AggregationBuilder
     */
    public function getBuilder(): AggregationBuilder
    {
        return $this->builder;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array<string, mixed> The stage expression as an array
     */
    abstract public function getExpression(): array;

    /**
     * Normalize a sub-pipeline into a `Pipeline` instance.
     *
     * Accepts an existing `Pipeline`, a raw stage array list (each element is a
     * wire-format stage, e.g. `['$match' => [...]]`), or a closure that receives
     * an `AggregationBuilder` and builds the sub-pipeline in place.
     *
     * @param \Crustum\Mongo\Database\Aggregation\Pipeline|\Closure|array<array<string, mixed>> $pipeline The sub-pipeline.
     * @return \Crustum\Mongo\Database\Aggregation\Pipeline
     */
    protected function buildSubPipeline(Pipeline|array|Closure $pipeline): Pipeline
    {
        if ($pipeline instanceof Pipeline) {
            return $pipeline;
        }

        if ($pipeline instanceof Closure) {
            $builder = new AggregationBuilder();
            $pipeline($builder);

            return $builder->getPipelineInstance();
        }

        $result = new Pipeline();
        foreach ($pipeline as $stage) {
            $result->addStage(RawStage::fromWire($this->builder, $stage));
        }

        return $result;
    }

    /**
     * Add a $match stage
     *
     * @param array<string, mixed> $conditions The match conditions
     * @return \Crustum\Mongo\Database\Aggregation\Stage\MatchStage
     */
    public function match(array $conditions): MatchStage
    {
        return $this->builder->match($conditions);
    }

    /**
     * Add a $group stage
     *
     * @param array<string, mixed> $grouping The grouping specification
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Group
     */
    public function group(array $grouping): Group
    {
        return $this->builder->group($grouping);
    }

    /**
     * Add a $sort stage
     *
     * @param array<string, int|string> $sort The sort specification
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Sort
     */
    public function sort(array $sort): Sort
    {
        return $this->builder->sort($sort);
    }

    /**
     * Add a $project stage
     *
     * @param array<string, mixed> $fields The projection specification
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Project
     */
    public function project(array $fields): Project
    {
        return $this->builder->project($fields);
    }

    /**
     * Add a $lookup stage
     *
     * @param string $from The collection name to join with
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Lookup
     */
    public function lookup(string $from): Lookup
    {
        return $this->builder->lookup($from);
    }

    /**
     * Add a $unwind stage
     *
     * @param string               $path    The field path to unwind
     * @param array<string, mixed> $options Additional options
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Unwind
     */
    public function unwind(string $path, array $options = []): Unwind
    {
        return $this->builder->unwind($path, $options);
    }

    /**
     * Add a $addFields stage
     *
     * @return \Crustum\Mongo\Database\Aggregation\Stage\AddFields
     */
    public function addFields(): AddFields
    {
        return $this->builder->addFields();
    }

    /**
     * Add a $set stage
     *
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Set
     */
    public function set(): Set
    {
        return $this->builder->set();
    }

    /**
     * Add a $limit stage
     *
     * @param int $limit The number of documents to limit
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Limit
     */
    public function limit(int $limit): Limit
    {
        return $this->builder->limit($limit);
    }

    /**
     * Add a $skip stage
     *
     * @param int $skip The number of documents to skip
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Skip
     */
    public function skip(int $skip): Skip
    {
        return $this->builder->skip($skip);
    }

    /**
     * Add a $count stage
     *
     * @param string $field The output field name for the count
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Count
     */
    public function count(string $field): Count
    {
        return $this->builder->count($field);
    }

    /**
     * Add a $replaceRoot stage
     *
     * @param array<string, mixed>|string $replacement The replacement document or expression
     * @return \Crustum\Mongo\Database\Aggregation\Stage\ReplaceRoot
     */
    public function replaceRoot(array|string $replacement): ReplaceRoot
    {
        return $this->builder->replaceRoot($replacement);
    }

    /**
     * Add a $replaceWith stage
     *
     * @param array<string, mixed>|string $replacement The replacement document or expression
     * @return \Crustum\Mongo\Database\Aggregation\Stage\ReplaceWith
     */
    public function replaceWith(array|string $replacement): ReplaceWith
    {
        return $this->builder->replaceWith($replacement);
    }

    /**
     * Add a $unset stage
     *
     * @param array<string>|string $fields The field(s) to remove
     * @return \Crustum\Mongo\Database\Aggregation\Stage\UnsetStage
     */
    public function unsetFields(array|string $fields): UnsetStage
    {
        return $this->builder->unsetFields($fields);
    }

    /**
     * Add a $bucket stage
     *
     * @param array<string, mixed>|string $groupBy    The expression to group by
     * @param array<int|float>            $boundaries The boundaries array
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Bucket
     */
    public function bucket(array|string $groupBy, array $boundaries): Bucket
    {
        return $this->builder->bucket($groupBy, $boundaries);
    }

    /**
     * Add a $bucketAuto stage
     *
     * @param array<string, mixed>|string $groupBy The expression to group by
     * @param int                         $buckets The number of buckets
     * @return \Crustum\Mongo\Database\Aggregation\Stage\BucketAuto
     */
    public function bucketAuto(array|string $groupBy, int $buckets): BucketAuto
    {
        return $this->builder->bucketAuto($groupBy, $buckets);
    }

    /**
     * Add a $facet stage
     *
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Facet
     */
    public function facet(): Facet
    {
        return $this->builder->facet();
    }

    /**
     * Add a $graphLookup stage
     *
     * @param string                        $from              The collection to search
     * @param array<string, mixed>|string   $startWith         The expression to start the search
     * @param string                        $connectFromField  The field to connect from
     * @param string                        $connectToField    The field to connect to
     * @param string                        $as                The alias for the results
     * @return \Crustum\Mongo\Database\Aggregation\Stage\GraphLookup
     */
    public function graphLookup(
        string $from,
        array|string $startWith,
        string $connectFromField,
        string $connectToField,
        string $as,
    ): GraphLookup {
        return $this->builder->graphLookup($from, $startWith, $connectFromField, $connectToField, $as);
    }

    /**
     * Add a $merge stage
     *
     * @param array<string, mixed>|string $into The target collection or database
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Merge
     */
    public function merge(string|array $into): Merge
    {
        return $this->builder->merge($into);
    }

    /**
     * Add a $out stage
     *
     * @param string $collection The target collection name
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Out
     */
    public function out(string $collection): Out
    {
        return $this->builder->out($collection);
    }

    /**
     * Add a $sample stage
     *
     * @param int $size The sample size
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Sample
     */
    public function sample(int $size): Sample
    {
        return $this->builder->sample($size);
    }

    /**
     * Add a $unionWith stage
     *
     * @param string $coll The collection to union with
     * @return \Crustum\Mongo\Database\Aggregation\Stage\UnionWith
     */
    public function unionWith(string $coll): UnionWith
    {
        return $this->builder->unionWith($coll);
    }

    /**
     * Add a $redact stage
     *
     * @param array<string, mixed>|string $expression The redact expression
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Redact
     */
    public function redact(array|string $expression): Redact
    {
        return $this->builder->redact($expression);
    }

    /**
     * Add a $densify stage
     *
     * @param string                            $field The field to densify
     * @param array<string, mixed>|null         $range The range specification (optional)
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Densify
     */
    public function densify(string $field, ?array $range = null): Densify
    {
        return $this->builder->densify($field, $range);
    }

    /**
     * Add a $fill stage
     *
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Fill
     */
    public function fill(): Fill
    {
        return $this->builder->fill();
    }

    /**
     * Add a $setWindowFields stage
     *
     * @return \Crustum\Mongo\Database\Aggregation\Stage\SetWindowFields
     */
    public function setWindowFields(): SetWindowFields
    {
        return $this->builder->setWindowFields();
    }

    /**
     * Add a $search stage
     *
     * @param array<string, mixed> $search The search specification
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Search
     */
    public function search(array $search): Search
    {
        return $this->builder->search($search);
    }

    /**
     * Add a $vectorSearch stage
     *
     * @param object|array<float> $queryVector  The query vector
     * @param string              $path         The field path to search over
     * @param int|null            $numCandidates The number of candidates to consider
     * @return \Crustum\Mongo\Database\Aggregation\Stage\VectorSearch
     */
    public function vectorSearch(array|object $queryVector, string $path, ?int $numCandidates = null): VectorSearch
    {
        return $this->builder->vectorSearch($queryVector, $path, $numCandidates);
    }

    /**
     * Add a $collStats stage
     *
     * @return \Crustum\Mongo\Database\Aggregation\Stage\CollStats
     */
    public function collStats(): CollStats
    {
        return $this->builder->collStats();
    }

    /**
     * Add a $indexStats stage
     *
     * @return \Crustum\Mongo\Database\Aggregation\Stage\IndexStats
     */
    public function indexStats(): IndexStats
    {
        return $this->builder->indexStats();
    }

    /**
     * Add a $geoNear stage
     *
     * @param array<float>|array<string, array<float>> $near          The point to search near
     * @param string                                   $distanceField The distance field name
     * @return \Crustum\Mongo\Database\Aggregation\Stage\GeoNear
     */
    public function geoNear(array $near, string $distanceField): GeoNear
    {
        return $this->builder->geoNear($near, $distanceField);
    }

    /**
     * Add a $sortByCount stage
     *
     * @param array<string, mixed>|string $expression The expression to group by
     * @return \Crustum\Mongo\Database\Aggregation\Stage\SortByCount
     */
    public function sortByCount(array|string $expression): SortByCount
    {
        return $this->builder->sortByCount($expression);
    }

    /**
     * Add a custom stage
     *
     * @param string                        $operator The stage operator (e.g. '$limit', '$skip')
     * @param array<string, mixed>|string|int $value   The stage specification or value
     * @return \Crustum\Mongo\Database\Aggregation\Stage\RawStage
     */
    public function addStage(string $operator, array|int|string $value): RawStage
    {
        return $this->builder->addStage($operator, $value);
    }
}
