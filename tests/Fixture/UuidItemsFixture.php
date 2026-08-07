<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\UuidItemsFixture` for the ODM test harness.
 */
class UuidItemsFixture extends TestFixture
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
    public string $table = 'uuid_items';

    /**
     * Documents to insert.
     *
     * The source primary key is a UUID; the UUID string is preserved in the
     * `id` field and a generated hex `_id` carries the Mongo identifier.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '481fc6d0-b920-43e0-a40d-6d1740cf8569', 'published' => 0, 'name' => 'Item 1'],
        ['_id' => '48298a29-81c0-4c26-a7fb-413140cf8569', 'published' => 0, 'name' => 'Item 2'],
        ['_id' => '482b7756-8da0-419a-b21f-27da40cf8569', 'published' => 0, 'name' => 'Item 3'],
        ['_id' => '482cfd4b-0e7c-4ea3-9582-4cec40cf8569', 'published' => 0, 'name' => 'Item 4'],
        ['_id' => '4831181b-4020-4983-a29b-131440cf8569', 'published' => 0, 'name' => 'Item 5'],
        ['_id' => '483798c8-c7cc-430e-8cf9-4fcc40cf8569', 'published' => 0, 'name' => 'Item 6'],
    ];
}
