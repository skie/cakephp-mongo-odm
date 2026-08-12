<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Audits fixture for polymorphic foreign-key join tests.
 *
 * `foreign_key` holds the ObjectId of the audited document and `model` the
 * owning model name — the classic polymorphic association shape. The field
 * name deliberately does NOT end in `_id` so type resolution relies on the
 * schema type map, not a naming convention.
 */
class AuditsFixture extends TestFixture
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
    public string $collection = 'audits';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'foreign_key' => '000000000000000000000001', 'model' => 'Users', 'note' => 'created user 1'],
        ['_id' => '000000000000000000000002', 'foreign_key' => '000000000000000000000002', 'model' => 'Users', 'note' => 'updated user 2'],
        ['_id' => '000000000000000000000003', 'foreign_key' => '000000000000000000000001', 'model' => 'Articles', 'note' => 'created article 1'],
    ];
}
