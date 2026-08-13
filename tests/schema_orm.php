<?php
declare(strict_types=1);

/**
 * Test database schema for the SQL ORM tables used by the cross-boundary
 * association bridge tests.
 *
 * Loaded via `SchemaLoader::loadInternalFile()` in `tests/bootstrap.php`
 * alongside `schema.php` (SQL) and `schema_mongo.php` (Mongo). Keys are table
 * names; each definition uses the CakePHP `TableSchema` array format
 * (columns/constraints/indexes).
 *
 * @return array<string, mixed>
 */
return [
    'orders' => [
        'columns' => [
            'id' => [
                'type' => 'integer',
                'autoIncrement' => true,
            ],
            'customer_name' => [
                'type' => 'string',
                'length' => 255,
                'null' => true,
            ],
            'author_id' => [
                'type' => 'string',
                'length' => 24,
                'null' => true,
            ],
        ],
        'constraints' => [
            'primary' => [
                'type' => 'primary',
                'columns' => ['id'],
            ],
        ],
    ],
    'files' => [
        'columns' => [
            'id' => [
                'type' => 'integer',
                'autoIncrement' => true,
            ],
            'name' => [
                'type' => 'string',
                'length' => 255,
                'null' => true,
            ],
            'file_ref' => [
                'type' => 'string',
                'length' => 255,
                'null' => true,
            ],
        ],
        'constraints' => [
            'primary' => [
                'type' => 'primary',
                'columns' => ['id'],
            ],
        ],
    ],
];
