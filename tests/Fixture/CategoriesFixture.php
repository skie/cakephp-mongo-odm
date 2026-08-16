<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\CategoriesFixture` for the ODM test harness.
 */
class CategoriesFixture extends TestFixture
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
    public string $collection = 'categories';

    /**
     * Documents to insert.
     *
     * Root rows use `parent_id => null` (Mongo “no parent”); children use hex
     * ObjectId strings. Cake’s SQL fixture used integer `0` for roots — that
     * mixes poorly with ObjectId FKs under Mongo equality.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'parent_id' => null, 'name' => 'Category 1', 'created' => '2007-03-18 15:30:23', 'updated' => '2007-03-18 15:32:31'],
        ['_id' => '000000000000000000000002', 'parent_id' => '000000000000000000000001', 'name' => 'Category 1.1', 'created' => '2007-03-18 15:30:23', 'updated' => '2007-03-18 15:32:31'],
        ['_id' => '000000000000000000000003', 'parent_id' => '000000000000000000000001', 'name' => 'Category 1.2', 'created' => '2007-03-18 15:30:23', 'updated' => '2007-03-18 15:32:31'],
        ['_id' => '000000000000000000000004', 'parent_id' => null, 'name' => 'Category 2', 'created' => '2007-03-18 15:30:23', 'updated' => '2007-03-18 15:32:31'],
        ['_id' => '000000000000000000000005', 'parent_id' => null, 'name' => 'Category 3', 'created' => '2007-03-18 15:30:23', 'updated' => '2007-03-18 15:32:31'],
        ['_id' => '000000000000000000000006', 'parent_id' => '000000000000000000000005', 'name' => 'Category 3.1', 'created' => '2007-03-18 15:30:23', 'updated' => '2007-03-18 15:32:31'],
        ['_id' => '000000000000000000000007', 'parent_id' => '000000000000000000000002', 'name' => 'Category 1.1.1', 'created' => '2007-03-18 15:30:23', 'updated' => '2007-03-18 15:32:31'],
        ['_id' => '000000000000000000000008', 'parent_id' => '000000000000000000000002', 'name' => 'Category 1.1.2', 'created' => '2007-03-18 15:30:23', 'updated' => '2007-03-18 15:32:31'],
    ];
}
