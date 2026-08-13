<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture\Orm;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * SQL ORM Files fixture for the cross-boundary bridge tests.
 *
 * `file_ref` holds a Mongo DBRef pointer (`{ $ref, $id }`) loaded by the
 * bridge DBRef association.
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §5.5
 */
class FilesFixture extends TestFixture
{
    /**
     * The connection name.
     *
     * @var string
     */
    public string $connection = 'test';

    /**
     * The table name.
     *
     * @var string
     */
    public string $table = 'files';

    /**
     * Records to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['id' => 1, 'name' => 'report.pdf', 'file_ref' => '000000000000000000000001'],
    ];
}
