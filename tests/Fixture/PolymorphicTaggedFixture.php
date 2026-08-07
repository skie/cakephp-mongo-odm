<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\PolymorphicTaggedFixture` for the ODM test harness.
 */
class PolymorphicTaggedFixture extends TestFixture
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
    public string $table = 'polymorphic_tagged';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'tag_id' => '000000000000000000000001', 'foreign_key' => 1, 'foreign_model' => 'Posts', 'position' => 1],
        ['_id' => '000000000000000000000002', 'tag_id' => '000000000000000000000001', 'foreign_key' => 1, 'foreign_model' => 'Articles', 'position' => 1],
    ];
}
