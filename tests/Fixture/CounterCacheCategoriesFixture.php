<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\CounterCacheCategoriesFixture` for the ODM test harness.
 */
class CounterCacheCategoriesFixture extends TestFixture
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
    public string $collection = 'counter_cache_categories';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'name' => 'Sport', 'post_count' => 1],
        ['_id' => '000000000000000000000002', 'name' => 'Music', 'post_count' => 2],
    ];
}
