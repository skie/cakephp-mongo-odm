<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Schema;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Schema\Field;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Test case for the Field value object.
 *
 * Ported from `Cake\Test\TestCase\Database\Schema\ColumnTest`, adapted for
 * MongoDB: SQL-specific options (generated, after, onUpdate, unsigned,
 * collate, srid, datetime-fractional) are dropped; Mongo/ODM options
 * (`enumType`, `notSaved`) are covered instead.
 */
#[CoversClass(Field::class)]
class FieldTest extends TestCase
{
    /**
     * Test name mutation.
     *
     * @return void
     */
    public function testSetName(): void
    {
        $field = new Field('body', 'string');
        $this->assertSame('body', $field->getName());

        $field->setName('id');
        $this->assertSame('id', $field->getName());
    }

    /**
     * Test type mutation.
     *
     * @return void
     */
    public function testSetType(): void
    {
        $field = new Field('body', 'string');
        $this->assertSame('string', $field->getType());

        $field->setType('objectid');
        $this->assertSame('objectid', $field->getType());

        // Types are not validated, so that we can preserve types we don't have
        // specific handling for, as drivers can implement their own types.
        $field->setType('imaginary');
        $this->assertSame('imaginary', $field->getType());
    }

    /**
     * Test base type when set explicitly.
     *
     * @return void
     */
    public function testSetBaseTypeExplicit(): void
    {
        $field = new Field('body', 'string');
        $this->assertSame('string', $field->getBaseType());

        $field
            ->setType('enum')
            ->setBaseType('string');
        $this->assertSame('enum', $field->getType());
        $this->assertSame('string', $field->getBaseType());
    }

    /**
     * Test base type inferred from the type factory.
     *
     * @return void
     */
    public function testGetBaseTypeInferredFromTypeFactory(): void
    {
        $field = new Field('created', 'date');
        $this->assertSame('date', $field->getBaseType());
        $this->assertSame('date', $field->getType());
    }

    /**
     * Test length.
     *
     * @return void
     */
    public function testSetLength(): void
    {
        $field = new Field('body', 'string');
        $this->assertNull($field->getLength());

        $field->setLength(255);
        $this->assertSame(255, $field->getLength());
    }

    /**
     * Test precision.
     *
     * @return void
     */
    public function testSetPrecision(): void
    {
        $field = new Field('amount', 'decimal128');
        $this->assertNull($field->getPrecision());

        $field->setPrecision(2);
        $this->assertSame(2, $field->getPrecision());
    }

    /**
     * Test the null flag.
     *
     * @return void
     */
    public function testSetNull(): void
    {
        $field = new Field('body', 'string');
        $this->assertFalse($field->isNull());
        $this->assertNull($field->getNull());

        $field->setNull(false);
        $this->assertFalse($field->isNull());
        $this->assertFalse($field->getNull());

        $field->setNull(true);
        $this->assertTrue($field->isNull());
        $this->assertTrue($field->getNull());
    }

    /**
     * Test the default value.
     *
     * @return void
     */
    public function testSetDefault(): void
    {
        $field = new Field('body', 'string');
        $this->assertNull($field->getDefault());

        $field->setDefault('default_value');
        $this->assertSame('default_value', $field->getDefault());
    }

    /**
     * Test the identity (primary key) flag.
     *
     * @return void
     */
    public function testSetIdentity(): void
    {
        $field = new Field('body', 'string');
        $this->assertFalse($field->isIdentity());

        $field->setIdentity(true);
        $this->assertTrue($field->isIdentity());
        $this->assertTrue($field->getIdentity());

        $field->setIdentity(false);
        $this->assertFalse($field->isIdentity());
        $this->assertFalse($field->getIdentity());
    }

    /**
     * Test setAttributes with an indexed option list throws.
     *
     * @return void
     */
    public function testSetAttributesThrowsExceptionIfOptionIsNotString(): void
    {
        $field = new Field('body', 'string');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"0" is not a valid field option.');

        $field->setAttributes(['identity']);
    }

    /**
     * Test the comment.
     *
     * @return void
     */
    public function testSetComment(): void
    {
        $field = new Field('body', 'string');
        $this->assertNull($field->getComment());

        $field->setComment('This is a comment');
        $this->assertSame('This is a comment', $field->getComment());
    }

    /**
     * Test setAttributes with identity keeps null untouched.
     *
     * @return void
     */
    public function testSetAttributesIdentity(): void
    {
        $field = new Field('body', 'string');
        $this->assertFalse($field->isNull());
        $this->assertFalse($field->isIdentity());

        $field->setAttributes(['identity' => true]);
        $this->assertFalse($field->isNull());
        $this->assertTrue($field->isIdentity());
    }

    /**
     * Test the enum type.
     *
     * @return void
     */
    public function testSetEnumType(): void
    {
        $field = new Field('status', 'enum');
        $this->assertNull($field->getEnumType());

        $field->setEnumType(FieldTestStatus::class);
        $this->assertSame(FieldTestStatus::class, $field->getEnumType());
    }

    /**
     * Test the notSaved flag.
     *
     * @return void
     */
    public function testSetNotSaved(): void
    {
        $field = new Field('tmp', 'string');
        $this->assertFalse($field->getNotSaved());

        $field->setNotSaved(true);
        $this->assertTrue($field->getNotSaved());
    }

    /**
     * Test setAttributes maps an option array.
     *
     * @return void
     */
    public function testSetAttributes(): void
    {
        $field = new Field('body', 'string');
        $options = [
            'type' => 'string',
            'length' => 255,
            'null' => false,
            'default' => 'default_value',
            'comment' => 'Body field',
        ];
        $field->setAttributes($options);

        $this->assertSame(255, $field->getLength());
        $this->assertFalse($field->isNull());
        $this->assertSame('default_value', $field->getDefault());
        $this->assertSame('Body field', $field->getComment());
    }

    /**
     * Data provider for toArray round-trips.
     *
     * @return array<string, array{string, bool|null}>
     */
    public static function toArrayDataProvider(): array
    {
        return [
            'string nullable' => ['string', null],
            'string not null' => ['string', false],
            'objectid' => ['objectid', null],
        ];
    }

    /**
     * Test toArray round-trip preserves name and type.
     *
     * @param string $inType The type to build with.
     * @param bool|null $null The null flag.
     * @return void
     */
    #[DataProvider('toArrayDataProvider')]
    public function testToArray(string $inType, ?bool $null): void
    {
        $field = new Field('created', $inType, $null);
        $result = $field->toArray();

        $this->assertSame($inType, $result['type']);
        $this->assertSame($null, $result['null']);
        $this->assertSame('created', $result['name']);
    }
}
