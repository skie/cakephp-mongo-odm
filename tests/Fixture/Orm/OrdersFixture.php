<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture\Orm;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * SQL ORM Orders fixture for the cross-boundary bridge tests.
 *
 * `author_id` holds a Mongo `_id` (hex) referenced from the Mongo `Authors`
 * collection; the bridge BelongsTo association loads the matching document.
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §5.1
 */
class OrdersFixture extends TestFixture
{
    /**
     * The connection name.
     *
     * @var string
     */
    public string $connection = 'test';

    /**
     * The table name.
     *
     * @var string
     */
    public string $table = 'orders';

    /**
     * Records to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['id' => 1, 'customer_name' => 'alice', 'author_id' => '000000000000000000000001'],
        ['id' => 2, 'customer_name' => 'bob', 'author_id' => '000000000000000000000002'],
        ['id' => 3, 'customer_name' => 'carol', 'author_id' => '000000000000000000000003'],
    ];
}
