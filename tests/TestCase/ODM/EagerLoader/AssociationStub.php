<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\EagerLoader;

final class AssociationStub
{
    /** @param array<int, array<string, mixed>> $pipeline */
    public function __construct(
        private RepositoryStub $target,
        private string $type = 'reference',
        private array $pipeline = [],
    ) {
    }

    public function getTarget(): RepositoryStub
    {
        return $this->target;
    }

    public function type(): string
    {
        return $this->type;
    }

    /** @param array<string, mixed> $options @return array<int, array<string, mixed>> */
    public function buildPipeline(array $options): array
    {
        return $this->pipeline;
    }
}
