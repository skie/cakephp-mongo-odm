<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Command;

use Cake\Console\Arguments;
use Cake\Console\CommandInterface;
use Crustum\Mongo\Command\Bake\MongoModelCommand;
use Crustum\Mongo\Database\Schema\CollectionSchema;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\Test\TestCase\Command\TestCase;

/**
 * MongoModelCommandTest class
 */
class MongoModelCommandTest extends TestCase
{
    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->_compareBasePath = ROOT . DS . 'tests' . DS . 'comparisons' . DS . 'Command' . DS;
    }

    /**
     * Test that listing collections works.
     *
     * @return void
     */
    public function testListAllConnection(): void
    {
        $this->exec('bake mongo_model --connection mongo');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertOutputContains('Products');
    }

    /**
     * Test baking a full model (document + collection) from the live schema.
     *
     * Uses `products` (present in schema_mongo) which has no existing
     * test-app class, so the generated files are brand new.
     *
     * @return void
     */
    public function testBakeModel(): void
    {
        $this->generatedFiles = [
            APP . 'Model/Document/Product.php',
            APP . 'Model/Collection/ProductsCollection.php',
        ];
        $this->exec('bake mongo_model Products --no-test --no-fixture --connection mongo');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFilesExist($this->generatedFiles);

        $document = file_get_contents($this->generatedFiles[0]);
        $this->assertStringContainsString('class Product extends Document', $document);
        $this->assertStringContainsString("#[DocumentAttribute(collection: 'products')]", $document);
        $this->assertStringContainsString("#[Field(name: 'name', type: CollectionSchemaInterface::TYPE_STRING", $document);
        $this->assertStringContainsString("#[Field(name: '_id', type: CollectionSchemaInterface::TYPE_OBJECTID, primaryKey: true)]", $document);
        $collection = file_get_contents($this->generatedFiles[1]);
        $this->assertStringContainsString('class ProductsCollection extends BaseCollection', $collection);
        $this->assertStringContainsString("\$this->setPrimaryKey('_id');", $collection);
    }

    /**
     * Test that baked documents canonicalize Mongo bsonTypes to
     * `CollectionSchemaInterface::TYPE_*` constants, matching `bake document`.
     *
     * `products.category`/`price` are bsonType `int` in the test schema; the
     * canonical TypeFactory name is `integer`.
     *
     * @return void
     */
    public function testBakeDocumentCanonicalizesTypes(): void
    {
        $this->generatedFile = APP . 'Model/Document/Product.php';
        $this->exec('bake mongo_model Products --no-test --no-fixture --no-collection --connection mongo');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFileExists($this->generatedFile);

        $document = file_get_contents($this->generatedFile);
        $this->assertStringContainsString("#[Field(name: 'price', type: CollectionSchemaInterface::TYPE_INTEGER)]", $document);
        $this->assertStringNotContainsString("#[Field(name: 'price', type: 'int')]", $document);
    }

    /**
     * Test that association detection finds a belongsTo relation on an
     * objectId foreign key pointing at an existing collection.
     *
     * `comments` has `article_id`/`user_id` objectId fields; `articles` and
     * `users` collections exist. No test-app class named `Comment`/`Comments`
     * exists, so generated files are new.
     *
     * @return void
     */
    public function testBakeModelWithBelongsTo(): void
    {
        $this->generatedFiles = [
            APP . 'Model/Collection/CommentsCollection.php',
        ];
        $this->exec('bake mongo_model Comments --no-test --no-fixture --no-document --connection mongo');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFilesExist($this->generatedFiles);

        $collection = file_get_contents($this->generatedFiles[0]);
        $this->assertStringContainsString("belongsTo('Article'", $collection);
        $this->assertStringContainsString("'className' => 'Articles'", $collection);
        $this->assertStringContainsString("'foreignKey' => 'article_id'", $collection);
    }

    /**
     * Test that validation rules are generated for typed fields.
     *
     * @return void
     */
    public function testBakeModelValidation(): void
    {
        $this->generatedFiles = [
            APP . 'Model/Collection/ProductsCollection.php',
        ];
        $this->exec('bake mongo_model Products --no-test --no-fixture --no-document --connection mongo');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFilesExist($this->generatedFiles);

        $collection = file_get_contents($this->generatedFiles[0]);
        $this->assertStringContainsString('public function validationDefault(Validator $validator): Validator', $collection);
        $this->assertStringContainsString('requirePresence', $collection);
        $this->assertStringContainsString("->scalar('name')", $collection);
    }

    /**
     * Test that validation rules include an `integer` rule for int-typed fields.
     *
     * `products.price` is bsonType `int`; the canonical TypeFactory name is
     * `integer`, so the baked validator must emit `->integer('price')`.
     *
     * @return void
     */
    public function testBakeModelValidationIntRule(): void
    {
        $this->generatedFiles = [
            APP . 'Model/Collection/ProductsCollection.php',
        ];
        $this->exec('bake mongo_model Products --no-test --no-fixture --no-document --connection mongo');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFilesExist($this->generatedFiles);

        $collection = file_get_contents($this->generatedFiles[0]);
        $this->assertStringContainsString("->integer('price')", $collection);
    }

    /**
     * Test the getCollectionName method.
     *
     * @return void
     */
    public function testGetCollectionName(): void
    {
        $command = new MongoModelCommand();
        $args = new Arguments([], [], []);

        $this->assertSame('products', $command->getCollectionName('Products', $args));

        $args = new Arguments([], ['collection' => 'items'], []);
        $this->assertSame('items', $command->getCollectionName('Products', $args));
    }

    /**
     * Test the primary key resolution.
     *
     * @return void
     */
    public function testGetPrimaryKey(): void
    {
        $command = new MongoModelCommand();
        $collection = new BaseCollection(['alias' => 'Articles', 'collection' => 'articles']);

        $args = new Arguments([], ['primary-key' => 'foo,bar'], []);
        $this->assertSame(['foo', 'bar'], $command->getPrimaryKey($collection, $args));

        $args = new Arguments([], [], []);
        $this->assertSame(['_id'], $command->getPrimaryKey($collection, $args));
    }

    /**
     * Test field validation for scalar/objectid types.
     *
     * @return void
     */
    public function testFieldValidation(): void
    {
        $command = new MongoModelCommand();
        $schema = new CollectionSchema('products');
        $schema->addField('name', ['type' => 'string']);
        $schema->addField('price', ['type' => 'integer']);
        $schema->addField('author_id', ['type' => 'objectid']);

        $rules = $command->fieldValidation($schema, 'name', ['type' => 'string'], ['_id']);
        $this->assertArrayHasKey('scalar', $rules);

        $rules = $command->fieldValidation($schema, 'price', ['type' => 'integer'], ['_id']);
        $this->assertArrayHasKey('integer', $rules);

        $rules = $command->fieldValidation($schema, 'author_id', ['type' => 'objectid'], ['_id']);
        $this->assertArrayHasKey('validId', $rules);
    }

    /**
     * Test the display field resolution (first string field).
     *
     * @return void
     */
    public function testGetDisplayField(): void
    {
        $command = new MongoModelCommand();
        $collection = new BaseCollection(['alias' => 'Products', 'collection' => 'products']);
        $schema = new CollectionSchema('products');
        $schema->addField('name', ['type' => 'string']);
        $schema->addField('price', ['type' => 'integer']);
        $collection->setSchema($schema);

        $args = new Arguments([], ['display-field' => 'price'], []);
        $this->assertSame('price', $command->getDisplayField($collection, $args));

        $args = new Arguments([], [], []);
        $this->assertSame('name', $command->getDisplayField($collection, $args));
    }
}
