<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\SiteTagsFixture` for the ODM test harness.
 *
 * @ported-from \Cake\Test\Fixture\SiteTagsFixture
 */
class SiteTagsFixture extends TestFixture
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
    public string $collection = 'site_tags';

    /**
     * Documents to insert.
     *
     * The integer primary key `id` is converted into the Mongo `_id`.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'site_id' => '000000000000000000000001', 'name' => 'tag1'],
        ['_id' => '000000000000000000000002', 'site_id' => '000000000000000000000002', 'name' => 'tag2'],
        ['_id' => '000000000000000000000003', 'site_id' => '000000000000000000000001', 'name' => 'tag3'],
        ['_id' => '000000000000000000000004', 'site_id' => '000000000000000000000002', 'name' => 'tag4'],
    ];
}
