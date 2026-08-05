<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\EagerLoader;

use BadMethodCallException;
use Closure;
use Crustum\Mongo\ODM\Association;
use Crustum\Mongo\ODM\Collection;

final class AssociationStub extends Association
{
    private string $strategyName;

    /**
     * @param array<int, array<string, mixed>> $pipeline
     */
    public function __construct(
        Collection $target,
        string $strategy = 'select',
        private array $pipeline = [],
    ) {
        parent::__construct('Related', $target, ['strategy' => $strategy]);
        $this->setTarget($target);
        $this->strategyName = $strategy;
    }

    public function type(): string
    {
        return 'reference';
    }

    public function getStrategy(): string
    {
        return $this->strategyName;
    }

    public function eagerLoader(array $options): Closure
    {
        throw new BadMethodCallException('Not used in eager loader tests.');
    }

    public function buildPipeline(array $options = []): array
    {
        return $this->pipeline;
    }
}
