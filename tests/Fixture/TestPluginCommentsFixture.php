<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\TestPluginCommentsFixture` for the ODM test harness.
 *
 * @ported-from \Cake\Test\Fixture\TestPluginCommentsFixture
 */
class TestPluginCommentsFixture extends TestFixture
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
    public string $collection = 'test_plugin_comments';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'article_id' => '000000000000000000000001', 'user_id' => '000000000000000000000002', 'comment' => 'First Comment for First Plugin Article', 'published' => 'Y', 'created' => '2008-09-24 10:45:23', 'updated' => '2008-09-24 10:47:31'],
        ['_id' => '000000000000000000000002', 'article_id' => '000000000000000000000001', 'user_id' => '000000000000000000000004', 'comment' => 'Second Comment for First Plugin Article', 'published' => 'Y', 'created' => '2008-09-24 10:47:23', 'updated' => '2008-09-24 10:49:31'],
        ['_id' => '000000000000000000000003', 'article_id' => '000000000000000000000001', 'user_id' => '000000000000000000000001', 'comment' => 'Third Comment for First Plugin Article', 'published' => 'Y', 'created' => '2008-09-24 10:49:23', 'updated' => '2008-09-24 10:51:31'],
        ['_id' => '000000000000000000000004', 'article_id' => '000000000000000000000001', 'user_id' => '000000000000000000000001', 'comment' => 'Fourth Comment for First Plugin Article', 'published' => 'N', 'created' => '2008-09-24 10:51:23', 'updated' => '2008-09-24 10:53:31'],
        ['_id' => '000000000000000000000005', 'article_id' => '000000000000000000000002', 'user_id' => '000000000000000000000001', 'comment' => 'First Comment for Second Plugin Article', 'published' => 'Y', 'created' => '2008-09-24 10:53:23', 'updated' => '2008-09-24 10:55:31'],
        ['_id' => '000000000000000000000006', 'article_id' => '000000000000000000000002', 'user_id' => '000000000000000000000002', 'comment' => 'Second Comment for Second Plugin Article', 'published' => 'Y', 'created' => '2008-09-24 10:55:23', 'updated' => '2008-09-24 10:57:31'],
    ];
}
