<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\SiteAuthorsFixture` for the ODM test harness.
 *
 * @ported-from \Cake\Test\Fixture\SiteAuthorsFixture
 */
class SiteAuthorsFixture extends TestFixture
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
    public string $collection = 'site_authors';

    /**
     * Documents to insert.
     *
     * The integer primary key `id` is converted into the Mongo `_id`.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'name' => 'mark', 'site_id' => '000000000000000000000001'],
        ['_id' => '000000000000000000000002', 'name' => 'juan', 'site_id' => '000000000000000000000002'],
        ['_id' => '000000000000000000000003', 'name' => 'jose', 'site_id' => '000000000000000000000002'],
        ['_id' => '000000000000000000000004', 'name' => 'andy', 'site_id' => '000000000000000000000001'],
    ];
}
