<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\TagsShadowTranslationsFixture` for the ODM test harness.
 *
 * @ported-from \Cake\Test\Fixture\TagsShadowTranslationsFixture
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
    public string $collection = 'tags_shadow_translations';

    /**
     * Documents to insert.
     *
     * The source table has a composite primary key `(locale, id)`; `id` is
     * preserved as a literal field and a unique `_id` is generated per record.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'locale' => 'eng', '_shadow_id' => '000000000000000000000001', 'name' => 'tag1 in eng'],
        ['_id' => '000000000000000000000002', 'locale' => 'deu', '_shadow_id' => '000000000000000000000001', 'name' => 'tag1 in deu'],
        ['_id' => '000000000000000000000003', 'locale' => 'cze', '_shadow_id' => '000000000000000000000001', 'name' => 'tag1 in cze'],
        ['_id' => '000000000000000000000004', 'locale' => 'eng', '_shadow_id' => '000000000000000000000002', 'name' => 'tag2 in eng'],
        ['_id' => '000000000000000000000005', 'locale' => 'deu', '_shadow_id' => '000000000000000000000002', 'name' => 'tag2 in deu'],
        ['_id' => '000000000000000000000006', 'locale' => 'cze', '_shadow_id' => '000000000000000000000002', 'name' => 'tag2 in cze'],
        ['_id' => '000000000000000000000007', 'locale' => 'eng', '_shadow_id' => '000000000000000000000003', 'name' => 'tag3 in eng'],
        ['_id' => '000000000000000000000008', 'locale' => 'deu', '_shadow_id' => '000000000000000000000003', 'name' => 'tag3 in deu'],
        ['_id' => '000000000000000000000009', 'locale' => 'cze', '_shadow_id' => '000000000000000000000003', 'name' => 'tag3 in cze'],
    ];
}
