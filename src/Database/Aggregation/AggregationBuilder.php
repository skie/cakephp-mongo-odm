<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation;

use Crustum\Mongo\Database\Aggregation\Stage\AddFields;
use Crustum\Mongo\Database\Aggregation\Stage\Bucket;
use Crustum\Mongo\Database\Aggregation\Stage\BucketAuto;
use Crustum\Mongo\Database\Aggregation\Stage\CollStats;
use Crustum\Mongo\Database\Aggregation\Stage\Count;
use Crustum\Mongo\Database\Aggregation\Stage\Densify;
use Crustum\Mongo\Database\Aggregation\Stage\Facet;
use Crustum\Mongo\Database\Aggregation\Stage\Fill;
use Crustum\Mongo\Database\Aggregation\Stage\GeoNear;
use Crustum\Mongo\Database\Aggregation\Stage\GraphLookup;
use Crustum\Mongo\Database\Aggregation\Stage\Group;
use Crustum\Mongo\Database\Aggregation\Stage\IndexStats;
use Crustum\Mongo\Database\Aggregation\Stage\Limit;
use Crustum\Mongo\Database\Aggregation\Stage\Lookup;
use Crustum\Mongo\Database\Aggregation\Stage\MatchStage;
use Crustum\Mongo\Database\Aggregation\Stage\Merge;
use Crustum\Mongo\Database\Aggregation\Stage\Out;
use Crustum\Mongo\Database\Aggregation\Stage\Project;
use Crustum\Mongo\Database\Aggregation\Stage\RawStage;
use Crustum\Mongo\Database\Aggregation\Stage\Redact;
use Crustum\Mongo\Database\Aggregation\Stage\ReplaceRoot;
use Crustum\Mongo\Database\Aggregation\Stage\ReplaceWith;
use Crustum\Mongo\Database\Aggregation\Stage\Sample;
use Crustum\Mongo\Database\Aggregation\Stage\Search;
use Crustum\Mongo\Database\Aggregation\Stage\Set;
use Crustum\Mongo\Database\Aggregation\Stage\SetWindowFields;
use Crustum\Mongo\Database\Aggregation\Stage\Skip;
use Crustum\Mongo\Database\Aggregation\Stage\Sort;
use Crustum\Mongo\Database\Aggregation\Stage\SortByCount;
use Crustum\Mongo\Database\Aggregation\Stage\Stage;
use Crustum\Mongo\Database\Aggregation\Stage\UnionWith;
use Crustum\Mongo\Database\Aggregation\Stage\UnsetStage;
use Crustum\Mongo\Database\Aggregation\Stage\Unwind;
use Crustum\Mongo\Database\Aggregation\Stage\VectorSearch;
use Crustum\Mongo\Database\FunctionsBuilder;
use OutOfRangeException;

/**
 * Aggregation pipeline builder for MongoDB.
 *
 * A thin facade over {@see \Crustum\Mongo\Database\Aggregation\Pipeline}. Every
 * stage method creates a stage and appends it to the pipeline, returning the
 * stage it created so setters chain on the stage and so the builder never mixes
 * `stage|$this` return types.
 */
