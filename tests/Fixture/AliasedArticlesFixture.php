<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\AliasedArticlesFixture` for the ODM test harness.
 *
 * The source fixture aliases the `articles` table via `$tableAlias`; crustum's
 * `TestFixture` has no alias property, so the alias resolves to the `articles`
 * collection directly.
 */
class AliasedArticlesFixture extends TestFixture
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
    public string $table = 'articles';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [];
}
