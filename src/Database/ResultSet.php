<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database;

use Cake\Collection\Collection;
use Cake\Datasource\ResultSetInterface;

/**
 * Generic database result set for raw MongoDB rows.
 *
 * @template TKey
 * @template TValue
 * @implements \Cake\Datasource\ResultSetInterface<TKey, TValue>
 * @extends \Cake\Collection\Collection<TKey, TValue>
 */
class ResultSet extends Collection implements ResultSetInterface
{
}
