<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\ArticlesTagsBindingKeysFixture` for the ODM test harness.
 */
class ArticlesTagsBindingKeysFixture extends TestFixture
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
    public string $table = 'articles_tags_binding_keys';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'article_id' => 1, 'tagname' => 'tag1'],
        ['_id' => '000000000000000000000002', 'article_id' => 1, 'tagname' => 'tag2'],
        ['_id' => '000000000000000000000003', 'article_id' => 2, 'tagname' => 'tag1'],
        ['_id' => '000000000000000000000004', 'article_id' => 2, 'tagname' => 'tag3'],
    ];
}
