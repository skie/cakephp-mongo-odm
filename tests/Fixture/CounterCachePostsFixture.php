<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\CounterCachePostsFixture` for the ODM test harness.
 */
class CounterCachePostsFixture extends TestFixture
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
    public string $table = 'counter_cache_posts';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'title' => 'Rock and Roll', 'user_id' => '000000000000000000000001', 'category_id' => '000000000000000000000001', 'published' => 0],
        ['_id' => '000000000000000000000002', 'title' => 'Music', 'user_id' => '000000000000000000000001', 'category_id' => '000000000000000000000002', 'published' => 1],
        ['_id' => '000000000000000000000003', 'title' => 'Food', 'user_id' => '000000000000000000000002', 'category_id' => '000000000000000000000002', 'published' => 1],
    ];
}
