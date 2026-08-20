<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\PostsFixture` for the ODM test harness.
 *
 * @ported-from \Cake\Test\Fixture\PostsFixture
 */
class PostsFixture extends TestFixture
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
    public string $collection = 'posts';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'author_id' => '000000000000000000000001', 'title' => 'First Post', 'body' => 'First Post Body', 'published' => 'Y'],
        ['_id' => '000000000000000000000002', 'author_id' => '000000000000000000000003', 'title' => 'Second Post', 'body' => 'Second Post Body', 'published' => 'Y'],
        ['_id' => '000000000000000000000003', 'author_id' => '000000000000000000000001', 'title' => 'Third Post', 'body' => 'Third Post Body', 'published' => 'Y'],
    ];
}
