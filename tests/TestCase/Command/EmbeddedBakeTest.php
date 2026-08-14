<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Command;

use Cake\Console\CommandInterface;
use Crustum\Mongo\Bake\MongoCollectionContext;
use Crustum\Mongo\Database\Schema\CollectionSchema;
use Crustum\Mongo\Database\Schema\Validator;
use Crustum\Mongo\Migration\Util\SchemaFields;
use Crustum\Mongo\ODM\Association\EmbedMany;
use Crustum\Mongo\ODM\Attribute\Embedded;
use Crustum\Mongo\ODM\BaseCollection;
use TestApp\Model\Document\Article;
use TestApp\Model\Document\BakeEmbeddedAddress;

/**
 * Embedded bake tests.
 *
 * Covers `#[Embedded]` attribute reading and the schema → embedded detection
 * (doc 36): `array` + `items.properties` → embedMany, `object` + `properties`
 * → embedOne, plain arrays/hashes stay `TYPE_COLLECTION`/`TYPE_HASH`.
 */
class EmbeddedBakeTest extends TestCase
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
     * Test that a class without #[Embedded] reads as not embedded.
     *
     * @return void
     */
    public function testReadNotEmbedded(): void
    {
        $this->assertNull(Embedded::read(Article::class));
    }

    /**
     * Test that an embedded Document class reads its many/key.
     *
     * @return void
     */
    public function testReadEmbedded(): void
    {
        $this->assertSame([
            'embedded' => true,
            'many' => true,
            'key' => 'addresses',
        ], Embedded::read(BakeEmbeddedAddress::class));
    }

    /**
     * Test that an array of objects is detected as embedMany.
     *
     * @return void
     */
    public function testSchemaDetectsEmbedMany(): void
    {
        $schema = [
            'users' => [
                'validator' => ['$jsonSchema' => [
                    'properties' => [
                        'addresses' => [
                            'bsonType' => 'array',
                            'items' => [
                                'bsonType' => 'object',
                                'properties' => [
                                    'street' => ['bsonType' => 'string'],
                                    'city' => ['bsonType' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ]],
            ],
        ];

        $fields = SchemaFields::fromSchema($schema, 'users');
        $this->assertArrayHasKey('addresses', $fields);
        $this->assertTrue($fields['addresses']['embedded']['many']);
        $this->assertSame('addresses', $fields['addresses']['embedded']['key']);
        $this->assertCount(2, $fields['addresses']['embedded']['fields']);
        $this->assertSame('street', $fields['addresses']['embedded']['fields'][0]['name']);
        $this->assertSame('string', $fields['addresses']['embedded']['fields'][0]['type']);
    }

    /**
     * Test that an object with properties is detected as embedOne.
     *
     * @return void
     */
    public function testSchemaDetectsEmbedOne(): void
    {
        $schema = [
            'users' => [
                'validator' => ['$jsonSchema' => [
                    'properties' => [
                        'profile' => [
                            'bsonType' => 'object',
                            'properties' => [
                                'name' => ['bsonType' => 'string'],
                                'age' => ['bsonType' => 'int'],
                            ],
                        ],
                    ],
                ]],
            ],
        ];

        $fields = SchemaFields::fromSchema($schema, 'users');
        $this->assertArrayHasKey('profile', $fields);
        $this->assertFalse($fields['profile']['embedded']['many']);
        $this->assertSame('profile', $fields['profile']['embedded']['key']);
        $this->assertSame('integer', $fields['profile']['embedded']['fields'][1]['type']);
    }

    /**
     * Test that a plain array (no items.properties) stays a collection field.
     *
     * @return void
     */
    public function testSchemaPlainArrayStaysCollection(): void
    {
        $schema = [
            'users' => [
                'validator' => ['$jsonSchema' => [
                    'properties' => [
                        'tags' => ['bsonType' => 'array'],
                    ],
                ]],
            ],
        ];

        $fields = SchemaFields::fromSchema($schema, 'users');
        $this->assertArrayNotHasKey('embedded', $fields['tags']);
        $this->assertSame('collection', SchemaFields::typeName('array'));
    }

    /**
     * Test that a plain hash (no properties) stays a hash field.
     *
     * @return void
     */
    public function testSchemaPlainHashStaysHash(): void
    {
        $schema = [
            'users' => [
                'validator' => ['$jsonSchema' => [
                    'properties' => [
                        'metadata' => ['bsonType' => 'object'],
                    ],
                ]],
            ],
        ];

        $fields = SchemaFields::fromSchema($schema, 'users');
        $this->assertArrayNotHasKey('embedded', $fields['metadata']);
        $this->assertSame('hash', SchemaFields::typeName('object'));
    }

    /**
     * Test baking a document with an embedded field from a lock file emits
     * #[Embedded] and the embedded document class.
     *
     * @return void
     */
    public function testBakeDocumentEmbedMany(): void
    {
        $lock = ROOT . DS . 'tests' . DS . 'comparisons' . DS . 'Command' . DS . 'schema-embedded.lock';
        $this->assertFileExists($lock);

        $this->generatedFiles = [
            APP . 'Model/Document/BakeUser.php',
            APP . 'Model/Document/BakeUserAddress.php',
        ];
        $this->exec('bake document BakeUser --connection mongo --schema-file ' . $lock);

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFilesExist($this->generatedFiles);

        $parent = file_get_contents($this->generatedFiles[0]);
        $this->assertStringContainsString("#[Embedded(many: true, key: 'addresses')]", $parent);
        $this->assertStringNotContainsString("#[Field(name: 'addresses'", $parent);

        $embedded = file_get_contents($this->generatedFiles[1]);
        $this->assertStringContainsString("#[Embedded(many: true, key: 'addresses')]", $embedded);
        $this->assertStringContainsString('class BakeUserAddress extends Document', $embedded);
        $this->assertStringContainsString("#[Field(name: 'street', type: CollectionSchemaInterface::TYPE_STRING)]", $embedded);
    }

    /**
     * Test that Collection::embedMany() derives localKey from #[Embedded].
     *
     * @return void
     */
    public function testEmbedManyDerivesLocalKeyFromAttribute(): void
    {
        $collection = new BaseCollection(['alias' => 'BakeUsers', 'collection' => 'bake_users']);
        $association = $collection->embedMany('addresses', [
            'documentClass' => BakeEmbeddedAddress::class,
        ]);

        $this->assertInstanceOf(EmbedMany::class, $association);
        $this->assertSame('addresses', $association->getLocalKey());
    }

    /**
     * Test that the collection bake context detects embedded associations and
     * resolves the embedded document class FQN.
     *
     * @return void
     */
    public function testCollectionContextDetectsEmbedded(): void
    {
        $collection = new BaseCollection(['alias' => 'BakeUsers', 'collection' => 'bake_users']);
        $schema = new CollectionSchema('bake_users');
        $schema->setValidator(new Validator([
            '$jsonSchema' => [
                'properties' => [
                    'username' => ['bsonType' => 'string'],
                    'addresses' => [
                        'bsonType' => 'array',
                        'items' => [
                            'bsonType' => 'object',
                            'properties' => [
                                'street' => ['bsonType' => 'string'],
                                'city' => ['bsonType' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
        ]));
        $collection->setSchema($schema);

        $context = new MongoCollectionContext();
        $data = $context->build($collection);

        $this->assertCount(1, $data['embedded']);
        $this->assertSame('addresses', $data['embedded'][0]['property']);
        $this->assertSame('EmbedMany', $data['embedded'][0]['type']);
        $this->assertSame('Address', $data['embedded'][0]['documentClass']);
        $this->assertSame(
            'TestApp\Model\Document\Address',
            $data['embedded'][0]['documentClassFqn'],
        );
        $this->assertContains('TestApp\Model\Document\Address', $data['embeddedImports']);
    }
}
