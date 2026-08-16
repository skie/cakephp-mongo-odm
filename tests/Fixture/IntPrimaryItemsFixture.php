<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Fixture for a collection whose primary key is an application-level integer
 * field `id` (not the Mongo `_id`).
 */
class IntPrimaryItemsFixture extends TestFixture
{
    /**
     * The connection name.
     *
     * @var string
     */
    public string $connection = 'test_mongo';

    /**
     * The collection name.
     *
     * @var string
     */
    public string $collection = 'int_primary_items';

    /**
     * Documents to insert.
     *
     * Both the Mongo `_id` and the application `id` (integer primary key) are
     * stored explicitly so the fixture does not fold `id` into `_id`.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'id' => 1, 'name' => 'Item 1'],
        ['_id' => '000000000000000000000002', 'id' => 2, 'name' => 'Item 2'],
        ['_id' => '000000000000000000000003', 'id' => 3, 'name' => 'Item 3'],
    ];
}
