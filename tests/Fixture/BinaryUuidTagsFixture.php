<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\BinaryUuidTagsFixture` for the ODM test harness.
 *
 * @ported-from \Cake\Test\Fixture\BinaryUuidTagsFixture
 */
class BinaryUuidTagsFixture extends TestFixture
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
    public string $collection = 'binary_uuid_tags';

    /**
     * Documents to insert.
     *
     * The source primary key is a binary UUID; the UUID string is preserved in
     * the `id` field and a generated hex `_id` carries the Mongo identifier.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '481fc6d0-b920-43e0-a40d-111111111111', 'name' => 'Defect'],
        ['_id' => '48298a29-81c0-4c26-a7fb-222222222222', 'name' => 'Enhancement'],
    ];
}
