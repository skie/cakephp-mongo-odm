<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\NumberTreesFixture` for the ODM test harness.
 */
class NumberTreesFixture extends TestFixture
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
    public string $collection = 'number_trees';

    /**
     * Documents to insert.
     *
     * Tree marker values (`lft`, `rght`, `parent_id`) are kept verbatim as
     * source-defined strings and nulls.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'name' => 'electronics', 'parent_id' => null, 'lft' => '1', 'rght' => '20', 'depth' => 0],
        ['_id' => '000000000000000000000002', 'name' => 'televisions', 'parent_id' => '000000000000000000000001', 'lft' => '2', 'rght' => '9', 'depth' => 1],
        ['_id' => '000000000000000000000003', 'name' => 'tube', 'parent_id' => '000000000000000000000002', 'lft' => '3', 'rght' => '4', 'depth' => 2],
        ['_id' => '000000000000000000000004', 'name' => 'lcd', 'parent_id' => '000000000000000000000002', 'lft' => '5', 'rght' => '6', 'depth' => 2],
        ['_id' => '000000000000000000000005', 'name' => 'plasma', 'parent_id' => '000000000000000000000002', 'lft' => '7', 'rght' => '8', 'depth' => 2],
        ['_id' => '000000000000000000000006', 'name' => 'portable', 'parent_id' => '000000000000000000000001', 'lft' => '10', 'rght' => '19', 'depth' => 1],
        ['_id' => '000000000000000000000007', 'name' => 'mp3', 'parent_id' => '000000000000000000000006', 'lft' => '11', 'rght' => '14', 'depth' => 2],
        ['_id' => '000000000000000000000008', 'name' => 'flash', 'parent_id' => '000000000000000000000007', 'lft' => '12', 'rght' => '13', 'depth' => 3],
        ['_id' => '000000000000000000000009', 'name' => 'cd', 'parent_id' => '000000000000000000000006', 'lft' => '15', 'rght' => '16', 'depth' => 2],
        ['_id' => '000000000000000000000010', 'name' => 'radios', 'parent_id' => '000000000000000000000006', 'lft' => '17', 'rght' => '18', 'depth' => 2],
        ['_id' => '000000000000000000000011', 'name' => 'alien hardware', 'parent_id' => null, 'lft' => '21', 'rght' => '22', 'depth' => 0],
    ];
}
