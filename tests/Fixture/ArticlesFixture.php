<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\ArticlesFixture` for the ODM test harness.
 *
 * @ported-from \Cake\Test\Fixture\ArticlesFixture
 */
class ArticlesFixture extends TestFixture
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
    public string $collection = 'articles';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'author_id' => '000000000000000000000001', 'title' => 'First Article', 'body' => 'First Article Body', 'published' => 'Y'],
        ['_id' => '000000000000000000000002', 'author_id' => '000000000000000000000003', 'title' => 'Second Article', 'body' => 'Second Article Body', 'published' => 'Y'],
        ['_id' => '000000000000000000000003', 'author_id' => '000000000000000000000001', 'title' => 'Third Article', 'body' => 'Third Article Body', 'published' => 'Y'],
    ];
}
