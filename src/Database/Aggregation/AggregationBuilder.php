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
use Crustum\Mongo\Database\Expression\FunctionsBuilder;
use OutOfRangeException;

/**
 * Aggregation pipeline builder for MongoDB
 *
 * Provides fluent interface for building MongoDB aggregation pipelines
 */
class AggregationBuilder
{
    /**
     * The aggregation pipeline stages
     *
     * @var array<\Crustum\Mongo\Database\Aggregation\Stage\Stage|array<string, mixed>>
     */
    protected array $_pipeline = [];

    /**
     * Add a $match stage
     *
     * @param callable|array<string, mixed> $conditions The match conditions
     * @return $this
     */
    public function match(array|callable $conditions)
    {
        if (is_callable($conditions)) {
            $conditions = $conditions($this);
        }

        $this->_pipeline[] = new MatchStage($this, $conditions);

        return $this;
    }

    /**
     * Add a $group stage
     *
     * @param array<string, mixed> $grouping The grouping specification
     * @return $this
     */
    public function group(array $grouping)
    {
        $this->_pipeline[] = new Group($this, $grouping);

        return $this;
    }

    /**
     * Add a $sort stage
     *
     * @param array<string, int|string> $sort The sort specification
     * @return $this
     */
    public function sort(array $sort)
    {
        $this->_pipeline[] = new Sort($this, $sort);

        return $this;
    }

    /**
     * Add a $project stage
     *
     * @param array<string, mixed> $fields The projection specification
     * @return $this
     */
    public function project(array $fields)
    {
        $this->_pipeline[] = new Project($this, $fields);

        return $this;
    }

    /**
     * Add a $lookup stage with fluent builder
     *
     * @param string $from The collection name to join with
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Lookup
     */
    public function lookup(string $from): Lookup
    {
        $stage = new Lookup($this, $from);
        $this->_pipeline[] = $stage;

        return $stage;
    }

    /**
     * Add a $lookup stage with array (legacy support)
     *
     * @param array<string, mixed> $lookup The lookup specification
     * @return $this
     */
    public function lookupArray(array $lookup)
    {
        $this->_pipeline[] = ['$lookup' => $lookup];

        return $this;
    }

    /**
     * Add a $unwind stage
     *
     * @param string $path The field path to unwind
     * @param array<string, mixed> $options Additional options (preserveNullAndEmptyArrays, includeArrayIndex)
     * @return $this
     */
    public function unwind(string $path, array $options = [])
    {
        $this->_pipeline[] = new Unwind($this, $path, $options);

        return $this;
    }

    /**
     * Add a $addFields stage
     *
     * @return \Crustum\Mongo\Database\Aggregation\Stage\AddFields
     */
    public function addFields(): AddFields
    {
        $stage = new AddFields($this);
        $this->_pipeline[] = $stage;

        return $stage;
    }

    /**
     * Add a $set stage (alias for $addFields)
     *
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Set
     */
    public function set(): Set
    {
        $stage = new Set($this);
        $this->_pipeline[] = $stage;

        return $stage;
    }

    /**
     * Add a $limit stage
     *
     * @param int $limit The number of documents to limit
     * @return $this
     */
    public function limit(int $limit)
    {
        $stage = new Limit($this, $limit);
        $this->_pipeline[] = $stage;

        return $this;
    }

    /**
     * Add a $skip stage
     *
     * @param int $skip The number of documents to skip
     * @return $this
     */
    public function skip(int $skip)
    {
        $stage = new Skip($this, $skip);
        $this->_pipeline[] = $stage;

        return $this;
    }

    /**
     * Add a $count stage
     *
     * @param string $field The output field name for the count
     * @return $this
     */
    public function count(string $field)
    {
        $stage = new Count($this, $field);
        $this->_pipeline[] = $stage;

        return $this;
    }

    /**
     * Add a $replaceRoot stage
     *
     * @param array<string, mixed>|string $replacement The replacement document or expression
     * @return $this
     */
    public function replaceRoot(array|string $replacement)
    {
        $stage = new ReplaceRoot($this, $replacement);
        $this->_pipeline[] = $stage;

        return $this;
    }

