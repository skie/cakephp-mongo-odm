<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\CounterCacheUsersFixture` for the ODM test harness.
 *
 * @ported-from \Cake\Test\Fixture\CounterCacheUsersFixture
 */
class CounterCacheUsersFixture extends TestFixture
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
    public string $collection = 'counter_cache_users';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'name' => 'Alexander', 'post_count' => 2, 'comment_count' => 2, 'posts_published' => 1],
        ['_id' => '000000000000000000000002', 'name' => 'Steven', 'post_count' => 1, 'comment_count' => 1, 'posts_published' => 1],
    ];
}
