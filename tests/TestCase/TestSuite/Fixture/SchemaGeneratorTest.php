<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\TestSuite\Fixture;

use Cake\Core\Exception\CakeException;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Schema\SchemaManager;
use Crustum\Mongo\TestSuite\Fixture\SchemaGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * Tests the TestSuite\Fixture\SchemaGenerator.
 *
 * These cover Mongo-specific schema loading (keyed format, scoped drop,
 * validator/index creation) and are not part of the ORM→ODM test port plan.
 */
#[CoversClass(SchemaGenerator::class)]
class SchemaGeneratorTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Connection
     */
    protected Connection $connection;

    /**
     * The schema manager used to assert generated state.
     *
     * @var \Crustum\Mongo\Database\Schema\SchemaManager
     */
    protected SchemaManager $manager;

    /**
     * Temporary schema files created during the test.
     *
     * @var list<string>
     */
    protected array $tempFiles = [];

    /**
     * Set up before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = ConnectionManager::get('test_mongo');
        $this->manager = new SchemaManager($this->connection);
    }

    /**
     * Tear down after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        $this->tempFiles = [];
        parent::tearDown();
    }

    /**
     * Writes a temporary schema file and registers it for cleanup.
     *
     * @param array<string, mixed> $schema The schema definition.
     * @return string The file path.
     */
    protected function writeSchema(array $schema): string
    {
        $file = TMP . 'tests/schema_' . uniqid() . '.php';
        $this->tempFiles[] = $file;

        file_put_contents(
            $file,
            "<?php\nreturn " . var_export($schema, true) . ";\n",
        );

        return $file;
    }

    /**
     * Creates the generator under test.
     *
     * @param string $schemaPath Schema file path.
     * @return \Crustum\Mongo\TestSuite\Fixture\SchemaGenerator
     */
    protected function generator(string $schemaPath): SchemaGenerator
    {
        return new SchemaGenerator($schemaPath, 'test_mongo');
    }

    /**
     * Test reload with a missing schema file throws.
     *
     * @return void
     */
    public function testMissingSchemaFileThrows(): void
    {
        $generator = $this->generator(TMP . 'tests/does_not_exist.php');

        $this->expectException(RuntimeException::class);
        $generator->reload();
    }

    /**
     * Test reload with a non-array schema file throws.
     *
     * @return void
     */
    public function testNonArraySchemaFileThrows(): void
    {
        $file = TMP . 'tests/schema_nonarray_' . uniqid() . '.php';
        $this->tempFiles[] = $file;
        file_put_contents($file, "<?php\nreturn 'nope';\n");

        $this->expectException(RuntimeException::class);
        $this->generator($file)->reload();
    }

    /**
     * Test reload with an invalid collection definition throws.
     *
     * @return void
     */
    public function testInvalidCollectionDefinitionThrows(): void
    {
        $file = $this->writeSchema([
            'articles' => 'not an array',
        ]);

        $this->expectException(CakeException::class);
        $this->generator($file)->reload();
    }

    /**
     * Test reload with an invalid index key throws.
     *
     * @return void
     */
    public function testInvalidIndexKeyThrows(): void
    {
        $file = $this->writeSchema([
            'articles' => [
                'indexes' => [
                    'articles_bad' => ['options' => ['unique' => true]],
                ],
            ],
        ]);

        $this->expectException(CakeException::class);
        $this->generator($file)->reload();
    }

    /**
     * Test reload with an invalid validator shape throws.
     *
     * @return void
     */
    public function testInvalidValidatorThrows(): void
    {
        $file = $this->writeSchema([
            'articles' => [
                'validator' => 'not an array',
            ],
        ]);

        $this->expectException(CakeException::class);
        $this->generator($file)->reload();
    }

    /**
     * Test reload with the wrong connection class throws.
     *
     * @return void
     */
    public function testWrongConnectionClassThrows(): void
    {
        $file = $this->writeSchema([
            'articles' => [],
        ]);
        $generator = new SchemaGenerator($file, 'test');

        $this->expectException(RuntimeException::class);
        $generator->reload();
    }

    /**
     * Test reload creates collections only for names in the file (scoped drop).
     *
     * @return void
     */
    public function testScopedDropLeavesUndeclaredCollections(): void
    {
        $declared = 'sg_declared';
        $undeclared = 'sg_undeclared';

        $this->connection->getDatabase()->dropCollection($declared);
        $this->connection->getDatabase()->dropCollection($undeclared);
        $this->connection->getCollection($undeclared)->insertOne(['name' => 'keep']);

        $file = $this->writeSchema([
            $declared => [
                'fields' => ['name' => ['bsonType' => 'string']],
            ],
        ]);
        $this->generator($file)->reload();

        $names = $this->manager->listCollections();
        $this->assertContains($declared, $names);
        $this->assertContains($undeclared, $names);

        $this->connection->getDatabase()->dropCollection($declared);
        $this->connection->getDatabase()->dropCollection($undeclared);
    }

    /**
     * Test reload with a validator creates the collection with validation.
     *
     * @return void
     */
    public function testCreateWithValidator(): void
    {
        $name = 'sg_validator';
        $this->connection->getDatabase()->dropCollection($name);

        $file = $this->writeSchema([
            $name => [
                'validator' => [
                    '$jsonSchema' => [
                        'bsonType' => 'object',
                        'required' => ['title'],
                        'properties' => [
                            'title' => ['bsonType' => 'string'],
                        ],
                    ],
                ],
                'options' => [
                    'validationLevel' => 'strict',
                    'validationAction' => 'error',
                ],
            ],
        ]);
        $this->generator($file)->reload();

        $validator = $this->manager->getValidator($name);
        $this->assertIsArray($validator);
        $this->assertSame(['title'], $validator['$jsonSchema']['required']);

        $this->connection->getDatabase()->dropCollection($name);
    }

    /**
     * Test reload compiles the fields shorthand into a validator.
     *
     * @return void
     */
    public function testFieldsShorthandCompilesValidator(): void
    {
        $name = 'sg_fields';
        $this->connection->getDatabase()->dropCollection($name);

        $file = $this->writeSchema([
            $name => [
                'fields' => [
                    'title' => ['bsonType' => 'string'],
                    'author_id' => ['bsonType' => 'objectId'],
                ],
            ],
        ]);
        $this->generator($file)->reload();

        $validator = $this->manager->getValidator($name);
        $this->assertArrayHasKey('$jsonSchema', $validator);
        $this->assertSame('string', $validator['$jsonSchema']['properties']['title']['bsonType']);

        $this->connection->getDatabase()->dropCollection($name);
    }

    /**
     * Test reload creates named indexes from the keyed index map.
     *
     * @return void
     */
    public function testCreateNamedIndexes(): void
    {
        $name = 'sg_indexes';
        $this->connection->getDatabase()->dropCollection($name);

        $file = $this->writeSchema([
            $name => [
                'indexes' => [
                    'articles_author' => [
                        'key' => ['author_id' => 1],
                        'options' => ['unique' => true],
                    ],
                    'articles_title' => [
                        'key' => ['title' => 1],
                    ],
                ],
            ],
        ]);
        $this->generator($file)->reload();

        $indexes = $this->manager->listIndexes($name);
        $this->assertArrayHasKey('articles_author', $indexes);
        $this->assertArrayHasKey('articles_title', $indexes);
        $this->assertSame(['author_id' => 1], $indexes['articles_author']['key']);
        $this->assertTrue($indexes['articles_author']['unique']);

        $this->connection->getDatabase()->dropCollection($name);
    }

    /**
     * Test reload never creates a user index for _id.
     *
     * MongoDB owns the implicit `_id_` index; a declared `_id` index must be
     * skipped so only the implicit one exists.
     *
     * @return void
     */
    public function testNoUserIndexForId(): void
    {
        $name = 'sg_id_index';
        $this->connection->getDatabase()->dropCollection($name);

        $file = $this->writeSchema([
            $name => [
                'indexes' => [
                    'user_id_index' => ['key' => ['_id' => 1]],
                ],
            ],
        ]);
        $this->generator($file)->reload();

        $indexes = $this->manager->listIndexes($name);
        $this->assertArrayHasKey('_id_', $indexes);
        $this->assertArrayNotHasKey('user_id_index', $indexes);
        $this->assertSame(['_id' => 1], $indexes['_id_']['key']);

        $this->connection->getDatabase()->dropCollection($name);
    }

    /**
     * Test reload is idempotent: running twice keeps the same state.
     *
     * @return void
     */
    public function testRepeatedReloadProducesSameState(): void
    {
        $name = 'sg_idempotent';
        $this->connection->getDatabase()->dropCollection($name);

        $file = $this->writeSchema([
            $name => [
                'fields' => [
                    'name' => ['bsonType' => 'string'],
                ],
                'indexes' => [
                    'sg_name' => ['key' => ['name' => 1]],
                ],
            ],
        ]);

        $generator = $this->generator($file);
        $generator->reload();
        $generator->reload();

        $indexes = $this->manager->listIndexes($name);
        $this->assertContains($name, $this->manager->listCollections());
        $this->assertArrayHasKey('sg_name', $indexes);

        $this->connection->getDatabase()->dropCollection($name);
    }

    /**
     * Test reload with an empty schema file is a no-op.
     *
     * @return void
     */
    public function testEmptySchemaIsNoop(): void
    {
        $file = $this->writeSchema([]);

        $this->generator($file)->reload();

        $this->addToAssertionCount(1);
    }
}
