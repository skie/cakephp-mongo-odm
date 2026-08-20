<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\TagsFixture` for the ODM test harness.
 *
 * @ported-from \Cake\Test\Fixture\TagsFixture
 */
class TagsFixture extends TestFixture
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
    public string $collection = 'tags';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'name' => 'tag1', 'description' => 'A big description', 'created' => '2016-01-01 00:00'],
        ['_id' => '000000000000000000000002', 'name' => 'tag2', 'description' => 'Another big description', 'created' => '2016-01-01 00:00'],
        ['_id' => '000000000000000000000003', 'name' => 'tag3', 'description' => 'Yet another one', 'created' => '2016-01-01 00:00'],
    ];
}
