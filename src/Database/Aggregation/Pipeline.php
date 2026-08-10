<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation;

use Crustum\Mongo\Database\Aggregation\Stage\Stage;

/**
 * Ordered list of aggregation pipeline stages.
 *
 * Owns the stage list and compiles it to the Mongo wire format in a single
 * canonical loop. Each stage owns its own configuration and compiles itself
 * via `Stage::getExpression()`.
 *
 * Mirrors Doctrine's `Aggregation\Builder::$stages` list, extracted into its
 * own class so list ordering, per-stage config, and compilation stay separate.
 */
class Pipeline
{
    /**
     * The ordered stages.
     *
     * @var list<\Crustum\Mongo\Database\Aggregation\Stage\Stage>
     */
    private array $stages = [];

    /**
     * Append a stage and return it for chaining.
     *
     * @template T of \Crustum\Mongo\Database\Aggregation\Stage\Stage
     * @param T $stage The stage to append.
     * @return T The appended stage.
     */
    public function addStage(Stage $stage): Stage
    {
        $this->stages[] = $stage;

        return $stage;
    }

    /**
     * Returns the ordered stages.
     *
     * @return list<\Crustum\Mongo\Database\Aggregation\Stage\Stage>
     */
    public function getStages(): array
    {
        return $this->stages;
    }

    /**
     * Compile every stage to the Mongo aggregation wire format.
     *
     * @return list<array<string, mixed>>
     */
    public function compile(): array
    {
        $result = [];
        foreach ($this->stages as $stage) {
            $result[] = $stage->getExpression();
        }

        return $result;
    }
}
