<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Fixture for a collection whose primary key is an application-level UUID
 * field `id` (not the Mongo `_id`).
 */
class UuidPrimaryItemsFixture extends TestFixture
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
    public string $collection = 'uuid_primary_items';

    /**
     * Documents to insert.
     *
     * Both the Mongo `_id` and the application `id` (UUID primary key) are
     * stored explicitly so the fixture does not fold `id` into `_id`.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'id' => '481fc6d0-b920-43e0-a40d-6d1740cf8569', 'name' => 'Item 1'],
        ['_id' => '000000000000000000000002', 'id' => '48298a29-81c0-4c26-a7fb-413140cf8569', 'name' => 'Item 2'],
        ['_id' => '000000000000000000000003', 'id' => '482b7756-8da0-419a-b21f-27da40cf8569', 'name' => 'Item 3'],
    ];
}
