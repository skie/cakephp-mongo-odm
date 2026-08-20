<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Rule;

use Cake\Datasource\RepositoryInterface;
use Crustum\Mongo\ODM\Association;

/**
 * ExistsIn rule with nullable foreign keys enabled by default.
 *
 * @ported-from \Cake\ORM\Rule\ExistsInNullable
 */
class ExistsInNullable extends ExistsIn
{
    /**
     * Constructor.
     *
     * @param array<string>|string $fields The local fields to check.
     * @param \Cake\Datasource\RepositoryInterface|\Crustum\Mongo\ODM\Association|string $repository The target
     *   repository, association, or alias.
     * @param array<string, mixed> $options Rule options.
     */
    public function __construct(
        array|string $fields,
        RepositoryInterface|Association|string $repository,
        array $options = [],
    ) {
        parent::__construct($fields, $repository, $options + ['allowNullableNulls' => true]);
    }
}
