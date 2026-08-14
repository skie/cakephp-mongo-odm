<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Migration\SchemaDiff;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the SchemaDiff desired-vs-actual diff engine used by
 * `mongo migrations diff` and `bake mongo_migration_diff`.
 */
#[CoversClass(SchemaDiff::class)]
class SchemaDiffTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Migration\SchemaDiff
     */
    protected SchemaDiff $diff;

    /**
     * Set up before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->diff = new SchemaDiff();
    }

    /**
     * Returns a sample articles collection definition.
     *
     * @return array<string, mixed>
     */
    protected function articlesDefinition(): array
    {
        return [
            'articles' => [
                'validator' => [
                    '$jsonSchema' => [
                        'bsonType' => 'object',
                        'properties' => [
                            'title' => ['bsonType' => 'string'],
                            'author_id' => ['bsonType' => 'objectId'],
                        ],
                        'additionalProperties' => true,
                    ],
                ],
                'indexes' => [
                    'author_id_1' => [
                        'key' => ['author_id' => 1],
                        'options' => [],
                    ],
                ],
            ],
        ];
    }

    /**
     * Test identical schemas produce no operations.
     *
     * @return void
     */
    public function testNoDifferences(): void
    {
        $desired = $this->articlesDefinition();
        $actual = $desired;

        $this->assertSame([], $this->diff->diff($desired, $actual));
    }

    /**
     * Test a brand-new collection emits a createCollection operation carrying
     * the validator, plus createIndex operations for its indexes.
     *
     * @return void
     */
    public function testCreateCollection(): void
    {
        $desired = $this->articlesDefinition();

        $operations = $this->diff->diff($desired, []);

        $this->assertCount(2, $operations);

        $this->assertSame('createCollection', $operations[0]['type']);
        $this->assertSame('articles', $operations[0]['collection']);
        $this->assertSame($desired['articles']['validator'], $operations[0]['options']['validator']);

        $this->assertSame('createIndex', $operations[1]['type']);
        $this->assertSame('author_id_1', $operations[1]['name']);
        $this->assertSame(['author_id' => 1], $operations[1]['key']);
    }

    /**
     * Test a brand-new collection with indexes emits createIndex operations so
     * a fresh-database diff does not silently lose the indexes.
     *
     * @return void
     */
    public function testCreateCollectionEmitsIndexOps(): void
    {
        $desired = $this->articlesDefinition();

        $operations = $this->diff->diff($desired, []);

        $indexOps = array_values(array_filter(
            $operations,
            fn(array $op): bool => $op['type'] === 'createIndex',
        ));

        $this->assertCount(1, $indexOps);
        $this->assertSame('author_id_1', $indexOps[0]['name']);
        $this->assertSame(['author_id' => 1], $indexOps[0]['key']);
    }

    /**
     * Test a validator change on an existing collection emits setValidator.
     *
     * @return void
     */
    public function testValidatorChange(): void
    {
        $desired = $this->articlesDefinition();
        $actual = $desired;
        $actual['articles']['validator']['$jsonSchema']['properties']['slug'] = ['bsonType' => 'string'];

        $operations = $this->diff->diff($desired, $actual);

        $this->assertCount(1, $operations);
        $this->assertSame('setValidator', $operations[0]['type']);
        $this->assertSame('articles', $operations[0]['collection']);
        $this->assertSame($desired['articles']['validator'], $operations[0]['validator']);
    }

    /**
     * Test a missing index on an existing collection emits createIndex.
     *
     * @return void
     */
    public function testAddIndex(): void
    {
        $desired = $this->articlesDefinition();
        $actual = $desired;
        unset($actual['articles']['indexes']['author_id_1']);

        $operations = $this->diff->diff($desired, $actual);

        $this->assertCount(1, $operations);
        $this->assertSame('createIndex', $operations[0]['type']);
        $this->assertSame('articles', $operations[0]['collection']);
        $this->assertSame('author_id_1', $operations[0]['name']);
        $this->assertSame(['author_id' => 1], $operations[0]['key']);
    }

    /**
     * Test an index present in the database but not desired emits dropIndex.
     *
     * @return void
     */
    public function testDropIndex(): void
    {
        $desired = $this->articlesDefinition();
        $actual = $desired;
        $actual['articles']['indexes']['legacy_slug_1'] = [
            'key' => ['slug' => 1],
            'options' => [],
        ];

        $operations = $this->diff->diff($desired, $actual);

        $this->assertCount(1, $operations);
        $this->assertSame('dropIndex', $operations[0]['type']);
        $this->assertSame('articles', $operations[0]['collection']);
        $this->assertSame('legacy_slug_1', $operations[0]['name']);
    }

    /**
     * Test a collection present in the database but not desired emits dropCollection.
     *
     * @return void
     */
    public function testDropCollection(): void
    {
        $actual = [
            'legacy' => ['validator' => null, 'indexes' => []],
        ];

        $operations = $this->diff->diff([], $actual);

        $this->assertCount(1, $operations);
        $this->assertSame('dropCollection', $operations[0]['type']);
        $this->assertSame('legacy', $operations[0]['collection']);
    }

    /**
     * Test multiple differences emit operations in a stable order.
     *
     * @return void
     */
    public function testMixedOperations(): void
    {
        $desired = $this->articlesDefinition();
        $desired['posts'] = [
            'validator' => null,
            'indexes' => [],
        ];
        $actual = $this->articlesDefinition();
        unset($actual['articles']['indexes']['author_id_1']);
        $actual['articles']['validator'] = null;
        $actual['legacy'] = ['validator' => null, 'indexes' => []];

        $operations = $this->diff->diff($desired, $actual);

        $types = array_column($operations, 'type');
        $this->assertSame('setValidator', $types[0]);
        $this->assertContains('createIndex', $types);
        $this->assertContains('createCollection', $types);
        $this->assertContains('dropCollection', $types);
        $this->assertSame('posts', $operations[array_search('createCollection', $types, true)]['collection']);
        $this->assertSame('legacy', $operations[array_search('dropCollection', $types, true)]['collection']);
    }
}
