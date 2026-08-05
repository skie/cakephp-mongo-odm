<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\SpecialTagsFixture` for the ODM test harness.
 */
class SpecialTagsFixture extends TestFixture
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
    public string $table = 'special_tags';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'article_id' => 1, 'tag_id' => 3, 'highlighted' => false, 'highlighted_time' => null, 'extra_info' => 'Foo', 'author_id' => 1],
        ['_id' => '000000000000000000000002', 'article_id' => 2, 'tag_id' => 1, 'highlighted' => true, 'highlighted_time' => '2014-06-01 10:10:00', 'extra_info' => 'Bar', 'author_id' => 2],
        ['_id' => '000000000000000000000003', 'article_id' => 10, 'tag_id' => 10, 'highlighted' => true, 'highlighted_time' => '2014-06-01 10:10:00', 'extra_info' => 'Baz', 'author_id' => null],
    ];
}
