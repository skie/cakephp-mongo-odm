<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use Cake\Datasource\Exception\MissingPropertyException;
use Crustum\Mongo\ODM\Document;
use Exception;
use InvalidArgumentException;
use Mockery;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;
use TestApp\Model\Document\Extending;
use TestApp\Model\Document\NonExtending;
use TestApp\Model\Document\VirtualUser;

/**
 * Document test case.
 */
#[CoversClass(Document::class)]
class DocumentTest extends TestCase
{
    public function testIdAndNewState(): void
    {
        $id = new ObjectId();
        $document = new Document(['_id' => $id, 'name' => 'one']);

        $this->assertSame((string)$id, $document->getId());
        // A document constructed with an `_id` is still new until it is
        // persisted or explicitly marked not-new (cake60 Entity semantics).
        $this->assertTrue($document->isNew());

        $new = new Document();
        $this->assertTrue($new->isNew());
        $new->setId((string)$id);
        $new->setNew(false);
        $this->assertFalse($new->isNew());
    }

    public function testBsonConstructionAndExport(): void
    {
        $id = new ObjectId();
        $document = new Document(new BSONDocument([
            '_id' => $id,
            'when' => new UTCDateTime(1704067200000),
            'amount' => new Decimal128('12.50'),
            'nested' => new BSONDocument(['value' => $id]),
        ]));

        $data = $document->toArray();
        $this->assertSame((string)$id, $data['_id']);
        $this->assertSame('2024-01-01 00:00:00', $data['when']);
        $this->assertSame('12.50', $data['amount']);
        $this->assertSame((string)$id, $data['nested']['value']);
    }

    public function testDirtyOriginalVirtualAndHiddenStateUsesEntityTrait(): void
    {
        $document = new Document(['name' => 'one']);
        $document->clean();
        $document->setHidden(['name']);
        $document->setVirtual(['display']);
        $document->set('display', 'ONE');

        $this->assertTrue($document->isDirty('display'));
        $this->assertSame(['name'], $document->getOriginalFields());
        $this->assertSame(['display' => 'ONE'], $document->toArray());
    }

    /**
     * Tests setting a single property in an entity without custom setters
     */
    public function testSetOneParamNoSetters(): void
    {
        $document = new Document();

        $this->assertNull($document->getOriginal('foo'));
        $document->set('foo', 'bar', ['asOriginal' => true]);
        $this->assertSame('bar', $document->foo);
        $this->assertSame('bar', $document->getOriginal('foo'));

        $document->set('foo', 'baz');
        $this->assertSame('baz', $document->foo);
        $this->assertSame('bar', $document->getOriginal('foo'));

        $document->set('id', 1, ['asOriginal' => true]);
        $this->assertSame(1, $document->id);
        $this->assertSame(1, $document->getOriginal('id'));
        $this->assertSame('bar', $document->getOriginal('foo'));
    }

    /**
     * Tests setting multiple properties without custom setters
     */
    public function testPatchPropertiesNoSetters(): void
    {
        $document = new Document();
        $document->setAccess('*', true);

        $document->patch(['foo' => 'bar', 'id' => 1], ['asOriginal' => true]);
        $this->assertSame('bar', $document->foo);
        $this->assertSame(1, $document->id);

        $document->patch(['foo' => 'baz', 'id' => 2, 'thing' => 3]);
        $this->assertSame('baz', $document->foo);
        $this->assertSame(2, $document->id);
        $this->assertSame(3, $document->thing);
        $this->assertSame('bar', $document->getOriginal('foo'));
        $this->assertSame(1, $document->getOriginal('id'));

        $document->patch(['foo', 'bar']);
        $this->assertSame('foo', $document->get('0'));
        $this->assertSame('bar', $document->get('1'));

        $document->patch(['sample']);
        $this->assertSame('sample', $document->get('0'));
    }

    public function testEntitySetException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot set an empty field');

