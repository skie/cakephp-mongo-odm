<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\CounterCacheUserCategoryPostsFixture` for the ODM test harness.
 *
 * @ported-from \Cake\Test\Fixture\CounterCacheUserCategoryPostsFixture
 */
class CounterCacheUserCategoryPostsFixture extends TestFixture
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
    public string $collection = 'counter_cache_user_category_posts';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'category_id' => '000000000000000000000001', 'user_id' => '000000000000000000000001', 'post_count' => 1],
        ['_id' => '000000000000000000000002', 'category_id' => '000000000000000000000002', 'user_id' => '000000000000000000000001', 'post_count' => 1],
        ['_id' => '000000000000000000000003', 'category_id' => '000000000000000000000002', 'user_id' => '000000000000000000000002', 'post_count' => 1],
    ];
}
