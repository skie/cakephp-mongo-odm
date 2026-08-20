<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\AuthorsTagsFixture` for the ODM test harness.
 *
 * @inspired-by \Cake\Test\Fixture\AuthorsTagsFixture
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
    public string $collection = 'authors_tags';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'author_id' => '000000000000000000000003', 'tag_id' => '000000000000000000000001'],
        ['_id' => '000000000000000000000002', 'author_id' => '000000000000000000000003', 'tag_id' => '000000000000000000000002'],
        ['_id' => '000000000000000000000003', 'author_id' => '000000000000000000000002', 'tag_id' => '000000000000000000000001'],
        ['_id' => '000000000000000000000004', 'author_id' => '000000000000000000000002', 'tag_id' => '000000000000000000000003'],
    ];
}
