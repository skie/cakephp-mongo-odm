<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Schema;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Schema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for the Validator value object.
 */
#[CoversClass(Validator::class)]
class ValidatorTest extends TestCase
{
    /**
     * Test an empty validator emits default $jsonSchema.
     *
     * @return void
     */
    public function testEmptyValidator(): void
    {
        $validator = new Validator();

        $this->assertSame('object', $validator->getBsonType());
        $this->assertTrue($validator->getAdditionalProperties());
        $this->assertSame([], $validator->getRequired());
        $this->assertSame([], $validator->getProperties());

        $this->assertSame([
            '$jsonSchema' => [
                'bsonType' => 'object',
                'properties' => [],
                'additionalProperties' => true,
            ],
        ], $validator->toArray());
    }

    /**
     * Test adding fields.
     *
     * @return void
     */
    public function testFields(): void
    {
        $validator = new Validator();
        $validator->field('title', ['bsonType' => 'string']);
        $validator->field('author_id', ['bsonType' => 'objectId']);

        $this->assertTrue($validator->hasField('title'));
        $this->assertSame(['bsonType' => 'string'], $validator->getField('title'));
        $this->assertFalse($validator->hasField('nope'));
        $this->assertNull($validator->getField('nope'));

        $properties = $validator->getProperties();
        $this->assertArrayHasKey('title', $properties);
        $this->assertArrayHasKey('author_id', $properties);
    }

    /**
     * Test removing fields.
     *
     * @return void
     */
    public function testRemoveField(): void
    {
        $validator = new Validator();
        $validator->field('title', ['bsonType' => 'string']);

        $this->assertTrue($validator->hasField('title'));
        $this->assertSame($validator, $validator->removeField('title'));
        $this->assertFalse($validator->hasField('title'));
    }

    /**
     * Test required fields.
     *
     * @return void
     */
    public function testRequired(): void
    {
        $validator = new Validator();
        $validator->addRequired('title', 'author_id');

        $this->assertSame(['title', 'author_id'], $validator->getRequired());

        $array = $validator->toArray();
        $this->assertSame(['title', 'author_id'], $array['$jsonSchema']['required']);
    }

    /**
     * Test setRequired replaces the list.
     *
     * @return void
     */
    public function testSetRequired(): void
    {
        $validator = new Validator();
        $validator->addRequired('title');
        $validator->setRequired(['body']);

        $this->assertSame(['body'], $validator->getRequired());
    }

    /**
     * Test empty required list omits the required key.
     *
     * @return void
     */
    public function testEmptyRequiredOmitsKey(): void
    {
        $validator = new Validator();
        $validator->addRequired('title');
        $validator->setRequired([]);

        $array = $validator->toArray();
        $this->assertArrayNotHasKey('required', $array['$jsonSchema']);
    }

    /**
     * Test additionalProperties toggling.
     *
     * @return void
     */
    public function testAdditionalProperties(): void
    {
        $validator = new Validator();
        $this->assertTrue($validator->getAdditionalProperties());

        $validator->setAdditionalProperties(false);
        $this->assertFalse($validator->getAdditionalProperties());

        $array = $validator->toArray();
        $this->assertFalse($array['$jsonSchema']['additionalProperties']);
    }

    /**
     * Test bsonType root toggling.
     *
     * @return void
     */
    public function testBsonType(): void
    {
        $validator = new Validator();
        $validator->setBsonType('array');

        $this->assertSame('array', $validator->getBsonType());
        $this->assertSame('array', $validator->toArray()['$jsonSchema']['bsonType']);
    }

    /**
     * Test adopting an existing validator document.
     *
     * @return void
     */
    public function testAdoptExistingValidator(): void
    {
        $validator = new Validator([
            '$jsonSchema' => [
                'bsonType' => 'object',
                'required' => ['title'],
                'properties' => [
                    'title' => ['bsonType' => 'string'],
                ],
                'additionalProperties' => false,
            ],
        ]);

        $this->assertSame(['title'], $validator->getRequired());
        $this->assertSame(['title' => ['bsonType' => 'string']], $validator->getProperties());
        $this->assertFalse($validator->getAdditionalProperties());

        // Round-trip must be identical.
        $this->assertSame([
            '$jsonSchema' => [
                'bsonType' => 'object',
                'properties' => ['title' => ['bsonType' => 'string']],
                'additionalProperties' => false,
                'required' => ['title'],
            ],
        ], $validator->toArray());
    }

    /**
     * Test adopting a bare $jsonSchema (without the wrapper key).
     *
     * @return void
     */
    public function testAdoptBareJsonSchema(): void
    {
        $validator = new Validator([
            'bsonType' => 'object',
            'properties' => ['title' => ['bsonType' => 'string']],
        ]);

        $this->assertSame(['title' => ['bsonType' => 'string']], $validator->getProperties());
    }

    /**
     * Test withOptions adds validation level/action.
     *
     * @return void
     */
    public function testWithOptions(): void
    {
        $validator = new Validator();
        $validator->field('title', ['bsonType' => 'string']);

        $document = $validator->withOptions([
            'validationLevel' => 'moderate',
            'validationAction' => 'warn',
        ]);

        $this->assertArrayHasKey('$jsonSchema', $document);
        $this->assertSame('moderate', $document['validationLevel']);
        $this->assertSame('warn', $document['validationAction']);
    }
}
