<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $geoNear aggregation stage
 *
 * Returns documents in order of proximity to a geospatial point
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Aggregation\Stage\GeoNear
 */
class GeoNear extends Stage
{
    /**
     * The point to search near
     *
     * @var array<string, mixed>
     */
    protected array $near;

    /**
     * The distance field name
     *
     * @var string
     */
    protected string $distanceField;

    /**
     * The spherical flag
     *
     * @var bool|null
     */
    protected ?bool $spherical = null;

    /**
     * The maximum distance
     *
     * @var float|null
     */
    protected ?float $maxDistance = null;

    /**
     * The query filter
     *
     * @var array<string, mixed>|null
     */
    protected ?array $query = null;

    /**
     * The distance multiplier
     *
     * @var float|null
     */
    protected ?float $distanceMultiplier = null;

    /**
     * The includeLocs flag
     *
     * @var bool|null
     */
    protected ?bool $includeLocs = null;

    /**
     * The uniqueDocs flag
     *
     * @var bool|null
     */
    protected ?bool $uniqueDocs = null;

    /**
     * The minDistance
     *
     * @var float|null
     */
    protected ?float $minDistance = null;

    /**
     * The key field
     *
     * @var string|null
     */
    protected ?string $key = null;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder        The aggregation builder
     * @param array<string, mixed>                                $near           The point to search near
     * @param string                                              $distanceField  The distance field name
     */
    public function __construct(AggregationBuilder $builder, array $near, string $distanceField)
    {
        parent::__construct($builder);
        $this->near = $near;
        $this->distanceField = $distanceField;
    }

    /**
     * Set spherical flag
     *
     * @param bool $spherical Whether to use spherical geometry
     * @return $this
     */
    public function spherical(bool $spherical = true)
    {
        $this->spherical = $spherical;

        return $this;
    }

    /**
     * Set maximum distance
     *
     * @param float $maxDistance The maximum distance
     * @return $this
     */
    public function maxDistance(float $maxDistance)
    {
        $this->maxDistance = $maxDistance;

        return $this;
    }

    /**
     * Set minimum distance
     *
     * @param float $minDistance The minimum distance
     * @return $this
     */
    public function minDistance(float $minDistance)
    {
        $this->minDistance = $minDistance;

        return $this;
    }

    /**
     * Set query filter
     *
     * @param array<string, mixed> $query The query filter
     * @return $this
     */
    public function query(array $query)
    {
        $this->query = $query;

        return $this;
    }

    /**
     * Set distance multiplier
     *
     * @param float $multiplier The distance multiplier
     * @return $this
     */
    public function distanceMultiplier(float $multiplier)
    {
        $this->distanceMultiplier = $multiplier;

        return $this;
    }

    /**
     * Set includeLocs flag
     *
     * @param bool $includeLocs Whether to include location
     * @return $this
     */
    public function includeLocs(bool $includeLocs = true)
    {
        $this->includeLocs = $includeLocs;

        return $this;
    }

    /**
     * Set uniqueDocs flag
     *
     * @param bool $uniqueDocs Whether to return unique documents
     * @return $this
     */
    public function uniqueDocs(bool $uniqueDocs = true)
    {
        $this->uniqueDocs = $uniqueDocs;

        return $this;
    }

    /**
     * Set key field
     *
     * @param string $key The key field name
     * @return $this
     */
    public function key(string $key)
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array<string, mixed> The $geoNear stage expression
     */
    public function getExpression(): array
    {
        $geoNear = [
            'near' => $this->near,
            'distanceField' => $this->distanceField,
        ];

        if ($this->spherical !== null) {
            $geoNear['spherical'] = $this->spherical;
        }

        if ($this->maxDistance !== null) {
            $geoNear['maxDistance'] = $this->maxDistance;
        }

        if ($this->minDistance !== null) {
            $geoNear['minDistance'] = $this->minDistance;
        }

        if ($this->query !== null) {
            $geoNear['query'] = $this->query;
        }

        if ($this->distanceMultiplier !== null) {
            $geoNear['distanceMultiplier'] = $this->distanceMultiplier;
        }

        if ($this->includeLocs !== null) {
            $geoNear['includeLocs'] = $this->includeLocs;
        }

        if ($this->uniqueDocs !== null) {
            $geoNear['uniqueDocs'] = $this->uniqueDocs;
        }

        if ($this->key !== null) {
            $geoNear['key'] = $this->key;
        }

        return ['$geoNear' => $geoNear];
    }
}
