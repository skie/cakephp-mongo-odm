<?php
declare(strict_types=1);

namespace TestApp\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Query\SelectQuery;

class TestCollection extends BaseCollection
{
    public mixed $first;

    public array $variadic;

    public array $variadicOptions;

    public function initialize(array $config): void
    {
        $this->setSchemaFromArray(['id' => ['type' => 'integer']]);
    }

    public function findPublishedWithArgOnly(SelectQuery $query, string $what = 'worked', mixed $other = null): SelectQuery
    {
        return $query->applyOptions(['this' => $what]);
    }

    public function findWithOptions(SelectQuery $query, array $options): SelectQuery
    {
        return $query->applyOptions(['this' => 'worked']);
    }

    public function findVariadicOptions(SelectQuery $query, ...$options): SelectQuery
    {
        $this->variadicOptions = $options;

        return $query;
    }

    public function findVariadic(SelectQuery $query, mixed $first = null, mixed ...$variadic): SelectQuery
    {
        $this->first = $first;
        $this->variadic = $variadic;

        return $query;
    }
}
