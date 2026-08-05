<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\EagerLoader;

use Cake\Datasource\QueryInterface;
use Crustum\Mongo\ODM\Association;
use Crustum\Mongo\ODM\Collection;
use Crustum\Mongo\ODM\Query\SelectQuery;

final class RepositoryStub extends Collection
{
    /**
     * @param array<string, \Crustum\Mongo\ODM\Association> $stubs
     */
    public function __construct(private array $stubs = [])
    {
        parent::__construct(['alias' => 'related']);
    }

    public function getAssociation(string $name): ?Association
    {
        return $this->stubs[$name] ?? null;
    }
}
