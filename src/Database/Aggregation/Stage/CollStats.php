<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $collStats aggregation stage
 *
 * Returns statistics regarding a collection or view
 */
class CollStats extends Stage
{
    /**
     * The latency statistics specification
     *
     * @var array<string, mixed>|bool|null
     */
    protected array|bool|null $latencyStats = null;

    /**
     * The storage statistics specification
     *
     * @var array<string, mixed>|bool|null
     */
    protected array|bool|null $storageStats = null;

    /**
     * The count flag
     *
     * @var bool|null
     */
    protected ?bool $count = null;

    /**
     * The query execution statistics specification
     *
     * @var array<string, mixed>|bool|null
     */
    protected array|bool|null $queryExecStats = null;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The aggregation builder
     */
    public function __construct(AggregationBuilder $builder)
    {
        parent::__construct($builder);
    }

    /**
     * Set latency statistics
     *
     * @param array<string, mixed>|bool $latencyStats The latency stats specification
     * @return $this
     */
    public function latencyStats(array|bool $latencyStats)
    {
        $this->latencyStats = $latencyStats;

        return $this;
    }

    /**
     * Set storage statistics
     *
     * @param array<string, mixed>|bool $storageStats The storage stats specification
     * @return $this
     */
    public function storageStats(array|bool $storageStats)
    {
        $this->storageStats = $storageStats;

        return $this;
    }

    /**
     * Set count flag
     *
     * @param bool $count Whether to include count
     * @return $this
     */
    public function count(bool $count)
    {
        $this->count = $count;

        return $this;
    }

    /**
     * Set query execution statistics
     *
     * @param array<string, mixed>|bool $queryExecStats The query exec stats specification
     * @return $this
     */
    public function queryExecStats(array|bool $queryExecStats)
    {
        $this->queryExecStats = $queryExecStats;

        return $this;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array The $collStats stage expression
     */
    public function getExpression(): array
    {
        $collStats = [];

        if ($this->latencyStats !== null) {
            $collStats['latencyStats'] = $this->latencyStats;
        }

        if ($this->storageStats !== null) {
            $collStats['storageStats'] = $this->storageStats;
        }

        if ($this->count !== null) {
            $collStats['count'] = $this->count;
        }

        if ($this->queryExecStats !== null) {
            $collStats['queryExecStats'] = $this->queryExecStats;
        }

        return ['$collStats' => $collStats];
    }
}
