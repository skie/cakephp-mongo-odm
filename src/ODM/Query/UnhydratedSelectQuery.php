<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Query;

/**
 * Select query variant that always returns unhydrated rows.
 *
 * @see cake60/src/ORM/Query/UnhydratedSelectQuery.php
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