    /**
     * Add a $replaceWith stage (alias for $replaceRoot)
     *
     * @param array<string, mixed>|string $replacement The replacement document or expression
     * @return $this
     */
    public function replaceWith(array|string $replacement)
    {
        $stage = new ReplaceWith($this, $replacement);
        $this->_pipeline[] = $stage;

        return $this;
    }

    /**
     * Add a $unset stage
     *
     * @param array<string>|string $fields The field(s) to remove
     * @return $this
     */
    public function unsetFields(array|string $fields)
    {
        $stage = new UnsetStage($this, $fields);
        $this->_pipeline[] = $stage;

        return $this;
    }

    /**
     * Add a $bucket stage
     *
     * @param array<string, mixed>|string $groupBy    The expression to group by
     * @param array<int|float> $boundaries The boundaries array
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Bucket
     */
    public function bucket(array|string $groupBy, array $boundaries): Bucket
    {
        $stage = new Bucket($this, $groupBy, $boundaries);
        $this->_pipeline[] = $stage;

        return $stage;
    }

    /**
     * Add a $bucketAuto stage
     *
     * @param array<string, mixed>|string $groupBy The expression to group by
     * @param int $buckets The number of buckets
     * @return \Crustum\Mongo\Database\Aggregation\Stage\BucketAuto
     */
    public function bucketAuto(array|string $groupBy, int $buckets): BucketAuto
    {
        $stage = new BucketAuto($this, $groupBy, $buckets);
        $this->_pipeline[] = $stage;

        return $stage;
    }

    /**
     * Add a $facet stage
     *
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Facet
     */
    public function facet(): Facet
    {
        $stage = new Facet($this);
        $this->_pipeline[] = $stage;

        return $stage;
    }

    /**
     * Add a $graphLookup stage
     *
     * @param string $from The collection to search
     * @param array<string, mixed>|string $startWith The expression to start the search
     * @param string $connectFromField The field to connect from
     * @param string $connectToField The field to connect to
     * @param string $as The alias for the results
     * @return \Crustum\Mongo\Database\Aggregation\Stage\GraphLookup
     */
    public function graphLookup(
        string $from,
        array|string $startWith,
        string $connectFromField,
        string $connectToField,
        string $as,
    ): GraphLookup {
        $stage = new GraphLookup($this, $from, $startWith, $connectFromField, $connectToField, $as);
        $this->_pipeline[] = $stage;

        return $stage;
    }

    /**
     * Add a $merge stage
     *
     * @param array<string, mixed>|string $into The target collection or database
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Merge
     */
    public function merge(string|array $into): Merge
    {
        $stage = new Merge($this, $into);
        $this->_pipeline[] = $stage;

        return $stage;
    }

    /**
     * Add a $out stage
     *
     * @param string $collection The target collection name
     * @return $this
     */
    public function out(string $collection)
    {
        $stage = new Out($this, $collection);
        $this->_pipeline[] = $stage;

        return $this;
    }

    /**
     * Add a $sample stage
     *
     * @param int $size The sample size
     * @return $this
     */
    public function sample(int $size)
    {
        $stage = new Sample($this, $size);
        $this->_pipeline[] = $stage;

        return $this;
    }

    /**
     * Add a $unionWith stage
     *
     * @param string $coll The collection to union with
     * @return \Crustum\Mongo\Database\Aggregation\Stage\UnionWith
     */
    public function unionWith(string $coll): UnionWith
    {
        $stage = new UnionWith($this, $coll);
        $this->_pipeline[] = $stage;

        return $stage;
    }

    /**
     * Add a $redact stage
     *
     * @param array<string, mixed>|string $expression The redact expression
     * @return $this
     */
    public function redact(array|string $expression)
    {
        $stage = new Redact($this, $expression);
        $this->_pipeline[] = $stage;

        return $this;
    }

    /**
     * Add a $densify stage
     *
     * @param string $field The field to densify
     * @param array<string, mixed>|null $range The range specification (optional)
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Densify
     */
    public function densify(string $field, ?array $range = null): Densify
    {
        $stage = new Densify($this, $field, $range);
        $this->_pipeline[] = $stage;

        return $stage;
    }

