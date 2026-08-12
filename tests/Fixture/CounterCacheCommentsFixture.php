<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\CounterCacheCommentsFixture` for the ODM test harness.
 */
class CounterCacheCommentsFixture extends TestFixture
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
    public string $collection = 'counter_cache_comments';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'title' => 'First Comment', 'user_id' => '000000000000000000000001'],
        ['_id' => '000000000000000000000002', 'title' => 'Second Comment', 'user_id' => '000000000000000000000001'],
        ['_id' => '000000000000000000000003', 'title' => 'Third Comment', 'user_id' => '000000000000000000000002'],
    ];
}