class AggregationBuilder
{
    /**
     * The aggregation pipeline.
     *
     * @var \Crustum\Mongo\Database\Aggregation\Pipeline
     */
    private Pipeline $pipeline;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->pipeline = new Pipeline();
    }

    /**
     * Add a $match stage.
     *
     * @param array<string, mixed> $conditions The match conditions
     * @return \Crustum\Mongo\Database\Aggregation\Stage\MatchStage
     */
    public function match(array $conditions): MatchStage
    {
        return $this->pipeline->addStage(new MatchStage($this, $conditions));
    }

    /**
     * Add a $group stage.
     *
     * @param array<string, mixed> $grouping The grouping specification
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Group
     */
    public function group(array $grouping): Group
    {
        return $this->pipeline->addStage(new Group($this, $grouping));
    }

    /**
     * Add a $sort stage.
     *
     * @param array<string, int|string> $sort The sort specification
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Sort
     */
    public function sort(array $sort): Sort
    {
        return $this->pipeline->addStage(new Sort($this, $sort));
    }

    /**
     * Add a $project stage.
     *
     * @param array<string, mixed> $fields The projection specification
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Project
     */
    public function project(array $fields): Project
    {
        return $this->pipeline->addStage(new Project($this, $fields));
    }

    /**
     * Add a $lookup stage with fluent builder.
     *
     * @param string $from The collection name to join with
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Lookup
     */
    public function lookup(string $from): Lookup
    {
        return $this->pipeline->addStage(new Lookup($this, $from));
    }

    /**
     * Add a $unwind stage.
     *
     * @param string               $path    The field path to unwind
     * @param array<string, mixed> $options Additional options (preserveNullAndEmptyArrays, includeArrayIndex)
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Unwind
     */
    public function unwind(string $path, array $options = []): Unwind
    {
        return $this->pipeline->addStage(new Unwind($this, $path, $options));
    }

    /**
     * Add a $addFields stage.
     *
     * @return \Crustum\Mongo\Database\Aggregation\Stage\AddFields
     */
    public function addFields(): AddFields
    {
        return $this->pipeline->addStage(new AddFields($this));
    }

    /**
     * Add a $set stage (alias for $addFields).
     *
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Set
     */
    public function set(): Set
    {
        return $this->pipeline->addStage(new Set($this));
    }

    /**
     * Add a $limit stage.
     *
     * @param int $limit The number of documents to limit
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Limit
     */
    public function limit(int $limit): Limit
    {
        return $this->pipeline->addStage(new Limit($this, $limit));
    }

    /**
     * Add a $skip stage.
     *
     * @param int $skip The number of documents to skip
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Skip
     */
    public function skip(int $skip): Skip
    {
        return $this->pipeline->addStage(new Skip($this, $skip));
    }

    /**
     * Add a $count stage.
     *
     * @param string $field The output field name for the count
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Count
     */
    public function count(string $field): Count
    {
        return $this->pipeline->addStage(new Count($this, $field));
    }

    /**
     * Add a $replaceRoot stage.
     *
     * @param array<string, mixed>|string $replacement The replacement document or expression
     * @return \Crustum\Mongo\Database\Aggregation\Stage\ReplaceRoot
     */
    public function replaceRoot(array|string $replacement): ReplaceRoot
    {
        return $this->pipeline->addStage(new ReplaceRoot($this, $replacement));
    }

    /**
     * Add a $replaceWith stage (alias for $replaceRoot).
     *
     * @param array<string, mixed>|string $replacement The replacement document or expression
     * @return \Crustum\Mongo\Database\Aggregation\Stage\ReplaceWith
     */
    public function replaceWith(array|string $replacement): ReplaceWith
    {
        return $this->pipeline->addStage(new ReplaceWith($this, $replacement));
    }

    /**
     * Add a $unset stage.
     *
     * @param array<string>|string $fields The field(s) to remove
     * @return \Crustum\Mongo\Database\Aggregation\Stage\UnsetStage
     */
    public function unsetFields(array|string $fields): UnsetStage
    {
        return $this->pipeline->addStage(new UnsetStage($this, $fields));
    }

    /**
     * Add a $bucket stage.
     *
     * @param array<string, mixed>|string $groupBy    The expression to group by
     * @param array<int|float>            $boundaries The boundaries array
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Bucket
     */
    public function bucket(array|string $groupBy, array $boundaries): Bucket
    {
        return $this->pipeline->addStage(new Bucket($this, $groupBy, $boundaries));
    }

    /**
     * Add a $bucketAuto stage.
     *
     * @param array<string, mixed>|string $groupBy The expression to group by
     * @param int                         $buckets The number of buckets
     * @return \Crustum\Mongo\Database\Aggregation\Stage\BucketAuto
     */
    public function bucketAuto(array|string $groupBy, int $buckets): BucketAuto
    {
        return $this->pipeline->addStage(new BucketAuto($this, $groupBy, $buckets));
    }

    /**
     * Add a $facet stage.
     *
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Facet
     */
    public function facet(): Facet
    {
        return $this->pipeline->addStage(new Facet($this));
    }

    /**
     * Add a $graphLookup stage.
     *
     * @param string                      $from             The collection to search
     * @param array<string, mixed>|string $startWith        The expression to start the search
     * @param string                      $connectFromField The field to connect from
     * @param string                      $connectToField   The field to connect to
     * @param string                      $as               The alias for the results
     * @return \Crustum\Mongo\Database\Aggregation\Stage\GraphLookup
     */
    public function graphLookup(
        string $from,
        array|string $startWith,
        string $connectFromField,
        string $connectToField,
        string $as,
    ): GraphLookup {
        return $this->pipeline->addStage(
            new GraphLookup($this, $from, $startWith, $connectFromField, $connectToField, $as),
        );
    }

    /**
     * Add a $merge stage.
     *
     * @param array<string, mixed>|string $into The target collection or database
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Merge
     */
    public function merge(string|array $into): Merge
    {
        return $this->pipeline->addStage(new Merge($this, $into));
    }

    /**
     * Add a $out stage.
     *
     * @param string $collection The target collection name
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Out
     */
    public function out(string $collection): Out
    {
        return $this->pipeline->addStage(new Out($this, $collection));
    }

    /**
     * Add a $sample stage.
     *
     * @param int $size The sample size
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Sample
     */
    public function sample(int $size): Sample
    {
        return $this->pipeline->addStage(new Sample($this, $size));
    }

    /**
     * Add a $unionWith stage.
     *
     * @param string $coll The collection to union with
     * @return \Crustum\Mongo\Database\Aggregation\Stage\UnionWith
     */
    public function unionWith(string $coll): UnionWith
    {
        return $this->pipeline->addStage(new UnionWith($this, $coll));
    }

    /**
     * Add a $redact stage.
     *
     * @param array<string, mixed>|string $expression The redact expression
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Redact
     */
    public function redact(array|string $expression): Redact
    {
        return $this->pipeline->addStage(new Redact($this, $expression));
    }

    /**
     * Add a $densify stage.
     *
     * @param string                     $field The field to densify
     * @param array<string, mixed>|null  $range The range specification (optional)
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Densify
     */
    public function densify(string $field, ?array $range = null): Densify
    {
        return $this->pipeline->addStage(new Densify($this, $field, $range));
    }

    /**
     * Add a $fill stage.
     *
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Fill
     */
    public function fill(): Fill
    {
        return $this->pipeline->addStage(new Fill($this));
    }

    /**
     * Add a $setWindowFields stage.
     *
     * @return \Crustum\Mongo\Database\Aggregation\Stage\SetWindowFields
     */
    public function setWindowFields(): SetWindowFields
    {
        return $this->pipeline->addStage(new SetWindowFields($this));
    }

    /**
     * Add a $search stage.
     *
     * @param array<string, mixed> $search The search specification
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Search
     */
    public function search(array $search): Search
    {
        return $this->pipeline->addStage(new Search($this, $search));
    }

    /**
     * Add a $vectorSearch stage.
     *
     * @param object|array<float> $queryVector   The query vector
     * @param string              $path          The field path to search over
     * @param int|null            $numCandidates The number of candidates to consider
     * @return \Crustum\Mongo\Database\Aggregation\Stage\VectorSearch
     */
    public function vectorSearch(array|object $queryVector, string $path, ?int $numCandidates = null): VectorSearch
    {
        return $this->pipeline->addStage(new VectorSearch($this, $queryVector, $path, $numCandidates));
    }

    /**
     * Add a $collStats stage.
     *
     * @return \Crustum\Mongo\Database\Aggregation\Stage\CollStats
     */
    public function collStats(): CollStats
    {
        return $this->pipeline->addStage(new CollStats($this));
    }

    /**
     * Add a $indexStats stage.
     *
     * @return \Crustum\Mongo\Database\Aggregation\Stage\IndexStats
     */
    public function indexStats(): IndexStats
    {
        return $this->pipeline->addStage(new IndexStats($this));
    }

    /**
     * Add a $geoNear stage.
     *
     * @param array<float>|array<string, array<float>> $near          The point to search near
     * @param string                                   $distanceField The distance field name
     * @return \Crustum\Mongo\Database\Aggregation\Stage\GeoNear
     */
    public function geoNear(array $near, string $distanceField): GeoNear
    {
        return $this->pipeline->addStage(new GeoNear($this, $near, $distanceField));
    }

    /**
     * Add a $sortByCount stage.
     *
     * @param array<string, mixed>|string $expression The expression to group by
     * @return \Crustum\Mongo\Database\Aggregation\Stage\SortByCount
     */
    public function sortByCount(array|string $expression): SortByCount
    {
        return $this->pipeline->addStage(new SortByCount($this, $expression));
    }

    /**
     * Add custom stage.
     *
     * @param string                        $operator The stage operator (e.g. '$limit', '$skip')
     * @param array<string, mixed>|string|int $value   The stage specification or value
     * @return \Crustum\Mongo\Database\Aggregation\Stage\RawStage
     */
    public function addStage(string $operator, array|int|string $value): RawStage
    {
        return $this->pipeline->addStage(new RawStage($this, $operator, $value));
    }

    /**
     * Get the pipeline with all stages compiled.
     *
     * @return list<array<string, mixed>>
     */
    public function getPipeline(): array
    {
        return $this->pipeline->compile();
    }

    /**
     * Returns an aggregation functions (expressions) builder.
     *
     * Mirrors `Cake\Database\Query::func()` for building operator expressions
     * that feed projections/group accumulators.
     *
     * @return \Crustum\Mongo\Database\FunctionsBuilder
     */
    public function func(): FunctionsBuilder
    {
        return new FunctionsBuilder();
    }

    /**
     * Returns a certain stage from the pipeline.
     *
     * @param int $index The zero-based stage index
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Stage The stage
     * @throws \OutOfRangeException When no stage exists at the given index
     */
    public function getStage(int $index): Stage
    {
        $stages = $this->pipeline->getStages();
        if (!isset($stages[$index])) {
            throw new OutOfRangeException(sprintf('Could not find stage with index %d.', $index));
        }

        return $stages[$index];
    }

    /**
     * Returns the underlying pipeline instance.
     *
     * Internal accessor used by sub-pipeline stages to detect self-referencing
     * pipelines. Not part of the public fluent surface.
     *
     * @return \Crustum\Mongo\Database\Aggregation\Pipeline
     */
    public function getPipelineInstance(): Pipeline
    {
        return $this->pipeline;
    }
}
