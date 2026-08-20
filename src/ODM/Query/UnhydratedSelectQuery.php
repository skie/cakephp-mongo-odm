<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Query;

/**
 * Select query variant that always returns unhydrated rows.
 *
 * @ported-from \Cake\ORM\Query\UnhydratedSelectQuery
 */
class UnhydratedSelectQuery extends SelectQuery
{
    /**
     * Hydration is disabled for this query variant.
     *
     * @var bool
     */
    protected bool $hydrate = false;
}
