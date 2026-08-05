<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\ArticlesTranslationsFixture` for the ODM test harness.
 */
class ArticlesTranslationsFixture extends TestFixture
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
    public string $table = 'articles_translations';

    /**
     * Documents to insert.
     *
     * The source table has a composite primary key `(locale, id)`; `id` is
     * preserved as a literal field and a unique `_id` is generated per record.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'locale' => 'eng', 'id' => 1, 'title' => 'Title #1', 'body' => 'Content #1'],
        ['_id' => '000000000000000000000002', 'locale' => 'deu', 'id' => 1, 'title' => 'Titel #1', 'body' => 'Inhalt #1'],
        ['_id' => '000000000000000000000003', 'locale' => 'cze', 'id' => 1, 'title' => 'Titulek #1', 'body' => 'Obsah #1'],
        ['_id' => '000000000000000000000004', 'locale' => 'spa', 'id' => 1, 'title' => 'First Article', 'body' => 'Contenido #1'],
        ['_id' => '000000000000000000000005', 'locale' => 'zzz', 'id' => 1, 'title' => '', 'body' => ''],
        ['_id' => '000000000000000000000006', 'locale' => 'eng', 'id' => 2, 'title' => 'Title #2', 'body' => 'Content #2'],
        ['_id' => '000000000000000000000007', 'locale' => 'deu', 'id' => 2, 'title' => 'Titel #2', 'body' => 'Inhalt #2'],
        ['_id' => '000000000000000000000008', 'locale' => 'cze', 'id' => 2, 'title' => 'Titulek #2', 'body' => 'Obsah #2'],
        ['_id' => '000000000000000000000009', 'locale' => 'eng', 'id' => 3, 'title' => 'Title #3', 'body' => 'Content #3'],
        ['_id' => '000000000000000000000010', 'locale' => 'deu', 'id' => 3, 'title' => 'Titel #3', 'body' => 'Inhalt #3'],
        ['_id' => '000000000000000000000011', 'locale' => 'cze', 'id' => 3, 'title' => 'Titulek #3', 'body' => 'Obsah #3'],
    ];
}
