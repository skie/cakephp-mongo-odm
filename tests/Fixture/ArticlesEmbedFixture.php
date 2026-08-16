<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Dedicated fixture for the Translate behavior embed strategy.
 *
 * Mirrors `ArticlesFixture` + `ArticlesTranslationsFixture`, but the non-default
 * locales live inline under the `_translations` embedded object (keyed by
 * locale). Kept separate from the shared `articles` fixture so the shadow
 * strategy tests are unaffected.
 */
class ArticlesEmbedFixture extends TestFixture
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
    public string $collection = 'articles_embed';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'author_id' => '000000000000000000000001', 'title' => 'First Article', 'body' => 'First Article Body', 'published' => 'Y', '_translations' => [
            'eng' => ['title' => 'Title #1', 'body' => 'Content #1'],
            'deu' => ['title' => 'Titel #1', 'body' => 'Inhalt #1'],
            'cze' => ['title' => 'Titulek #1', 'body' => 'Obsah #1'],
            'spa' => ['title' => 'First Article', 'body' => 'Contenido #1'],
            'zzz' => ['title' => '', 'body' => ''],
        ]],
        ['_id' => '000000000000000000000002', 'author_id' => '000000000000000000000003', 'title' => 'Second Article', 'body' => 'Second Article Body', 'published' => 'Y', '_translations' => [
            'eng' => ['title' => 'Title #2', 'body' => 'Content #2'],
            'deu' => ['title' => 'Titel #2', 'body' => 'Inhalt #2'],
            'cze' => ['title' => 'Titulek #2', 'body' => 'Obsah #2'],
        ]],
        ['_id' => '000000000000000000000003', 'author_id' => '000000000000000000000001', 'title' => 'Third Article', 'body' => 'Third Article Body', 'published' => 'Y', '_translations' => [
            'eng' => ['title' => 'Title #3', 'body' => 'Content #3'],
            'deu' => ['title' => 'Titel #3', 'body' => 'Inhalt #3'],
            'cze' => ['title' => 'Titulek #3', 'body' => 'Obsah #3'],
        ]],
    ];
}
