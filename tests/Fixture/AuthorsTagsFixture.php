<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\AuthorsTagsFixture` for the ODM test harness.
 */
class AuthorsTagsFixture extends TestFixture
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
    public string $table = 'authors_tags';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'author_id' => 3, 'tag_id' => 1],
        ['_id' => '000000000000000000000002', 'author_id' => 3, 'tag_id' => 2],
        ['_id' => '000000000000000000000003', 'author_id' => 2, 'tag_id' => 1],
        ['_id' => '000000000000000000000004', 'author_id' => 2, 'tag_id' => 3],
    ];
}
