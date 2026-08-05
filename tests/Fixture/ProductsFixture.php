<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\ProductsFixture` for the ODM test harness.
 */
class ProductsFixture extends TestFixture
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
    public string $table = 'products';

    /**
     * Documents to insert.
     *
     * The integer primary key `id` is converted into the Mongo `_id`.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'category' => 1, 'name' => 'First product', 'price' => 10],
        ['_id' => '000000000000000000000002', 'category' => 2, 'name' => 'Second product', 'price' => 20],
        ['_id' => '000000000000000000000003', 'category' => 3, 'name' => 'Third product', 'price' => 30],
    ];
}
