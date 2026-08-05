<?php
declare(strict_types=1);

/**
 * Test database schema for the Mongo connection.
 *
 * Keyed by collection name. Loaded by
 * `Crustum\Mongo\TestSuite\Fixture\SchemaGenerator` in `tests/bootstrap.php`.
 *
 * @return array<string, array<string, mixed>>
 */
return [
    'test_users' => [
        'fields' => [
            'username' => ['bsonType' => 'string'],
            'email' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'test_users_username' => ['key' => ['username' => 1]],
        ],
    ],
];
