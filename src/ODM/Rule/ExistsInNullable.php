<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Rule;

use Cake\Datasource\RepositoryInterface;

/**
 * ExistsIn rule with nullable foreign keys enabled by default.
 *
 * @see cake60/src/ORM/Rule/ExistsInNullable.php
 */
final class ExistsInNullable extends ExistsIn
{
    /**
     * Constructor.
     *
     * @param array<string>|string $fields The local fields to check.
     * @param \Cake\Datasource\RepositoryInterface|string $repository The target repository or alias.
     * @param array<string, mixed> $options Rule options.
     */
    public function __construct(array|string $fields, RepositoryInterface|string $repository, array $options = [])
    {
        parent::__construct($fields, $repository, $options + ['allowNullableNulls' => true]);
    }
}
