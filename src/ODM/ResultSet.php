<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use Cake\Collection\Collection;
use Cake\Datasource\ResultSetInterface;

/**
 * Represents the results obtained after executing an ODM query.
 *
 * The collection keeps the iterable result API supplied by CakePHP while the
 * Mongo result factory is responsible for hydration and BSON-aware nesting.
 *
 * @see cake60/src/ORM/ResultSet.php
 * @template TKey
 * @template TValue
 * @implements \Cake\Datasource\ResultSetInterface<TKey, TValue>
 * @extends \Cake\Collection\Collection<TKey, TValue>
 */
class ResultSet extends Collection implements ResultSetInterface
{
}
