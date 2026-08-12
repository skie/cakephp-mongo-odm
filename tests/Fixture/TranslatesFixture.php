<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\TranslatesFixture` for the ODM test harness.
 */
class TranslatesFixture extends TestFixture
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
    public string $collection = 'i18n';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'locale' => 'eng', 'model' => 'Articles', 'foreign_key' => 1, 'field' => 'title', 'content' => 'Title #1'],
        ['_id' => '000000000000000000000002', 'locale' => 'eng', 'model' => 'Articles', 'foreign_key' => 1, 'field' => 'body', 'content' => 'Content #1'],
        ['_id' => '000000000000000000000003', 'locale' => 'eng', 'model' => 'Articles', 'foreign_key' => 1, 'field' => 'description', 'content' => 'Description #1'],
        ['_id' => '000000000000000000000004', 'locale' => 'spa', 'model' => 'Articles', 'foreign_key' => 1, 'field' => 'body', 'content' => 'Contenido #1'],
        ['_id' => '000000000000000000000005', 'locale' => 'spa', 'model' => 'Articles', 'foreign_key' => 1, 'field' => 'description', 'content' => ''],
        ['_id' => '000000000000000000000006', 'locale' => 'deu', 'model' => 'Articles', 'foreign_key' => 1, 'field' => 'title', 'content' => 'Titel #1'],
        ['_id' => '000000000000000000000007', 'locale' => 'deu', 'model' => 'Articles', 'foreign_key' => 1, 'field' => 'body', 'content' => 'Inhalt #1'],
        ['_id' => '000000000000000000000008', 'locale' => 'cze', 'model' => 'Articles', 'foreign_key' => 1, 'field' => 'title', 'content' => 'Titulek #1'],
        ['_id' => '000000000000000000000009', 'locale' => 'cze', 'model' => 'Articles', 'foreign_key' => 1, 'field' => 'body', 'content' => 'Obsah #1'],
        ['_id' => '000000000000000000000010', 'locale' => 'eng', 'model' => 'Articles', 'foreign_key' => 2, 'field' => 'title', 'content' => 'Title #2'],
        ['_id' => '000000000000000000000011', 'locale' => 'eng', 'model' => 'Articles', 'foreign_key' => 2, 'field' => 'body', 'content' => 'Content #2'],
        ['_id' => '000000000000000000000012', 'locale' => 'deu', 'model' => 'Articles', 'foreign_key' => 2, 'field' => 'title', 'content' => 'Titel #2'],
        ['_id' => '000000000000000000000013', 'locale' => 'deu', 'model' => 'Articles', 'foreign_key' => 2, 'field' => 'body', 'content' => 'Inhalt #2'],
        ['_id' => '000000000000000000000014', 'locale' => 'cze', 'model' => 'Articles', 'foreign_key' => 2, 'field' => 'title', 'content' => 'Titulek #2'],
        ['_id' => '000000000000000000000015', 'locale' => 'cze', 'model' => 'Articles', 'foreign_key' => 2, 'field' => 'body', 'content' => 'Obsah #2'],
        ['_id' => '000000000000000000000016', 'locale' => 'eng', 'model' => 'Articles', 'foreign_key' => 3, 'field' => 'title', 'content' => 'Title #3'],
        ['_id' => '000000000000000000000017', 'locale' => 'eng', 'model' => 'Articles', 'foreign_key' => 3, 'field' => 'body', 'content' => 'Content #3'],
        ['_id' => '000000000000000000000018', 'locale' => 'deu', 'model' => 'Articles', 'foreign_key' => 3, 'field' => 'title', 'content' => 'Titel #3'],
        ['_id' => '000000000000000000000019', 'locale' => 'deu', 'model' => 'Articles', 'foreign_key' => 3, 'field' => 'body', 'content' => 'Inhalt #3'],
        ['_id' => '000000000000000000000020', 'locale' => 'cze', 'model' => 'Articles', 'foreign_key' => 3, 'field' => 'title', 'content' => 'Titulek #3'],
        ['_id' => '000000000000000000000021', 'locale' => 'cze', 'model' => 'Articles', 'foreign_key' => 3, 'field' => 'body', 'content' => 'Obsah #3'],
        ['_id' => '000000000000000000000022', 'locale' => 'eng', 'model' => 'Comments', 'foreign_key' => 1, 'field' => 'comment', 'content' => 'Comment #1'],
        ['_id' => '000000000000000000000023', 'locale' => 'eng', 'model' => 'Comments', 'foreign_key' => 2, 'field' => 'comment', 'content' => 'Comment #2'],
        ['_id' => '000000000000000000000024', 'locale' => 'eng', 'model' => 'Comments', 'foreign_key' => 3, 'field' => 'comment', 'content' => 'Comment #3'],
        ['_id' => '000000000000000000000025', 'locale' => 'eng', 'model' => 'Comments', 'foreign_key' => 4, 'field' => 'comment', 'content' => 'Comment #4'],
        ['_id' => '000000000000000000000026', 'locale' => 'spa', 'model' => 'Comments', 'foreign_key' => 4, 'field' => 'comment', 'content' => 'Comentario #4'],
        ['_id' => '000000000000000000000027', 'locale' => 'eng', 'model' => 'Authors', 'foreign_key' => 1, 'field' => 'name', 'content' => 'May-rianoh'],
        ['_id' => '000000000000000000000028', 'locale' => 'dan', 'model' => 'NumberTrees', 'foreign_key' => 1, 'field' => 'name', 'content' => 'Elektroniker'],
        ['_id' => '000000000000000000000029', 'locale' => 'dan', 'model' => 'NumberTrees', 'foreign_key' => 11, 'field' => 'name', 'content' => 'Alien Tingerne'],
        ['_id' => '000000000000000000000030', 'locale' => 'eng', 'model' => 'SpecialTags', 'foreign_key' => 2, 'field' => 'extra_info', 'content' => 'Translated Info'],
    ];
}