        $document = new Document();
        $document->set('', 'value');
    }

    /**
     * Test that getOriginal() retains falsey values.
     */
    public function testGetOriginal(): void
    {
        $document = new Document(
            ['false' => false, 'null' => null, 'zero' => 0, 'empty' => ''],
            ['markNew' => true],
        );
        $this->assertNull($document->getOriginal('null'));
        $this->assertFalse($document->getOriginal('false'));
        $this->assertSame(0, $document->getOriginal('zero'));
        $this->assertSame('', $document->getOriginal('empty'));

        $document->patch(['false' => 'y', 'null' => 'y', 'zero' => 'y', 'empty' => '']);
        $this->assertNull($document->getOriginal('null'));
        $this->assertFalse($document->getOriginal('false'));
        $this->assertSame(0, $document->getOriginal('zero'));
        $this->assertSame('', $document->getOriginal('empty'));
    }

    /**
     * Test that getOriginal throws an exception for fields without original value
     * when called with second parameter "false"
     */
    public function testGetOriginalFallback(): void
    {
        $document = new Document(
            ['foo' => 'foo', 'bar' => 'bar'],
            ['markNew' => true],
        );
        $this->assertNull($document->getOriginal('baz', true));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot retrieve original value for field `baz`');
        $document->getOriginal('baz', false);
    }

    /**
     * Test extractOriginal()
     */
    public function testExtractOriginal(): void
    {
        $document = new Document([
            'id' => 1,
            'title' => 'original',
            'body' => 'no',
            'null' => null,
        ], ['markNew' => true]);
        $document->set('body', 'updated body');

        $result = $document->extractOriginal(['id', 'title', 'body', 'null', 'undefined']);
        $expected = [
            'id' => 1,
            'title' => 'original',
            'body' => 'no',
            'null' => null,
        ];
        $this->assertEquals($expected, $result);

        $result = $document->extractOriginalChanged(['id', 'title', 'body', 'null', 'undefined']);
        $expected = [
            'body' => 'no',
        ];
        $this->assertEquals($expected, $result);

        $document->set('null', 'not null');
        $result = $document->extractOriginalChanged(['id', 'title', 'body', 'null', 'undefined']);
        $expected = [
            'null' => null,
            'body' => 'no',
        ];
        $this->assertEquals($expected, $result);
    }

    /**
     * Test that all original values are returned properly
     */
    public function testExtractOriginalValues(): void
    {
        $document = new Document([
            'id' => 1,
            'title' => 'original',
            'body' => 'no',
            'null' => null,
        ], ['markNew' => true]);
        $document->set('body', 'updated body');

        $result = $document->getOriginalValues();
        $expected = [
            'id' => 1,
            'title' => 'original',
            'body' => 'no',
            'null' => null,
        ];
        $this->assertEquals($expected, $result);
    }

    /**
     * Tests setting a single property using a setter function
     */
    public function testSetOneParamWithSetter(): void
    {
        $document = new class extends Document {
            protected function _setName(?string $name): string
            {
                return 'Dr. ' . $name;
            }
        };
        $document->set('name', 'Jones');
        $this->assertSame('Dr. Jones', $document->name);
    }

    /**
     * Tests setting multiple properties using a setter function
     */
    public function testMultipleWithSetter(): void
    {
        $document = new class extends Document {
            protected function _setName(?string $name): string
            {
                return 'Dr. ' . $name;
            }

            protected function _setStuff(?array $stuff): array
            {
                return ['c', 'd'];
            }
        };
        $document->setAccess('*', true);
        $document->patch(['name' => 'Jones', 'stuff' => ['a', 'b']]);
        $this->assertSame('Dr. Jones', $document->name);
        $this->assertEquals(['c', 'd'], $document->stuff);
    }

    /**
     * Tests that it is possible to bypass the setters
     */
    public function testBypassSetters(): void
    {
        $document = new class extends Document {
            protected function _setName(?string $name): string
            {
                throw new Exception('_setName should not have been called');
            }

            protected function _setStuff(?array $stuff): array
            {
                throw new Exception('_setStuff should not have been called');
            }
        };
        $document->setAccess('*', true);

        $document->set('name', 'Jones', ['setter' => false]);
        $this->assertSame('Jones', $document->name);

        $document->set('stuff', 'Thing', ['setter' => false]);
        $this->assertSame('Thing', $document->stuff);

        $document->patch(['name' => 'foo', 'stuff' => 'bar'], ['setter' => false]);
        $this->assertSame('bar', $document->stuff);
    }

    /**
     * Tests that the constructor will set initial properties
     */
    public function testConstructor(): void
    {
        $document = Mockery::mock(Document::class)->makePartial();

        $document
            ->shouldReceive('patch')
            ->with(['a' => 'b', 'c' => 'd'], ['setter' => true, 'guard' => false, 'asOriginal' => true])
            ->once();

        $document->shouldReceive('patch')
            ->with(['foo' => 'bar'], ['setter' => false, 'guard' => false, 'asOriginal' => true])
            ->once();

        $document->__construct(['a' => 'b', 'c' => 'd']);
        $document->__construct(['foo' => 'bar'], ['useSetters' => false]);
    }

    /**
     * Tests that the constructor will set initial properties and pass the guard
     * option along
     */
    public function testConstructorWithGuard(): void
    {
        $document = Mockery::mock(Document::class)->makePartial();

        $document
            ->shouldReceive('patch')
            ->with(['foo' => 'bar'], ['setter' => true, 'guard' => true, 'asOriginal' => true])
            ->once();

        $document->__construct(['foo' => 'bar'], ['guard' => true]);
    }

    /**
     * Tests getting properties with no custom getters
     */
    public function testGetNoGetters(): void
    {
        $document = new Document(['id' => 1, 'foo' => 'bar']);
        $this->assertSame(1, $document->get('id'));
        $this->assertSame('bar', $document->get('foo'));
    }

    public function testRequirePresenceException(): void
    {
        $this->expectException(MissingPropertyException::class);
        $this->expectExceptionMessage('Property `not_present` does not exist for the entity `Crustum\Mongo\ODM\Document`');

        $document = new Document();
        $document->requireFieldPresence();
        $document->{'not_present'};
    }

    public function testGetOrFailException(): void
    {
        $this->expectException(MissingPropertyException::class);
        $this->expectExceptionMessage('Property `not_present` does not exist for the entity `Crustum\Mongo\ODM\Document`');

        $document = new Document();
        $document->getRequiredOrFail('not_present');
    }

    /**
     * Test to ensure that requireFieldPresence does not affect get
     */
    public function testGetNoException(): void
    {
        $document = new Document();
        $document->requireFieldPresence();
        $this->assertNull($document->get('not_present'));
    }

    public function testRequirePresenceNoException(): void
    {
        $document = new Document(['is_present' => null]);
        $document->requireFieldPresence();
        $this->assertNull($document->get('is_present'));

        $document = new VirtualUser();
        $document->requireFieldPresence();
        $this->assertSame('bonus', $document->get('bonus'));
    }

    /**
     * Tests get with custom getter
     */
    public function testGetCustomGetters(): void
    {
        $document = new class extends Document {
            protected function _getName(string $name): string
            {
                return 'Dr. ' . $name;
            }
        };
        $document->set('name', 'Jones');
        $this->assertSame('Dr. Jones', $document->get('name'));
        $this->assertSame('Dr. Jones', $document->get('name'));
    }

    /**
     * Tests get with custom getter
     */
    public function testGetCustomGettersAfterSet(): void
    {
        $document = new class extends Document {
            protected function _getName(string $name): string
            {
                return 'Dr. ' . $name;
            }
        };
        $document->set('name', 'Jones');
        $this->assertSame('Dr. Jones', $document->get('name'));
        $this->assertSame('Dr. Jones', $document->get('name'));

        $document->set('name', 'Mark');
        $this->assertSame('Dr. Mark', $document->get('name'));
        $this->assertSame('Dr. Mark', $document->get('name'));
    }

    /**
     * Tests that the get cache is cleared by unset.
     */
    public function testGetCacheClearedByUnset(): void
    {
        $document = new class extends Document {
            protected function _getName(?string $name): string
            {
                return 'Dr. ' . $name;
            }
        };
        $document->set('name', 'Jones');
        $this->assertSame('Dr. Jones', $document->get('name'));

        $document->unset('name');
        $this->assertSame('Dr. ', $document->get('name'));
    }

    /**
     * Test getting camelcased virtual fields.
     */
    public function testGetCamelCasedProperties(): void
    {
        $document = new class extends Document {
            protected function _getListIdName(): string
            {
                return 'A name';
            }
        };
        $document->setVirtual(['ListIdName']);
        $this->assertSame('A name', $document->list_id_name, 'underscored virtual field should be accessible');
        $this->assertSame('A name', $document->listIdName, 'Camelbacked virtual field should be accessible');
    }

    /**
     * Test magic property setting with no custom setter
     */
    public function testMagicSet(): void
    {
        $document = new Document();
        $document->name = 'Jones';
        $this->assertSame('Jones', $document->name);
        $document->name = 'George';
        $this->assertSame('George', $document->name);
    }

    /**
     * Tests magic set with custom setter function
     */
    public function testMagicSetWithSetter(): void
    {
        $document = new class extends Document {
            protected function _setName(?string $name): string
            {
                return 'Dr. ' . $name;
            }
        };
        $document->name = 'Jones';
        $this->assertSame('Dr. Jones', $document->name);
    }

    /**
     * Tests magic set with custom setter function using a Title cased property
     */
    public function testMagicSetWithSetterTitleCase(): void
    {
        $document = new class extends Document {
            protected function _setName(?string $name): string
            {
                return 'Dr. ' . $name;
            }
        };
        $document->Name = 'Jones';
        $this->assertSame('Dr. Jones', $document->Name);
    }

    /**
     * Tests the magic getter with a custom getter function
     */
    public function testMagicGetWithGetter(): void
    {
        $document = new class extends Document {
            protected function _getName(string $name): string
            {
                return 'Dr. ' . $name;
            }
        };
        $document->set('name', 'Jones');
        $this->assertSame('Dr. Jones', $document->name);
    }

    /**
     * Tests magic get with custom getter function using a Title cased property
     */
    public function testMagicGetWithGetterTitleCase(): void
    {
        $document = new class extends Document {
            protected function _getName(string $name): string
            {
                return 'Dr. ' . $name;
            }
        };
        $document->set('Name', 'Jones');
        $this->assertSame('Dr. Jones', $document->Name);
    }

    /**
     * Test indirectly modifying internal properties
     */
    public function testIndirectModification(): void
    {
        $document = new Document();
        $document->things = ['a', 'b'];
        $document->things[] = 'c';
        $this->assertEquals(['a', 'b', 'c'], $document->things);
    }

    /**
     * Tests has() method
     */
    public function testHas(): void
    {
        $document = new Document(['id' => 1]);
        $document->name = 'Juan';
        $document->foo = null;
        $this->assertTrue($document->has('id'));
        $this->assertTrue($document->has('name'));
        $this->assertTrue($document->has('foo'));
        $this->assertFalse($document->has('last_name'));

        $this->assertTrue($document->has(['id']));
        $this->assertTrue($document->has(['id', 'name']));
        $this->assertTrue($document->has(['id', 'foo']));
        $this->assertFalse($document->has(['id', 'nope']));

        $document = new class extends Document {
            protected function _getThings(): never
            {
                throw new Exception('_getThings() should not have been called');
            }
        };
        $this->assertTrue($document->has('things'));
    }

    /**
     * Tests unset one property at a time
     */
    public function testUnset(): void
    {
        $document = new Document(['id' => 1, 'name' => 'bar']);
        $document->unset('id');
        $this->assertFalse($document->has('id'));
        $this->assertTrue($document->has('name'));
        $document->unset('name');
        $this->assertFalse($document->has('id'));
    }

    /**
     * Unsetting a property should not mark it as dirty.
     */
    public function testUnsetMakesClean(): void
    {
        $document = new Document(['id' => 1, 'name' => 'bar']);
        $this->assertTrue($document->isDirty('name'));
        $document->unset('name');
        $this->assertFalse($document->isDirty('name'), 'Removed properties are not dirty.');
    }

    /**
     * Tests unset with multiple properties
     */
    public function testUnsetMultiple(): void
    {
        $document = new Document(['id' => 1, 'name' => 'bar', 'thing' => 2]);
        $document->unset(['id', 'thing']);
        $this->assertFalse($document->has('id'));
        $this->assertTrue($document->has('name'));
        $this->assertFalse($document->has('thing'));
    }

    /**
     * Tests the magic __isset() method
     */
    public function testMagicIsset(): void
    {
        $document = new Document(['id' => 1, 'name' => 'Juan', 'foo' => null]);
        $this->assertTrue(isset($document->id));
        $this->assertTrue(isset($document->name));
        $this->assertFalse(isset($document->foo));
        $this->assertFalse(isset($document->thing));
    }

    /**
     * Tests the magic __unset() method
     */
    public function testMagicUnset(): void
    {
        $document = new Document(['foo' => 'bar']);

        unset($document->foo);

        $this->assertFalse($document->has('foo'));
    }

    /**
     * Tests isset with array access
     */
    public function testIssetArrayAccess(): void
    {
        $document = new Document(['id' => 1, 'name' => 'Juan', 'foo' => null]);
        $this->assertArrayHasKey('id', $document);
        $this->assertArrayHasKey('name', $document);
        $this->assertArrayNotHasKey('foo', $document);
        $this->assertArrayNotHasKey('thing', $document);
    }

    /**
     * Tests get property with array access
     */
    public function testGetArrayAccess(): void
    {
        $document = Mockery::spy(Document::class)->makePartial();

        $this->assertNull($document['foo']);
        $this->assertNull($document['bar']);

        $document->shouldHaveReceived('get')
            ->with('foo')
            ->once();

        $document->shouldHaveReceived('get')
            ->with('bar')
            ->once();
    }

    /**
     * Tests set with array access
     */
    public function testSetArrayAccess(): void
    {
        $document = Mockery::spy(Document::class)->makePartial();
        $document->setAccess('*', true);

        $document['foo'] = 1;
        $document['bar'] = 2;

        $document->shouldHaveReceived('set')
            ->with('foo', 1)
            ->once();

        $document->shouldHaveReceived('set')
            ->with('bar', 2)
            ->once();
    }

    /**
     * Tests unset with array access
     */
    public function testUnsetArrayAccess(): void
    {
        $document = new Document(['foo' => 'bar']);

        unset($document['foo']);

        $this->assertFalse($document->has('foo'));
    }

    /**
     * Tests that the method cache will only report the methods for the called class,
     * this is, calling methods defined in another entity will not cause a fatal error
     * when trying to call directly an inexistent method in another class
     */
    public function testMethodCache(): void
    {
        $document = new class extends Document {
            protected function _setFoo(?string $name): string
            {
                return 'Dr. ' . $name;
            }

            protected function _getBar(string $bar): string
            {
                return 'Dir. ' . $bar;
            }
        };
        $document2 = new class extends Document {
            protected function _setBar(?string $name): string
            {
                return 'DrDr. ' . $name;
            }
        };

        $document = $document->set('foo', 'Someone');
        $this->assertEquals('Dr. Someone', $document->get('foo'));
        $document2 = $document2->set('bar', 'Someone');
        $this->assertEquals('DrDr. Someone', $document2->get('bar'));
    }

    /**
     * Tests that long properties in the entity are inflected correctly
     */
    public function testSetGetLongPropertyNames(): void
    {
        $document = new class extends Document {
            protected function _setVeryLongProperty(?string $name): string
            {
                return 'Dr. ' . $name;
            }

            protected function _getVeryLongProperty(?string $veryLongProperty): string
            {
                return 'Dir. ' . $veryLongProperty;
            }
        };
        $this->assertEquals('Dir. ', $document->get('very_long_property'));
        $document->set('very_long_property', 'Someone');
        $this->assertEquals('Dir. Dr. Someone', $document->get('very_long_property'));
    }

    /**
     * Tests serializing an entity as JSON
     */
    public function testJsonSerialize(): void
    {
        $data = ['name' => 'James', 'age' => 20, 'phones' => ['123', '457']];
        $document = new Document($data);
        $this->assertEquals(json_encode($data), json_encode($document));
    }

    /**
     * Tests serializing an entity as PHP
     */
    public function testPhpSerialize(): void
    {
        $data = ['name' => 'James', 'age' => 20, 'phones' => ['123', '457']];
        $document = new Document($data);
        $copy = unserialize(serialize($document));
        $this->assertInstanceOf(Document::class, $copy);
        $this->assertEquals($data, $copy->toArray());
    }

    /**
     * Tests that jsonSerialize is called recursively for contained entities
     */
    public function testJsonSerializeRecursive(): void
    {
        $phone = new Document(['something' => true]);
        $data = ['name' => 'James', 'age' => 20, 'phone' => $phone];
        $document = new Document($data);
        $expected = ['name' => 'James', 'age' => 20, 'phone' => ['something' => true]];
        $this->assertEquals(json_encode($expected), json_encode($document));
    }

    /**
     * Tests the extract method
     */
    public function testExtract(): void
    {
        $document = new Document([
            'id' => 1,
            'title' => 'Foo',
            'author_id' => 3,
        ]);
        $expected = ['author_id' => 3, 'title' => 'Foo',];
        $this->assertEquals($expected, $document->extract(['author_id', 'title']));

        $expected = ['id' => 1];
        $this->assertEquals($expected, $document->extract(['id']));

        $expected = [];
        $this->assertEquals($expected, $document->extract([]));

        $expected = ['id' => 1, 'craziness' => null];
        $this->assertEquals($expected, $document->extract(['id', 'craziness']));
    }

    /**
     * Tests isDirty() method on a newly created object
     */
    public function testIsDirty(): void
    {
        $document = new Document([
            'id' => 1,
            'title' => 'Foo',
            'author_id' => 3,
        ]);
        $this->assertTrue($document->isDirty('id'));
        $this->assertTrue($document->isDirty('title'));
        $this->assertTrue($document->isDirty('author_id'));

        $this->assertTrue($document->isDirty());

        $document->setDirty('id', false);
        $this->assertFalse($document->isDirty('id'));
        $this->assertTrue($document->isDirty('title'));

        $document->setDirty('title', false);
        $this->assertFalse($document->isDirty('title'));
        $this->assertTrue($document->isDirty(), 'should be dirty, one field left');

        $document->setDirty('author_id', false);
        $this->assertFalse($document->isDirty(), 'all fields are clean.');
    }

    /**
     * Test setDirty().
     */
    public function testSetDirty(): void
    {
        $document = new Document([
            'id' => 1,
            'title' => 'Foo',
            'author_id' => 3,
        ], ['markClean' => true]);

        $this->assertFalse($document->isDirty());
        $this->assertSame($document, $document->setDirty('title'));
        $this->assertSame($document, $document->setDirty('id', false));

        $document->setErrors(['title' => ['badness']]);
        $document->setDirty('title', true);
        $this->assertEmpty($document->getErrors(), 'Making a field dirty clears errors.');
    }

    /**
     * Tests dirty() when altering properties values and adding new ones
     */
    public function testDirtyChangingProperties(): void
    {
        $document = new Document([
            'title' => 'Foo',
        ]);

        $document->setDirty('title', false);
        $this->assertFalse($document->isDirty('title'));

        $document->set('title', 'Foo');
        // Not dirty as the value set is the same as the existing value
        $this->assertFalse($document->isDirty('title'));

        $document->set('title', 'Bar');
        $this->assertTrue($document->isDirty('title'));

        $document->set('something', 'else');
        $this->assertTrue($document->isDirty('something'));
    }

    /**
     * Tests that setting an object value when existing field is scalar
     * correctly marks field as dirty without raising PHP notices.
     *
     * This tests the fix for a bug where isModified() compared an object
     * to a scalar using loose equality, causing PHP 8+ to raise notices
     * like "Object of class X could not be converted to float".
     */
    public function testDirtyObjectReplacingScalar(): void
    {
        $document = new Document([
            'amount' => 10.50,
        ], ['markClean' => true]);

        $this->assertFalse($document->isDirty('amount'));

        // Create an object that represents the same value but as object type.
        // In real usage this would be something like BigDecimal.
        $objectValue = new class (10.50) {
            public function __construct(private float $value)
            {
            }

            public function getValue(): float
            {
                return $this->value;
            }
        };

        // Setting object value when existing is scalar should:
        // 1. Not raise a PHP notice (object-to-scalar comparison)
        // 2. Mark the field as dirty (types differ)
        $document->set('amount', $objectValue);

        // Field should be dirty because we're changing from scalar to object
        $this->assertTrue($document->isDirty('amount'));
    }

    /**
     * Tests that setting an equivalent object when existing field is also an object
     * correctly detects no change (field remains clean).
     */
    public function testDirtyObjectReplacingEquivalentObject(): void
    {
        $objectValue = new stdClass();
        $objectValue->value = 10.50;

        $document = new Document([
            'amount' => $objectValue,
        ], ['markClean' => true]);

        $this->assertFalse($document->isDirty('amount'));

        // Create an equivalent object (same properties, same values)
        $equivalentObject = new stdClass();
        $equivalentObject->value = 10.50;

        // Setting equivalent object should NOT mark field dirty
        $document->set('amount', $equivalentObject);
        $this->assertFalse($document->isDirty('amount'));

        // Setting different object SHOULD mark field dirty
        $differentObject = new stdClass();
        $differentObject->value = 20.00;

        $document->set('amount', $differentObject);
        $this->assertTrue($document->isDirty('amount'));
    }

    /**
     * Tests extract only dirty properties
     */
    public function testExtractDirty(): void
    {
        $document = new Document([
            'id' => 1,
            'title' => 'Foo',
            'author_id' => 3,
        ]);
        $document->setDirty('id', false);
        $document->setDirty('title', false);

        $expected = ['author_id' => 3];
        $result = $document->extract(['id', 'title', 'author_id'], true);
        $this->assertEquals($expected, $result);
    }

    /**
     * Tests the getDirty method
     */
    public function testGetDirty(): void
    {
        $document = new Document([
            'id' => 1,
            'title' => 'Foo',
            'author_id' => 3,
        ]);

        $expected = [
            'id',
            'title',
            'author_id',
        ];
        $this->assertSame($expected, $document->getDirty());
    }

    /**
     * Tests the clean method
     */
    public function testClean(): void
    {
        $document = new Document([
            'id' => 1,
            'title' => 'Foo',
            'author_id' => 3,
        ]);
        $this->assertTrue($document->isDirty('id'));
        $this->assertTrue($document->isDirty('title'));
        $this->assertTrue($document->isDirty('author_id'));

        $document->clean();
        $this->assertFalse($document->isDirty('id'));
        $this->assertFalse($document->isDirty('title'));
        $this->assertFalse($document->isDirty('author_id'));
    }

    /**
     * Tests the isNew method
     */
    public function testIsNew(): void
    {
        $data = [
            'id' => 1,
            'title' => 'Foo',
            'author_id' => 3,
        ];
        $document = new Document($data);
        $this->assertTrue($document->isNew());

        $document->setNew(true);
        $this->assertTrue($document->isNew());

        $document->setNew(false);
        $this->assertFalse($document->isNew());
    }

    /**
     * Tests the constructor when passing the markClean option
     */
    public function testConstructorWithClean(): void
    {
        $document = new Document(['a' => 'b', 'c' => 'd']);
        $this->assertTrue($document->isDirty('a'));
        $this->assertTrue($document->isDirty('c'));

        $document = new Document(['a' => 'b', 'c' => 'd'], ['markClean' => true]);
        $this->assertFalse($document->isDirty('a'));
        $this->assertFalse($document->isDirty('c'));
    }

    /**
     * Tests the constructor when passing the markClean option
     */
    public function testConstructorWithMarkNew(): void
    {
        $document = new Document(['a' => 'b', 'c' => 'd']);
        $this->assertTrue($document->isNew());

        $document = new Document(['a' => 'b', 'c' => 'd'], ['markNew' => false]);
        $this->assertFalse($document->isNew());

        $document = new Document(['a' => 'b', 'c' => 'd'], ['markNew' => true]);
        $this->assertTrue($document->isNew());
    }

    /**
     * Test toArray method.
     */
    public function testToArray(): void
    {
        $data = ['name' => 'James', 'age' => 20, 'phones' => ['123', '457']];
        $document = new Document($data);

        $this->assertEquals($data, $document->toArray());
    }

    /**
     * Test toArray recursive.
     */
    public function testToArrayRecursive(): void
    {
        $data = ['id' => 1, 'name' => 'James', 'age' => 20, 'phones' => ['123', '457']];
        $user = new Extending($data);
        $comments = [
            new NonExtending(['user_id' => 1, 'body' => 'Comment 1']),
            new NonExtending(['user_id' => 1, 'body' => 'Comment 2']),
        ];
        $user->comments = $comments;
        $user->profile = new Document(['email' => 'mark@example.com']);

        $expected = [
            'id' => 1,
            'name' => 'James',
            'age' => 20,
            'phones' => ['123', '457'],
            'profile' => ['email' => 'mark@example.com'],
            'comments' => [
                ['user_id' => 1, 'body' => 'Comment 1'],
                ['user_id' => 1, 'body' => 'Comment 2'],
            ],
        ];
        $this->assertEquals($expected, $user->toArray());
    }

    /**
     * Tests that an entity with entities and other misc types can be properly toArray'd
     */
    public function testToArrayMixed(): void
    {
        $test = new Document([
            'id' => 1,
            'foo' => [
                new Document(['hi' => 'test']),
                'notentity' => 1,
            ],
        ]);
        $expected = [
            'id' => 1,
            'foo' => [
                ['hi' => 'test'],
                'notentity' => 1,
            ],
        ];
        $this->assertEquals($expected, $test->toArray());
    }

    /**
     * Test that get accessors are called when converting to arrays.
     */
    public function testToArrayWithAccessor(): void
    {
        $document = new class extends Document {
            protected function _getName(?string $name): string
            {
                return 'Jose';
            }
        };
        $document->setAccess('*', true);
        $document->patch(['name' => 'Mark', 'email' => 'mark@example.com']);

        $expected = ['name' => 'Jose', 'email' => 'mark@example.com'];
        $this->assertEquals($expected, $document->toArray());
    }

    /**
     * Test that toArray respects hidden properties.
     */
    public function testToArrayHiddenProperties(): void
    {
        $data = ['secret' => 'sauce', 'name' => 'mark', 'id' => 1];
        $document = new Document($data);
        $document->setHidden(['secret']);
        $this->assertEquals(['name' => 'mark', 'id' => 1], $document->toArray());
    }

    /**
     * Tests setting hidden properties.
     */
    public function testSetHidden(): void
    {
        $data = ['secret' => 'sauce', 'name' => 'mark', 'id' => 1];
        $document = new Document($data);
        $document->setHidden(['secret']);

        $result = $document->getHidden();
        $this->assertSame(['secret'], $result);

        $document->setHidden(['name']);

        $result = $document->getHidden();
        $this->assertSame(['name'], $result);
    }

    /**
     * Tests setting hidden properties with merging.
     */
    public function testSetHiddenWithMerge(): void
    {
        $data = ['secret' => 'sauce', 'name' => 'mark', 'id' => 1];
        $document = new Document($data);
        $document->setHidden(['secret'], true);

        $result = $document->getHidden();
        $this->assertSame(['secret'], $result);

        $document->setHidden(['name'], true);

        $result = $document->getHidden();
        $this->assertSame(['secret', 'name'], $result);

        $document->setHidden(['name'], true);
        $result = $document->getHidden();
        $this->assertSame(['secret', 'name'], $result);
    }

    /**
     * Test toArray includes 'virtual' properties.
     */
    public function testToArrayVirtualProperties(): void
    {
        $document = new class extends Document {
            protected function _getName(?string $name): string
            {
                return 'Jose';
            }
        };
        $document->setAccess('*', true);
        $document->patch(['email' => 'mark@example.com']);

        $document->setVirtual(['name']);

        $expected = ['name' => 'Jose', 'email' => 'mark@example.com'];
        $this->assertEquals($expected, $document->toArray());

        $this->assertEquals(['name'], $document->getVirtual());

        $document->setHidden(['name']);
        $expected = ['email' => 'mark@example.com'];
        $this->assertEquals($expected, $document->toArray());
        $this->assertEquals(['name'], $document->getHidden());
    }

    /**
     * Tests the getVisible() method
     */
    public function testGetVisible(): void
    {
        $document = new Document();
        $document->foo = 'foo';
        $document->bar = 'bar';

        $expected = $document->getVisible();
        $this->assertSame(['foo', 'bar'], $expected);
    }

    /**
     * Tests setting virtual properties with merging.
     */
    public function testSetVirtualWithMerge(): void
    {
        $data = ['virtual' => 'sauce', 'name' => 'mark', 'id' => 1];
        $document = new Document($data);
        $document->setVirtual(['virtual']);

        $result = $document->getVirtual();
        $this->assertSame(['virtual'], $result);

        $document->setVirtual(['name'], true);

        $result = $document->getVirtual();
        $this->assertSame(['virtual', 'name'], $result);

        $document->setVirtual(['name'], true);
        $result = $document->getVirtual();
        $this->assertSame(['virtual', 'name'], $result);
    }

    /**
     * Tests error getters and setters
     */
    public function testGetErrorAndSetError(): void
    {
        $document = new Document();
        $this->assertEmpty($document->getErrors());

        $document->setError('foo', 'bar');
        $this->assertEquals(['bar'], $document->getError('foo'));

        $document->requireFieldPresence(true);
        $this->assertEquals([], $document->getError('non_existent'));

        $expected = [
            'foo' => ['bar'],
        ];
        $result = $document->getErrors();
        $this->assertEquals($expected, $result);

        $indexedErrors = [2 => ['foo' => 'bar']];
        $document = new Document();
        $document->setError('indexes', $indexedErrors);

        $expectedIndexed = [
            'indexes' => ['2' => ['foo' => 'bar']],
        ];
        $result = $document->getErrors();
        $this->assertEquals($expectedIndexed, $result);
    }

    /**
     * Tests that setError with dotted paths creates nested structure
     */
    public function testSetErrorDottedPath(): void
    {
        $document = new Document();
        $document->setError('patients._ids', ['dummyRule' => 'Error message']);

        // Should create nested structure that can be retrieved with dotted path
        $expected = ['dummyRule' => 'Error message'];
        $this->assertEquals($expected, $document->getError('patients._ids'));

        // Should also work with getErrors()
        $expected = [
            'patients' => [
                '_ids' => ['dummyRule' => 'Error message'],
            ],
        ];
        $this->assertEquals($expected, $document->getErrors());

        // Test deeper nesting
        $document = new Document();
        $document->setError('foo.bar.baz', 'deep error');

        $this->assertEquals(['deep error'], $document->getError('foo.bar.baz'));
        $expected = [
            'foo' => [
                'bar' => [
                    'baz' => ['deep error'],
                ],
            ],
        ];
        $this->assertEquals($expected, $document->getErrors());

        // Test with string error message
        $document = new Document();
        $document->setError('field.subfield', 'simple message');

        $this->assertEquals(['simple message'], $document->getError('field.subfield'));
    }

    /**
     * Tests reading errors from nested validator
     */
    public function testGetErrorNested(): void
    {
        $document = new Document();
        $document->setError('options', ['subpages' => ['_empty' => 'required']]);

        $expected = [
            'subpages' => ['_empty' => 'required'],
        ];
        $this->assertEquals($expected, $document->getError('options'));

        $expected = ['_empty' => 'required'];
        $this->assertEquals($expected, $document->getError('options.subpages'));
    }

    /**
     * Tests that it is possible to get errors for nested entities
     */
    public function testErrorsDeep(): void
    {
        $user = new Document();
        $owner = new NonExtending();
        $author = new Extending([
            'foo' => 'bar',
            'thing' => 'baz',
            'user' => $user,
            'owner' => $owner,
        ]);
        $author->setError('thing', ['this is a mistake']);

        $user->setErrors(['a' => ['error1'], 'b' => ['error2']]);
        $owner->setErrors(['c' => ['error3'], 'd' => ['error4']]);

        $expected = ['a' => ['error1'], 'b' => ['error2']];
        $this->assertEquals($expected, $author->getError('user'));

        $expected = ['c' => ['error3'], 'd' => ['error4']];
        $this->assertEquals($expected, $author->getError('owner'));

        $author->set('multiple', [$user, $owner]);
        $expected = [
            ['a' => ['error1'], 'b' => ['error2']],
            ['c' => ['error3'], 'd' => ['error4']],
        ];
        $this->assertEquals($expected, $author->getError('multiple'));

        $expected = [
            'thing' => $author->getError('thing'),
            'user' => $author->getError('user'),
            'owner' => $author->getError('owner'),
            'multiple' => $author->getError('multiple'),
        ];
        $this->assertEquals($expected, $author->getErrors());
    }

    /**
     * Tests that check if hasErrors() works
     */
    public function testHasErrors(): void
    {
        $document = new Document();
        $hasErrors = $document->hasErrors();
        $this->assertFalse($hasErrors);

        $nestedEntity = new Document();
        $document->patch([
            'nested' => $nestedEntity,
        ]);
        $hasErrors = $document->hasErrors();
        $this->assertFalse($hasErrors);

        $nestedEntity->setError('description', 'oops');
        $hasErrors = $document->hasErrors();
        $this->assertTrue($hasErrors);

        $hasErrors = $document->hasErrors(false);
        $this->assertFalse($hasErrors);

        $document->clean();
        $hasErrors = $document->hasErrors();
        $this->assertTrue($hasErrors);
        $hasErrors = $document->hasErrors(false);
        $this->assertFalse($hasErrors);

        $nestedEntity->clean();
        $hasErrors = $document->hasErrors();
        $this->assertFalse($hasErrors);

        $document->setError('foo', []);
        $this->assertFalse($document->hasErrors());
    }

    /**
     * Test that errors can be read with a path.
     */
    public function testErrorPathReading(): void
    {
        $assoc = new Document();
        $assoc2 = new NonExtending();
        $document = new Extending([
            'field' => 'value',
            'one' => $assoc,
            'many' => [$assoc2],
        ]);
        $document->setError('wrong', 'Bad stuff');

        $assoc->setError('nope', 'Terrible things');
        $assoc2->setError('nope', 'Terrible things');

        $this->assertEquals(['Bad stuff'], $document->getError('wrong'));
        $this->assertEquals(['Terrible things'], $document->getError('many.0.nope'));
        $this->assertEquals(['Terrible things'], $document->getError('one.nope'));
        $this->assertEquals(['nope' => ['Terrible things']], $document->getError('one'));
        $this->assertEquals([0 => ['nope' => ['Terrible things']]], $document->getError('many'));
        $this->assertEquals(['nope' => ['Terrible things']], $document->getError('many.0'));

        $this->assertEquals([], $document->getError('many.0.mistake'));
        $this->assertEquals([], $document->getError('one.mistake'));
        $this->assertEquals([], $document->getError('one.1.mistake'));
        $this->assertEquals([], $document->getError('many.1.nope'));
    }

    /**
     * Tests that changing the value of a property will remove errors
     * stored for it
     */
    public function testDirtyRemovesError(): void
    {
        $document = new Document(['a' => 'b']);
        $document->setError('a', 'is not good');
        $document->set('a', 'c');
        $this->assertEmpty($document->getError('a'));

        $document->setError('a', 'is not good');
        $document->setDirty('a', true);
        $this->assertEmpty($document->getError('a'));
    }

    /**
     * Tests that marking an entity as clean will remove errors too
     */
    public function testCleanRemovesErrors(): void
    {
        $document = new Document(['a' => 'b']);
        $document->setError('a', 'is not good');
        $document->clean();
        $this->assertEmpty($document->getErrors());
    }

    /**
     * Tests getAccessible() method
     */
    public function testGetPatchable(): void
    {
        $document = new Document();
        $document->setAccess('*', false);
        $document->setAccess('bar', true);

        $patchable = $document->getAccessible();
        $expected = [
            '*' => false,
            'bar' => true,
        ];
        $this->assertSame($expected, $patchable);
    }

    /**
     * Tests isAccessible() and setAccess() methods
     */
    public function testIsPatchable(): void
    {
        $document = new Document();
        $document->setAccess('*', false);
        $this->assertFalse($document->isAccessible('foo'));
        $this->assertFalse($document->isAccessible('bar'));

        $this->assertSame($document, $document->setAccess('foo', true));
        $this->assertTrue($document->isAccessible('foo'));
        $this->assertFalse($document->isAccessible('bar'));

        $this->assertSame($document, $document->setAccess('bar', true));
        $this->assertTrue($document->isAccessible('foo'));
        $this->assertTrue($document->isAccessible('bar'));

        $this->assertSame($document, $document->setAccess('foo', false));
        $this->assertFalse($document->isAccessible('foo'));
        $this->assertTrue($document->isAccessible('bar'));

        $this->assertSame($document, $document->setAccess('bar', false));
        $this->assertFalse($document->isAccessible('foo'));
        $this->assertFalse($document->isAccessible('bar'));
    }

    /**
     * Tests that an array can be used to set
     */
    public function testPatchableAsArray(): void
    {
        $document = new Document();
        $document->setAccess(['foo', 'bar', 'baz'], true);
        $this->assertTrue($document->isAccessible('foo'));
        $this->assertTrue($document->isAccessible('bar'));
        $this->assertTrue($document->isAccessible('baz'));

        $document->setAccess('foo', false);
        $this->assertFalse($document->isAccessible('foo'));
        $this->assertTrue($document->isAccessible('bar'));
        $this->assertTrue($document->isAccessible('baz'));

        $document->setAccess(['foo', 'bar', 'baz'], false);
        $this->assertFalse($document->isAccessible('foo'));
        $this->assertFalse($document->isAccessible('bar'));
        $this->assertFalse($document->isAccessible('baz'));
    }

    /**
     * Tests that a wildcard can be used for setting patchable properties
     */
    public function testPatchableWildcard(): void
    {
        $document = new Document();
        $document->setAccess(['foo', 'bar', 'baz'], true);
        $this->assertTrue($document->isAccessible('foo'));
        $this->assertTrue($document->isAccessible('bar'));
        $this->assertTrue($document->isAccessible('baz'));

        $document->setAccess('*', false);
        $this->assertFalse($document->isAccessible('foo'));
        $this->assertFalse($document->isAccessible('bar'));
        $this->assertFalse($document->isAccessible('baz'));
        $this->assertFalse($document->isAccessible('newOne'));

        $document->setAccess('*', true);
        $this->assertTrue($document->isAccessible('foo'));
        $this->assertTrue($document->isAccessible('bar'));
        $this->assertTrue($document->isAccessible('baz'));
        $this->assertTrue($document->isAccessible('newOne2'));
    }

    /**
     * Tests that only patchable properties can be set
     */
    public function testSetWithPatchable(): void
    {
        $document = new Document(['foo' => 1, 'bar' => 2]);
        $options = ['guard' => true];
        $document->setAccess('*', false);
        $document->setAccess('foo', true);
        $document->set('bar', 3, $options);
        $document->set('foo', 4, $options);
        $this->assertSame(2, $document->get('bar'));
        $this->assertSame(4, $document->get('foo'));

        $document->setAccess('bar', true);
        $document->set('bar', 3, $options);
        $this->assertSame(3, $document->get('bar'));
    }

    /**
     * Tests that only patchable properties can be set
     */
    public function testSetWithPatchableWithArray(): void
    {
        $document = new Document(['foo' => 1, 'bar' => 2]);
        $options = ['guard' => true];
        $document->setAccess('*', false);
        $document->setAccess('foo', true);
        $document->patch(['bar' => 3, 'foo' => 4], $options);
        $this->assertSame(2, $document->get('bar'));
        $this->assertSame(4, $document->get('foo'));

        $document->setAccess('bar', true);
        $document->patch(['bar' => 3, 'foo' => 5], $options);
        $this->assertSame(3, $document->get('bar'));
        $this->assertSame(5, $document->get('foo'));
    }

    /**
     * Test that patchable() and single property setting works.
     */
    public function testSetWithPatchableSingleProperty(): void
    {
        $document = new Document(['foo' => 1, 'bar' => 2]);
        $document->setAccess('*', false);
        $document->setAccess('title', true);

        $document->patch(['title' => 'test', 'body' => 'Nope']);
        $this->assertSame('test', $document->title);
        $this->assertNull($document->body);

        $document->body = 'Yep';
        $this->assertSame('Yep', $document->body, 'Single set should bypass guards.');

        $document->set('body', 'Yes');
        $this->assertSame('Yes', $document->body, 'Single set should bypass guards.');
    }

    /**
     * Tests __debugInfo
     */
    public function testDebugInfo(): void
    {
        $document = new Document(['foo' => 'bar'], ['markClean' => true]);
        $document->somethingElse = 'value';
        $document->setAccess('id', false);
        $document->setAccess('name', true);
        $document->setVirtual(['baz']);
        $document->setDirty('foo', true);
        $document->setError('foo', ['An error']);
        $document->setInvalidField('foo', 'a value');
        $document->setSource('foos');

        $result = $document->__debugInfo();
        $expected = [
            'foo' => 'bar',
            'somethingElse' => 'value',
            'baz' => null,
            '[new]' => true,
            '[accessible]' => ['*' => true, 'id' => false, 'name' => true],
            '[dirty]' => ['somethingElse' => true, 'foo' => true],
            '[original]' => [],
            '[originalFields]' => ['foo'],
            '[virtual]' => ['baz'],
            '[hasErrors]' => true,
            '[errors]' => ['foo' => ['An error']],
            '[invalid]' => ['foo' => 'a value'],
            '[repository]' => 'foos',
        ];
        $this->assertSame($expected, $result);
    }

    /**
     * Test the source getter
     */
    public function testGetAndSetSource(): void
    {
        $document = new Document();
        $this->assertSame('', $document->getSource());
        $document->setSource('foos');
        $this->assertSame('foos', $document->getSource());
    }

    /**
     * Provides empty values
     *
     * @return array
     */
    public function emptyNamesProvider(): array
    {
        return [[''], [null]];
    }

    /**
     * Tests that trying to get an empty property name throws exception
     */
    public function testEmptyProperties(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $document = new Document();
        $document->get('');
    }

    /**
     * Provides empty values
     */
    public function testIsDirtyFromClone(): void
    {
        $document = new Document(
            ['a' => 1, 'b' => 2],
            ['markNew' => false, 'markClean' => true],
        );

        $this->assertFalse($document->isNew());
        $this->assertFalse($document->isDirty());

        $cloned = clone $document;
        $cloned->setNew(true);

        $this->assertTrue($cloned->isDirty());
        $this->assertTrue($cloned->isDirty('a'));
        $this->assertTrue($cloned->isDirty('b'));
    }

    /**
     * Tests getInvalid and setInvalid
     */
    public function testGetSetInvalid(): void
    {
        $document = new Document();
        $return = $document->setInvalid([
            'title' => 'albert',
            'body' => 'einstein',
        ]);
        $this->assertSame($document, $return);
        $this->assertSame([
            'title' => 'albert',
            'body' => 'einstein',
        ], $document->getInvalid());

        $set = $document->setInvalid([
            'title' => 'nikola',
            'body' => 'tesla',
        ]);
        $this->assertSame([
            'title' => 'albert',
            'body' => 'einstein',
        ], $set->getInvalid());

        $overwrite = $document->setInvalid([
            'title' => 'nikola',
            'body' => 'tesla',
        ], true);
        $this->assertSame($document, $overwrite);
        $this->assertSame([
            'title' => 'nikola',
            'body' => 'tesla',
        ], $document->getInvalid());
    }

    /**
     * Tests getInvalidField
     */
    public function testGetSetInvalidField(): void
    {
        $document = new Document();
        $return = $document->setInvalidField('title', 'albert');
        $this->assertSame($document, $return);
        $this->assertSame('albert', $document->getInvalidField('title'));

        $overwrite = $document->setInvalidField('title', 'nikola');
        $this->assertSame($document, $overwrite);
        $this->assertSame('nikola', $document->getInvalidField('title'));
    }

    /**
     * Tests getInvalidFieldNull
     */
    public function testGetInvalidFieldNull(): void
    {
        $document = new Document();
        $this->assertNull($document->getInvalidField('foo'));
    }

    /**
     * Test hasValue()
     */
    public function testHasValue(): void
    {
        $document = new Document([
            'array' => ['foo' => 'bar'],
            'emptyArray' => [],
            'object' => new stdClass(),
            'string' => 'string',
            'stringZero' => '0',
            'emptyString' => '',
            'intZero' => 0,
            'intNotZero' => 1,
            'floatZero' => 0.0,
            'floatNonZero' => 1.5,
            'null' => null,
        ]);

        $this->assertTrue($document->hasValue('array'));
        $this->assertFalse($document->hasValue('emptyArray'));
        $this->assertTrue($document->hasValue('object'));
        $this->assertTrue($document->hasValue('string'));
        $this->assertTrue($document->hasValue('stringZero'));
        $this->assertFalse($document->hasValue('emptyString'));
        $this->assertTrue($document->hasValue('intZero'));
        $this->assertTrue($document->hasValue('intNotZero'));
        $this->assertTrue($document->hasValue('floatZero'));
        $this->assertTrue($document->hasValue('floatNonZero'));
        $this->assertFalse($document->hasValue('null'));
    }

    /**
     * Test isOriginalField()
     */
    public function testIsOriginalField(): void
    {
        $document = new Document(['foo' => null]);
        $return = $document->isOriginalField('foo');
        $this->assertSame(true, $return);

        $document = new Document([]);
        $document->set('foo');

        $return = $document->isOriginalField('foo');
        $this->assertSame(false, $return);

        $return = $document->isOriginalField('bar');
        $this->assertSame(false, $return);
    }

    /**
     * Test getOriginalFields()
     */
    public function testGetOriginalFields(): void
    {
        $document = new Document(['foo' => 'foo', 'bar' => 'bar']);
        $document->set('baz', 'baz');

        $return = $document->getOriginalFields();
        $this->assertEquals(['foo', 'bar'], $return);

        $document = new Document([]);
        $document->set('foo', 'foo');
        $document->set('bar', 'bar');
        $document->set('baz', 'baz');

        $return = $document->getOriginalFields();
        $this->assertEquals([], $return);
    }

    /**
     * Test setOriginalField() inside EntityInterface::setDirty()
     */
    public function testSetOriginalFieldInSetDirty(): void
    {
        $document = new Document([]);
        $document->set('foo', 'bar');

        $return = $document->isOriginalField('foo');
        $this->assertSame(false, $return);

        $document->setDirty('foo', false);

        $return = $document->isOriginalField('foo');
        $this->assertSame(true, $return);
    }

    /**
     * Test setOriginalField() inside EntityInterface::clean()
     */
    public function testSetOriginalFieldInClean(): void
    {
        $document = new Document([]);
        $document->set('foo', 'bar');

        $return = $document->isOriginalField('foo');
        $this->assertSame(false, $return);

        $document->clean();

        $return = $document->isOriginalField('foo');
        $this->assertSame(true, $return);
    }

    /**
     * Test infinite recursion in getErrors and hasErrors
     * See https://github.com/cakephp/cakephp/issues/17318
     */
    public function testGetErrorsRecursionError(): void
    {
        $document = new Document();
        $secondEntity = new Document();

        $document->set('child', $secondEntity);
        $secondEntity->set('parent', $document);

        $expectedErrors = ['name' => ['_required' => 'Must be present.']];
        $secondEntity->setErrors($expectedErrors);

        $this->assertEquals(['child' => $expectedErrors], $document->getErrors());
    }

    /**
     * Test infinite recursion in getErrors and hasErrors
     * See https://github.com/cakephp/cakephp/issues/17318
     */
    public function testHasErrorsRecursionError(): void
    {
        $document = new Document();
        $secondEntity = new Document();

        $document->set('child', $secondEntity);
        $secondEntity->set('parent', $document);

        $this->assertFalse($document->hasErrors());
    }
}
