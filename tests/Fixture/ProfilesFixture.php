<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\ProfilesFixture` for the ODM test harness.
 */
class ProfilesFixture extends TestFixture
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
    public string $collection = 'profiles';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'user_id' => '000000000000000000000001', 'first_name' => 'mariano', 'last_name' => 'iglesias', 'is_active' => false],
        ['_id' => '000000000000000000000002', 'user_id' => '000000000000000000000002', 'first_name' => 'nate', 'last_name' => 'abele', 'is_active' => false],
        ['_id' => '000000000000000000000003', 'user_id' => '000000000000000000000003', 'first_name' => 'larry', 'last_name' => 'masters', 'is_active' => true],
        ['_id' => '000000000000000000000004', 'user_id' => '000000000000000000000004', 'first_name' => 'garrett', 'last_name' => 'woodworth', 'is_active' => false],
    ];
}
