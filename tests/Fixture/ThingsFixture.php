<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\ThingsFixture` for the ODM test harness.
 *
 * @ported-from \Cake\Test\Fixture\ThingsFixture
 */
class ThingsFixture extends TestFixture
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
    public string $collection = 'things';

    /**
     * Documents to insert.
     *
     * The integer primary key `id` is converted into the Mongo `_id`.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'title' => 'a title', 'body' => 'a body'],
        ['_id' => '000000000000000000000002', 'title' => 'another title', 'body' => 'another body'],
    ];
}
