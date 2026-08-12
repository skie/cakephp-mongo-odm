<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\CommentsTranslationsFixture` for the ODM test harness.
 */
class CommentsTranslationsFixture extends TestFixture
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
    public string $collection = 'comments_translations';

    /**
     * Documents to insert.
     *
     * The source table has a composite primary key `(locale, id)`; `id` is
     * preserved as a literal field and a unique `_id` is generated per record.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'locale' => 'eng', 'id' => '000000000000000000000001', 'comment' => 'Comment #1'],
        ['_id' => '000000000000000000000002', 'locale' => 'eng', 'id' => '000000000000000000000002', 'comment' => 'Comment #2'],
        ['_id' => '000000000000000000000003', 'locale' => 'eng', 'id' => '000000000000000000000003', 'comment' => 'Comment #3'],
        ['_id' => '000000000000000000000004', 'locale' => 'eng', 'id' => '000000000000000000000004', 'comment' => 'Comment #4'],
        ['_id' => '000000000000000000000005', 'locale' => 'spa', 'id' => '000000000000000000000004', 'comment' => 'Comentario #4'],
    ];
}
