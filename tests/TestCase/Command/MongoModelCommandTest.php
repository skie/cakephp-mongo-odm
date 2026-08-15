<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Command;

use Cake\Console\Arguments;
use Cake\Console\CommandInterface;
use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleInput;
use Cake\Console\TestSuite\StubConsoleOutput;
use Crustum\Mongo\Command\Bake\MongoModelCommand;
use Crustum\Mongo\Database\Schema\CollectionSchema;
use Crustum\Mongo\ODM\BaseCollection;

/**
 * MongoModelCommandTest class
 *
 * Port of `Bake\Test\TestCase\Command\ModelCommandTest` for the Mongo ODM.
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
     * Test getting the collection object.
     *
     * @return void
     */
    public function testGetCollectionObject(): void
    {
        $command = new MongoModelCommand();
        $command->connection = 'mongo';

        $result = $command->getCollectionObject('Articles', 'articles');
        $this->assertInstanceOf(BaseCollection::class, $result);
        $this->assertSame('articles', $result->getCollection());
        $this->assertSame('Articles', $result->getAlias());
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

    /**
     * Test getting accessible fields.
     *
     * @return void
     */
    public function testGetFields(): void
    {
        $collection = new BaseCollection(['alias' => 'Products', 'collection' => 'products']);
        $schema = new CollectionSchema('products');
        $schema->addField('_id', ['type' => 'objectid']);
        $schema->addField('name', ['type' => 'string']);
        $schema->addField('price', ['type' => 'integer']);

        $collection->setSchema($schema);

        $command = new MongoModelCommand();
        $result = $command->getFields($collection, new Arguments([], [], []));
        $this->assertSame(['name', 'price'], $result);
    }

    /**
     * Test getting accessible fields with the no- option
     *
     * @return void
     */
    public function testGetFieldsDisabled(): void
    {
        $collection = new BaseCollection(['alias' => 'Products', 'collection' => 'products']);
        $args = new Arguments([], ['no-fields' => true], []);
        $command = new MongoModelCommand();
        $result = $command->getFields($collection, $args);
        $this->assertFalse($result);
    }

    /**
     * Test getting accessible fields with a whitelist
     *
     * @return void
     */
    public function testGetFieldsWhiteList(): void
    {
        $collection = new BaseCollection(['alias' => 'Products', 'collection' => 'products']);
        $schema = new CollectionSchema('products');
        $schema->addField('name', ['type' => 'string']);
        $schema->addField('price', ['type' => 'integer']);
        $schema->addField('category', ['type' => 'integer']);

        $collection->setSchema($schema);

        $args = new Arguments([], ['fields' => 'name, price  , , category'], []);
        $command = new MongoModelCommand();
        $result = $command->getFields($collection, $args);
        $this->assertEquals(['name', 'price', 'category'], $result);
    }

    /**
     * Test getting hidden fields.
     *
     * @return void
     */
    public function testGetHiddenFields(): void
    {
        $collection = new BaseCollection(['alias' => 'Users', 'collection' => 'users']);
        $schema = new CollectionSchema('users');
        $schema->addField('username', ['type' => 'string']);
        $schema->addField('password', ['type' => 'string']);

        $collection->setSchema($schema);

        $args = new Arguments([], [], []);
        $command = new MongoModelCommand();
        $result = $command->getHiddenFields($collection, $args);
        $this->assertEquals(['password'], $result);
    }

    /**
     * Test getting hidden field with the no- option
     *
     * @return void
     */
    public function testGetHiddenFieldsDisabled(): void
    {
        $collection = new BaseCollection(['alias' => 'Users', 'collection' => 'users']);
        $args = new Arguments([], ['no-hidden' => true], []);
        $command = new MongoModelCommand();
        $result = $command->getHiddenFields($collection, $args);
        $this->assertEquals([], $result);
    }

    /**
     * Test getting hidden field with a whitelist
     *
     * @return void
     */
    public function testGetHiddenFieldsWhiteList(): void
    {
        $collection = new BaseCollection(['alias' => 'Users', 'collection' => 'users']);
        $schema = new CollectionSchema('users');
        $schema->addField('username', ['type' => 'string']);
        $schema->addField('password', ['type' => 'string']);
        $schema->addField('token', ['type' => 'string']);

        $collection->setSchema($schema);

        $args = new Arguments([], ['hidden' => 'username, token'], []);
        $command = new MongoModelCommand();
        $result = $command->getHiddenFields($collection, $args);
        $this->assertEquals(['username', 'token'], $result);
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
     * test getting validation rules with the no-validation rule.
     *
     * @return void
     */
    public function testGetValidationDisabled(): void
    {
        $collection = new BaseCollection(['alias' => 'Products', 'collection' => 'products']);
        $command = new MongoModelCommand();
        $args = new Arguments([], ['no-validation' => true], []);
        $result = $command->getValidation($collection, [], $args);
        $this->assertEquals([], $result);
    }

    /**
     * test getting validation rules.
     *
     * @return void
     */
    public function testGetValidation(): void
    {
        $collection = new BaseCollection(['alias' => 'Products', 'collection' => 'products']);
        $schema = new CollectionSchema('products');
        $schema->addField('_id', ['type' => 'objectid']);
        $schema->addField('name', ['type' => 'string']);
        $schema->addField('price', ['type' => 'integer', 'null' => true]);

        $collection->setSchema($schema);

        $command = new MongoModelCommand();
        $args = new Arguments([], [], []);
        $result = $command->getValidation($collection, [], $args);

        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('scalar', $result['name']);
        $this->assertArrayHasKey('requirePresence', $result['name']);
        $this->assertArrayHasKey('notEmpty', $result['name']);

        $this->assertArrayHasKey('price', $result);
        $this->assertArrayHasKey('integer', $result['price']);
        $this->assertArrayHasKey('allowEmpty', $result['price']);
    }

    /**
     * test getting rules checker with the no-rules param.
     *
     * @return void
     */
    public function testGetRulesDisabled(): void
    {
        $collection = new BaseCollection(['alias' => 'Users', 'collection' => 'users']);
        $command = new MongoModelCommand();
        $args = new Arguments([], ['no-rules' => true], []);
        $result = $command->getRules($collection, [], $args);
        $this->assertEquals([], $result);
    }

    /**
     * Tests the getRules method
     *
     * @return void
     */
    public function testGetRules(): void
    {
        $collection = new BaseCollection(['alias' => 'Users', 'collection' => 'users']);
        $schema = new CollectionSchema('users');
        $schema->addField('_id', ['type' => 'objectid']);
        $schema->addField('username', ['type' => 'string']);
        $schema->addField('country_id', ['type' => 'objectid']);
        $schema->addIndex('users_username', ['key' => ['username' => 1], 'unique' => true]);

        $collection->setSchema($schema);

        $associations = [
            'belongsTo' => [
                ['alias' => 'Countries', 'foreignKey' => 'country_id'],
            ],
        ];
        $command = new MongoModelCommand();
        $args = new Arguments([], [], []);
        $result = $command->getRules($collection, $associations, $args);
        $expected = [
            [
                'name' => 'isUnique',
                'fields' => ['username'],
                'options' => [],
            ],
            [
                'name' => 'existsIn',
                'fields' => ['country_id'],
                'extra' => 'Countries',
                'options' => [],
            ],
        ];
        $this->assertEquals($expected, $result);
    }

    /**
     * Tests the getRules with unique keys.
     *
     * @return void
     */
    public function testGetRulesUniqueKeys(): void
    {
        $collection = new BaseCollection(['alias' => 'Users', 'collection' => 'users']);
        $schema = new CollectionSchema('users');
        $schema->addField('_id', ['type' => 'objectid']);
        $schema->addField('title', ['type' => 'string']);
        $schema->addField('user_id', ['type' => 'objectid']);
        $schema->addIndex('unique_title', ['key' => ['title' => 1], 'unique' => true]);
        $schema->addIndex('unique_composite', ['key' => ['title' => 1, 'user_id' => 1], 'unique' => true]);

        $collection->setSchema($schema);

        $command = new MongoModelCommand();
        $args = new Arguments([], [], []);
        $result = $command->getRules($collection, [], $args);
        $expected = [
            [
                'name' => 'isUnique',
                'fields' => ['title'],
                'options' => [],
            ],
            [
                'name' => 'isUnique',
                'fields' => ['title', 'user_id'],
                'options' => [],
                'message' => 'This combination of title and user_id already exists',
            ],
        ];
        $this->assertEquals($expected, $result);
    }

    /**
     * Test that specific behaviors are auto-detected
     *
     * @return void
     */
    public function testGetBehaviorsAutoDetect(): void
    {
        $command = new MongoModelCommand();

        $collection = new BaseCollection(['alias' => 'Posts', 'collection' => 'posts']);
        $schema = new CollectionSchema('posts');
        $schema->addField('created', ['type' => 'date']);

        $collection->setSchema($schema);
        $result = $command->getBehaviors($collection);
        $this->assertEquals(['Timestamp' => []], $result);

        $collection = new BaseCollection(['alias' => 'Tags', 'collection' => 'tags']);
        $schema = new CollectionSchema('tags');
        $schema->addField('name', ['type' => 'string']);

        $collection->setSchema($schema);
        $result = $command->getBehaviors($collection);
        $this->assertEquals([], $result);
    }

    /**
     * Test applying associations.
     *
     * @return void
     */
    public function testApplyAssociations(): void
    {
        $collection = new BaseCollection(['alias' => 'Articles', 'collection' => 'articles']);
        $assocs = [
            'belongsTo' => [
                [
                    'alias' => 'Users',
                    'foreignKey' => 'user_id',
                ],
            ],
            'hasMany' => [
                [
                    'alias' => 'Comments',
                    'foreignKey' => 'article_id',
                ],
            ],
        ];
        $original = $collection->associations()->keys();
        $this->assertEquals([], $original);

        $command = new MongoModelCommand();
        $command->applyAssociations($collection, $assocs);

        $new = $collection->associations()->keys();
        $expected = ['Users', 'Comments'];
        $this->assertEquals($expected, $new);
    }

    /**
     * Test getAssociations with off flag.
     *
     * @return void
     */
    public function testGetAssociationsNoFlag(): void
    {
        $command = new MongoModelCommand();
        $command->connection = 'mongo';

        $arguments = new Arguments([], ['no-associations' => true], []);
        $io = new ConsoleIo(new StubConsoleOutput(), new StubConsoleOutput(), new StubConsoleInput([]));
        $collection = new BaseCollection(['alias' => 'Articles', 'collection' => 'articles']);
        $this->assertEquals([], $command->getAssociations($collection, 'articles', $arguments, $io));
    }

    /**
     * Test that association detection finds a belongsTo relation on an
     * objectId foreign key pointing at an existing collection.
     *
     * @return void
     */
    public function testFindBelongsTo(): void
    {
        $command = new MongoModelCommand();
        $command->connection = 'mongo';

        $model = new BaseCollection(['alias' => 'Comments', 'collection' => 'comments']);
        $result = $command->findBelongsTo($model, []);

        $this->assertNotEmpty($result['belongsTo']);
        $aliases = array_column($result['belongsTo'], 'alias');
        $this->assertContains('Article', $aliases);
        $this->assertContains('User', $aliases);
    }

    /**
     * Test isPossibleBelongsToManyRelation.
     *
     * @return void
     */
    public function testIsPossibleBelongsToManyRelation(): void
    {
        $command = new MongoModelCommand();
        $command->connection = 'mongo';

        $this->assertTrue($command->isPossibleBelongsToManyRelation('articles', 'articles_tags'));
        $this->assertFalse($command->isPossibleBelongsToManyRelation('articles', 'comments'));
    }

    /**
     * Test baking a full model (document + collection) from the live schema.
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
     * Ensure that the fixture baking can be disabled
     *
     * @return void
     */
    public function testBakeFixtureDisabled(): void
    {
        $this->generatedFiles = [
            APP . 'Model/Document/Product.php',
            APP . 'Model/Collection/ProductsCollection.php',
        ];
        $this->exec('bake mongo_model Products --no-test --no-fixture --connection mongo');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFilesExist($this->generatedFiles);
        $this->assertFileDoesNotExist(ROOT . 'tests' . DS . 'Fixture' . DS . 'ProductsFixture.php');
    }
}
