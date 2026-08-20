<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\NullableAuthorsFixture` for the ODM test harness.
 *
 * The source SQL `$fields` (column definitions and constraints) are dropped:
 * crustum `TestFixture::$schema` holds Mongo index definitions, not columns.
 *
 * @inspired-by \Cake\Test\Fixture\NullableAuthorsFixture
 */
class NullableAuthorsFixture extends TestFixture
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
    public string $collection = 'nullable_authors';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'author_id' => '000000000000000000000003'],
        ['_id' => '000000000000000000000002', 'author_id' => null],
    ];
}
