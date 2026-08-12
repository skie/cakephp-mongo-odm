<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\NumberTreesArticlesFixture` for the ODM test harness.
 */
class NumberTreesArticlesFixture extends TestFixture
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
    public string $collection = 'number_trees_articles';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'number_tree_id' => '000000000000000000000001', 'title' => 'First Article', 'body' => 'First Article Body', 'published' => 'Y'],
        ['_id' => '000000000000000000000002', 'number_tree_id' => '000000000000000000000001', 'title' => 'Second Article', 'body' => 'Second Article Body', 'published' => 'Y'],
        ['_id' => '000000000000000000000003', 'number_tree_id' => '000000000000000000000011', 'title' => 'Third Article', 'body' => 'Third Article Body', 'published' => 'Y'],
    ];
}
