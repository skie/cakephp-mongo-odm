<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\ArticlesTagsFixture` for the ODM test harness.
 */
class ArticlesTagsFixture extends TestFixture
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
    public string $collection = 'articles_tags';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'article_id' => '000000000000000000000001', 'tag_id' => '000000000000000000000001'],
        ['_id' => '000000000000000000000002', 'article_id' => '000000000000000000000001', 'tag_id' => '000000000000000000000002'],
        ['_id' => '000000000000000000000003', 'article_id' => '000000000000000000000002', 'tag_id' => '000000000000000000000001'],
        ['_id' => '000000000000000000000004', 'article_id' => '000000000000000000000002', 'tag_id' => '000000000000000000000003'],
    ];
}
