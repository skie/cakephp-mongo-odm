<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\OtherArticlesFixture` for the ODM test harness.
 *
 * The source fixture attaches to a non-test `other` connection; crustum's
 * `TestFixture` requires a connection name starting with `test`, so the port
 * attaches to `test_mongo`.
 */
class OtherArticlesFixture extends TestFixture
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
    public string $collection = 'other_articles';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [];
}
