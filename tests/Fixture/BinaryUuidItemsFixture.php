<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\BinaryUuidItemsFixture` for the ODM test harness.
 */
class BinaryUuidItemsFixture extends TestFixture
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
    public string $table = 'binary_uuid_items';

    /**
     * Documents to insert.
     *
     * The source primary key is a binary UUID; the UUID string is preserved in
     * the `id` field and a generated hex `_id` carries the Mongo identifier.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '481fc6d0-b920-43e0-a40d-6d1740cf8569', 'published' => true, 'name' => 'Item 1'],
        ['_id' => '48298a29-81c0-4c26-a7fb-413140cf8569', 'published' => false, 'name' => 'Item 2'],
        ['_id' => '482b7756-8da0-419a-b21f-27da40cf8569', 'published' => true, 'name' => 'Item 3'],
    ];
}
