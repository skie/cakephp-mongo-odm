<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\AuthorsFixture` for the ODM test harness.
 */
class AuthorsFixture extends TestFixture
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
    public string $collection = 'authors';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'name' => 'mariano'],
        ['_id' => '000000000000000000000002', 'name' => 'nate'],
        ['_id' => '000000000000000000000003', 'name' => 'larry'],
        ['_id' => '000000000000000000000004', 'name' => 'garrett'],
    ];
}
