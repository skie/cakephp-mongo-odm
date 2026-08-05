<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\SiteArticlesFixture` for the ODM test harness.
 */
class SiteArticlesFixture extends TestFixture
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
    public string $table = 'site_articles';

    /**
     * Documents to insert.
     *
     * The integer primary key `id` is converted into the Mongo `_id`.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'author_id' => 1, 'site_id' => 1, 'title' => 'First Article', 'body' => 'First Article Body'],
        ['_id' => '000000000000000000000002', 'author_id' => 3, 'site_id' => 2, 'title' => 'Second Article', 'body' => 'Second Article Body'],
        ['_id' => '000000000000000000000003', 'author_id' => 1, 'site_id' => 2, 'title' => 'Third Article', 'body' => 'Third Article Body'],
        ['_id' => '000000000000000000000004', 'author_id' => 3, 'site_id' => 1, 'title' => 'Fourth Article', 'body' => 'Fourth Article Body'],
    ];
}
