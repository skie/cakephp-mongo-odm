<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\ODM\Marshaller;

final class MarshallerCollection
{
    /**
     * @var array<int, string>
     */
    public array $events = [];

    public object $validator;

    public object $association;

    public function marshaller(): Marshaller
    {
        return new Marshaller($this);
    }

    public function getEntityClass(): string
    {
        return Document::class;
    }

    public function getValidator(string $name): object
    {
        return $this->validator ?? new class {
            /** @return array<string, mixed> */
            public function validate(array $data, bool $isNew, array $context = []): array
            {
                return [];
            }
        };
    }

    public function getAssociation(string $name): ?object
    {
        return $this->association ?? null;
    }

    /** @param array<string, mixed> $payload */
    public function dispatchEvent(string $name, array $payload): void
    {
        $this->events[] = $name;
    }
}
