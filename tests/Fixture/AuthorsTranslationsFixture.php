<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\AuthorsTranslationsFixture` for the ODM test harness.
 */
class AuthorsTranslationsFixture extends TestFixture
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
    public string $table = 'authors_translations';

    /**
     * Documents to insert.
     *
     * The source table has a composite primary key `(locale, id)`; `id` is
     * preserved as a literal field and a unique `_id` is generated per record.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'locale' => 'eng', 'id' => '000000000000000000000001', 'name' => 'May-rianoh'],
    ];
}
