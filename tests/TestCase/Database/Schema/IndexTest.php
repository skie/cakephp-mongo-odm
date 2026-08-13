<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Schema;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Schema\Index;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * Test case for the Index value object.
 *
 * Ported from `Cake\Test\TestCase\Database\Schema\IndexTest`, adapted for
 * MongoDB: the column list becomes a key map (`field => direction`), the
 * SQL-specific `length`/`include`/`accessMethod` options are dropped, and
 * Mongo-specific options (`unique`, `sparse`, `expireAfterSeconds`,
 * `partialFilterExpression`, `collation`) are covered instead.
 */
#[CoversClass(Index::class)]
class IndexTest extends TestCase
{
    /**
     * Test type mutation.
     *
     * @return void
     */
    public function testSetType(): void
    {
        $index = new Index('title_idx', ['title' => 1]);
        $this->assertSame(Index::INDEX, $index->getType());

        $index->setType(Index::TEXT);
        $this->assertSame(Index::TEXT, $index->getType());

        $index->setType(Index::INDEX);
        $this->assertSame(Index::INDEX, $index->getType());
    }

    /**
     * Test key mutation.
     *
     * @return void
     */
    public function testSetKey(): void
    {
        $index = new Index('title_idx', []);
        $this->assertSame([], $index->getKey());

        $index->setKey(['title' => 1]);
        $this->assertSame(['title' => 1], $index->getKey());

        $index->setKey(['title' => 1, 'name' => -1]);
        $this->assertSame(['title' => 1, 'name' => -1], $index->getKey());
    }

    /**
     * Test name mutation.
     *
     * @return void
     */
    public function testSetName(): void
    {
        $index = new Index('title_idx', ['title' => 1]);
        $this->assertSame('title_idx', $index->getName());

        $index->setName('my_index');
        $this->assertSame('my_index', $index->getName());
    }

    /**
     * Test the unique flag.
     *
     * @return void
     */
    public function testSetUnique(): void
    {
        $index = new Index('title_idx', ['title' => 1]);
        $this->assertFalse($index->getUnique());

        $index->setUnique(true);
        $this->assertTrue($index->getUnique());

        $index->setUnique(false);
        $this->assertFalse($index->getUnique());
    }

    /**
     * Test the sparse flag.
     *
     * @return void
     */
    public function testSetSparse(): void
    {
        $index = new Index('title_idx', ['title' => 1]);
        $this->assertFalse($index->getSparse());

        $index->setSparse(true);
        $this->assertTrue($index->getSparse());

        $index->setSparse(false);
        $this->assertFalse($index->getSparse());
    }

    /**
     * Test the TTL option.
     *
     * @return void
     */
    public function testSetExpireAfterSeconds(): void
    {
        $index = new Index('title_idx', ['title' => 1]);
        $this->assertNull($index->getExpireAfterSeconds());

        $index->setExpireAfterSeconds(3600);
        $this->assertSame(3600, $index->getExpireAfterSeconds());
    }

    /**
     * Test the partial filter expression.
     *
     * @return void
     */
    public function testSetPartialFilterExpression(): void
    {
        $index = new Index('title_idx', ['title' => 1]);
        $this->assertNull($index->getPartialFilterExpression());

        $index->setPartialFilterExpression(['status' => 'active']);
        $this->assertSame(['status' => 'active'], $index->getPartialFilterExpression());
    }

    /**
     * Test the collation.
     *
     * @return void
     */
    public function testSetCollation(): void
    {
        $index = new Index('title_idx', ['title' => 1]);
        $this->assertNull($index->getCollation());

        $index->setCollation(['locale' => 'en', 'strength' => 2]);
        $this->assertSame(['locale' => 'en', 'strength' => 2], $index->getCollation());
    }

    /**
     * Test setAttributes maps an option array.
     *
     * @return void
     */
    public function testSetAttributes(): void
    {
        $index = new Index('title_idx', ['title' => 1]);
        $attrs = [
            'name' => 'index-name',
            'key' => ['title' => 1, 'name' => -1],
            'unique' => true,
            'sparse' => true,
        ];
        $index->setAttributes($attrs);
        $this->assertSame('index-name', $index->getName());
        $this->assertSame(['title' => 1, 'name' => -1], $index->getKey());
        $this->assertTrue($index->getUnique());
        $this->assertTrue($index->getSparse());
    }

    /**
     * Test setAttributes rejects unknown options.
     *
     * @return void
     */
    public function testSetAttributesRejectsUnknown(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"bogus" is not a valid index option.');

        (new Index('a', ['title' => 1]))->setAttributes(['bogus' => true]);
    }

    /**
     * Test toArray round-trip.
     *
     * @return void
     */
    public function testToArray(): void
    {
        $index = new Index('title_idx', ['title' => 1]);
        $result = $index->toArray();

        $this->assertSame('title_idx', $result['name']);
        $this->assertSame(['title' => 1], $result['key']);
        $this->assertSame(Index::INDEX, $result['type']);
        $this->assertFalse($result['unique']);
        $this->assertFalse($result['sparse']);
    }

    /**
     * Test createIndexOptions merges first-class options.
     *
     * @return void
     */
    public function testCreateIndexOptions(): void
    {
        $index = new Index(
            'user_unique',
            ['user_id' => 1],
            Index::UNIQUE,
            true,
            true,
            null,
            null,
            null,
            ['hidden' => true],
        );

        $options = $index->createIndexOptions();
        $this->assertSame('user_unique', $options['name']);
        $this->assertTrue($options['unique']);
        $this->assertTrue($options['sparse']);
        $this->assertTrue($options['hidden']);
    }

    /**
     * Test createIndexOptions includes optional attributes.
     *
     * @return void
     */
    public function testCreateIndexOptionsWithOptionalAttributes(): void
    {
        $index = new Index(
            'ttl_idx',
            ['created' => 1],
            Index::INDEX,
            false,
            false,
            3600,
            ['status' => 'active'],
            ['locale' => 'en'],
        );

        $options = $index->createIndexOptions();
        $this->assertSame(3600, $options['expireAfterSeconds']);
        $this->assertSame(['status' => 'active'], $options['partialFilterExpression']);
        $this->assertSame(['locale' => 'en'], $options['collation']);
    }

    /**
     * Test fromAttributes with the Mongo key shape.
     *
     * @return void
     */
    public function testFromAttributesWithKey(): void
    {
        $index = Index::fromAttributes('user_idx', [
            'key' => ['user_id' => 1],
            'unique' => true,
        ]);

        $this->assertSame('user_idx', $index->getName());
        $this->assertSame(['user_id' => 1], $index->getKey());
        $this->assertSame(Index::UNIQUE, $index->getType());
        $this->assertTrue($index->getUnique());
    }

    /**
     * Test fromAttributes with the Cake columns shape.
     *
     * @return void
     */
    public function testFromAttributesWithColumns(): void
    {
        $index = Index::fromAttributes('title_idx', [
            'columns' => ['title'],
        ]);

        $this->assertSame(['title' => 1], $index->getKey());
    }

    /**
     * Test fromAttributes requires a non-empty key.
     *
     * @return void
     */
    public function testFromAttributesRequiresKey(): void
    {
        $this->expectException(RuntimeException::class);
        Index::fromAttributes('empty', []);
    }
}