    /**
     * Add a $fill stage
     *
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Fill
     */
    public function fill(): Fill
    {
        $stage = new Fill($this);
        $this->_pipeline[] = $stage;

        return $stage;
    }

    /**
     * Add a $setWindowFields stage
     *
     * @return \Crustum\Mongo\Database\Aggregation\Stage\SetWindowFields
     */
    public function setWindowFields(): SetWindowFields
    {
        $stage = new SetWindowFields($this);
        $this->_pipeline[] = $stage;

        return $stage;
    }

    /**
     * Add a $search stage
     *
     * @param array<string, mixed> $search The search specification
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Search
     */
    public function search(array $search): Search
    {
        $stage = new Search($this, $search);
        $this->_pipeline[] = $stage;

        return $stage;
    }

    /**
     * Add a $vectorSearch stage
     *
     * @param object|array<float> $queryVector The query vector
     * @param string $path The field path to search over
     * @param int|null $numCandidates The number of candidates to consider
     * @return \Crustum\Mongo\Database\Aggregation\Stage\VectorSearch
     */
    public function vectorSearch(array|object $queryVector, string $path, ?int $numCandidates = null): VectorSearch
    {
        $stage = new VectorSearch($this, $queryVector, $path, $numCandidates);
        $this->_pipeline[] = $stage;

        return $stage;
    }

    /**
     * Add a $collStats stage
     *
     * @return \Crustum\Mongo\Database\Aggregation\Stage\CollStats
     */
    public function collStats(): CollStats
    {
        $stage = new CollStats($this);
        $this->_pipeline[] = $stage;

        return $stage;
    }

    /**
     * Add a $indexStats stage
     *
     * @return $this
     */
    public function indexStats()
    {
        $stage = new IndexStats($this);
        $this->_pipeline[] = $stage;

        return $this;
    }

    /**
     * Add a $geoNear stage
     *
     * @param array<float>|array<string, array<float>> $near The point to search near
     * @param string $distanceField The distance field name
     * @return \Crustum\Mongo\Database\Aggregation\Stage\GeoNear
     */
    public function geoNear(array $near, string $distanceField): GeoNear
    {
        $stage = new GeoNear($this, $near, $distanceField);
        $this->_pipeline[] = $stage;

        return $stage;
    }

    /**
     * Add a $sortByCount stage
     *
     * @param array<string, mixed>|string $expression The expression to group by
     * @return $this
     */
    public function sortByCount(array|string $expression)
    {
        $stage = new SortByCount($this, $expression);
        $this->_pipeline[] = $stage;

        return $this;
    }

    /**
     * Add custom stage
     *
     * @param string $operator The stage operator (e.g., '$limit', '$skip')
     * @param array<string, mixed>|string|int $stage The stage specification or value
     * @return $this
     */
    public function addStage(string $operator, array|int|string $stage)
    {
        if (!is_array($stage)) {
            $stage = [$operator === '$limit' ? 'limit' : 'value' => $stage];
        }

        $this->_pipeline[] = [$operator => $stage];

        return $this;
    }

    /**
     * Get the pipeline with all stages compiled
     *
     * @return array<array<string, mixed>>
     */
    public function getPipeline(): array
    {
        $result = [];
        foreach ($this->_pipeline as $stage) {
            $result[] = $stage instanceof Stage ? $stage->getExpression() : $stage;
        }

        return $result;
    }

    /**
     * Returns an aggregation functions (expressions) builder.
     *
     * Mirrors `Cake\Database\Query::func()` for building operator expressions
     * that feed projections/group accumulators.
     *
     * @return \Crustum\Mongo\Database\Expression\FunctionsBuilder
     */
    public function func(): FunctionsBuilder
    {
        return new FunctionsBuilder();
    }

    /**
     * Returns a certain stage from the pipeline.
     *
     * @param int $index The zero-based stage index
     * @return \Crustum\Mongo\Database\Aggregation\Stage\Stage|array<string, mixed> The stage
     * @throws \OutOfRangeException When no stage exists at the given index
     */
    public function getStage(int $index): Stage|array
    {
        if (!isset($this->_pipeline[$index])) {
            throw new OutOfRangeException(sprintf('Could not find stage with index %d.', $index));
        }

        return $this->_pipeline[$index];
    }
}
