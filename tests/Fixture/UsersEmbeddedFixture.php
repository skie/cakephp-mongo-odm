<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Users with embedded addresses/profile for the embedded-association tests.
 */
class UsersEmbeddedFixture extends TestFixture
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
    public string $collection = 'users_embedded';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        [
            '_id' => '000000000000000000000001',
            'username' => 'mariano',
            'addresses' => [
                ['city' => 'NYC', 'zip' => '10001'],
                ['city' => 'LA', 'zip' => '90001'],
            ],
            'profile' => ['city' => 'NYC', 'zip' => '10001'],
        ],
        [
            '_id' => '000000000000000000000002',
            'username' => 'nate',
            'addresses' => [
                ['city' => 'SF', 'zip' => '94101'],
            ],
            'profile' => null,
        ],
    ];
}
