<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\TagsTranslationsFixture` for the ODM test harness.
 */
class TagsTranslationsFixture extends TestFixture
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
    public string $table = 'tags_translations';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'locale' => 'en_us', 'name' => 'tag 1 translated into en_us'],
        ['_id' => '000000000000000000000002', 'locale' => 'en_us', 'name' => 'tag 2 translated into en_us'],
        ['_id' => '000000000000000000000003', 'locale' => 'en_us', 'name' => 'tag 3 translated into en_us'],
    ];
}
