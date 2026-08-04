<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\EagerLoader;

use Crustum\Mongo\Database\Query\SelectQuery;

final class RepositoryStub
{
    /** @param array<string, AssociationStub> $associations */
    public function __construct(private array $associations = [])
    {
    }

    public function getAssociation(string $name): ?AssociationStub
    {
        return $this->associations[$name] ?? null;
    }

    public function query(): SelectQuery
    {
        return new SelectQuery(null, 'related');
    }
}
