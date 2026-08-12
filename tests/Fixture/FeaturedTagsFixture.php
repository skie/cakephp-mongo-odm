<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\FeaturedTagsFixture` for the ODM test harness.
 */
class FeaturedTagsFixture extends TestFixture
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
    public string $collection = 'featured_tags';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'tag_id' => '000000000000000000000001', 'priority' => 1],
        ['_id' => '000000000000000000000002', 'tag_id' => '000000000000000000000002', 'priority' => 2],
        ['_id' => '000000000000000000000003', 'tag_id' => '000000000000000000000003', 'priority' => 3],
    ];
}
