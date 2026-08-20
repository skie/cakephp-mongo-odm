<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\SiteArticlesFixture` for the ODM test harness.
 *
 * @ported-from \Cake\Test\Fixture\SiteArticlesFixture
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
    public string $collection = 'site_articles';

    /**
     * Documents to insert.
     *
     * The integer primary key `id` is converted into the Mongo `_id`.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'author_id' => '000000000000000000000001', 'site_id' => '000000000000000000000001', 'title' => 'First Article', 'body' => 'First Article Body'],
        ['_id' => '000000000000000000000002', 'author_id' => '000000000000000000000003', 'site_id' => '000000000000000000000002', 'title' => 'Second Article', 'body' => 'Second Article Body'],
        ['_id' => '000000000000000000000003', 'author_id' => '000000000000000000000001', 'site_id' => '000000000000000000000002', 'title' => 'Third Article', 'body' => 'Third Article Body'],
        ['_id' => '000000000000000000000004', 'author_id' => '000000000000000000000003', 'site_id' => '000000000000000000000001', 'title' => 'Fourth Article', 'body' => 'Fourth Article Body'],
    ];
}
