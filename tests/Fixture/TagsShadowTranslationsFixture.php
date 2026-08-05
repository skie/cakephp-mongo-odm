<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\TagsShadowTranslationsFixture` for the ODM test harness.
 */
class TagsShadowTranslationsFixture extends TestFixture
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
    public string $table = 'tags_shadow_translations';

    /**
     * Documents to insert.
     *
     * The source table has a composite primary key `(locale, id)`; `id` is
     * preserved as a literal field and a unique `_id` is generated per record.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'locale' => 'eng', 'id' => 1, 'name' => 'tag1 in eng'],
        ['_id' => '000000000000000000000002', 'locale' => 'deu', 'id' => 1, 'name' => 'tag1 in deu'],
        ['_id' => '000000000000000000000003', 'locale' => 'cze', 'id' => 1, 'name' => 'tag1 in cze'],
        ['_id' => '000000000000000000000004', 'locale' => 'eng', 'id' => 2, 'name' => 'tag2 in eng'],
        ['_id' => '000000000000000000000005', 'locale' => 'deu', 'id' => 2, 'name' => 'tag2 in deu'],
        ['_id' => '000000000000000000000006', 'locale' => 'cze', 'id' => 2, 'name' => 'tag2 in cze'],
        ['_id' => '000000000000000000000007', 'locale' => 'eng', 'id' => 3, 'name' => 'tag3 in eng'],
        ['_id' => '000000000000000000000008', 'locale' => 'deu', 'id' => 3, 'name' => 'tag3 in deu'],
        ['_id' => '000000000000000000000009', 'locale' => 'cze', 'id' => 3, 'name' => 'tag3 in cze'],
    ];
}
