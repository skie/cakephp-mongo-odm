<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Update;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Update\UpdateBuilder;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for UpdateBuilder
 */
#[CoversClass(UpdateBuilder::class)]
class UpdateBuilderTest extends TestCase
{
    /**
     * Test set method
     */
    public function testSet(): void
    {
        $builder = new UpdateBuilder();
        $builder->set(['name' => 'John', 'age' => 30]);

        $update = $builder->getUpdate();
        $this->assertArrayHasKey('$set', $update);
        $this->assertEquals('John', $update['$set']['name']);
        $this->assertEquals(30, $update['$set']['age']);
    }

    /**
     * Test set with multiple calls
     */
    public function testSetMultipleCalls(): void
    {
        $builder = new UpdateBuilder();
        $builder->set(['name' => 'John'])
            ->set(['age' => 30]);

        $update = $builder->getUpdate();
        $this->assertCount(2, $update['$set']);
        $this->assertEquals('John', $update['$set']['name']);
        $this->assertEquals(30, $update['$set']['age']);
    }

    /**
     * Test inc method
     */
    public function testInc(): void
    {
        $builder = new UpdateBuilder();
        $builder->inc(['count' => 1, 'score' => 5]);

        $update = $builder->getUpdate();
        $this->assertArrayHasKey('$inc', $update);
        $this->assertEquals(1, $update['$inc']['count']);
        $this->assertEquals(5, $update['$inc']['score']);
    }

    /**
     * Test mul method
     */
    public function testMul(): void
    {
        $builder = new UpdateBuilder();
        $builder->mul(['price' => 1.1, 'quantity' => 2]);

        $update = $builder->getUpdate();
        $this->assertArrayHasKey('$mul', $update);
        $this->assertEquals(1.1, $update['$mul']['price']);
        $this->assertEquals(2, $update['$mul']['quantity']);
    }

    /**
     * Test min method
     */
    public function testMin(): void
    {
        $builder = new UpdateBuilder();
        $builder->min(['lowest' => 100, 'score' => 50]);

        $update = $builder->getUpdate();
        $this->assertArrayHasKey('$min', $update);
        $this->assertEquals(100, $update['$min']['lowest']);
        $this->assertEquals(50, $update['$min']['score']);
    }

    /**
     * Test max method
     */
    public function testMax(): void
    {
        $builder = new UpdateBuilder();
        $builder->max(['highest' => 1000, 'score' => 200]);

        $update = $builder->getUpdate();
        $this->assertArrayHasKey('$max', $update);
        $this->assertEquals(1000, $update['$max']['highest']);
        $this->assertEquals(200, $update['$max']['score']);
    }

    /**
     * Test currentDate method with date type
     */
    public function testCurrentDateAsDate(): void
    {
        $builder = new UpdateBuilder();
        $builder->currentDate('lastModified');

        $update = $builder->getUpdate();
        $this->assertArrayHasKey('$currentDate', $update);
        $this->assertEquals(['$type' => 'date'], $update['$currentDate']['lastModified']);
    }

    /**
     * Test currentDate method with timestamp type
     */
    public function testCurrentDateAsTimestamp(): void
    {
        $builder = new UpdateBuilder();
        $builder->currentDate('lastModified', false);

        $update = $builder->getUpdate();
        $this->assertArrayHasKey('$currentDate', $update);
        $this->assertEquals(['$type' => 'timestamp'], $update['$currentDate']['lastModified']);
    }

    /**
     * Test currentDate with multiple fields
     */
    public function testCurrentDateMultipleFields(): void
    {
        $builder = new UpdateBuilder();
        $builder->currentDate(['created', 'updated']);

        $update = $builder->getUpdate();
        $this->assertCount(2, $update['$currentDate']);
        $this->assertArrayHasKey('created', $update['$currentDate']);
        $this->assertArrayHasKey('updated', $update['$currentDate']);
    }

    /**
     * Test rename method
     */
    public function testRename(): void
    {
        $builder = new UpdateBuilder();
        $builder->rename('oldName', 'newName');

        $update = $builder->getUpdate();
        $this->assertArrayHasKey('$rename', $update);
        $this->assertEquals('newName', $update['$rename']['oldName']);
    }

    /**
     * Test setOnInsert method
     */
    public function testSetOnInsert(): void
    {
        $builder = new UpdateBuilder();
        $builder->setOnInsert(['created' => '2024-01-01', 'status' => 'new']);

        $update = $builder->getUpdate();
        $this->assertArrayHasKey('$setOnInsert', $update);
        $this->assertEquals('2024-01-01', $update['$setOnInsert']['created']);
        $this->assertEquals('new', $update['$setOnInsert']['status']);
    }

    /**
     * Test bit method
     */
    public function testBit(): void
    {
        $builder = new UpdateBuilder();
        $builder->bit(['flags' => ['and' => 5], 'permissions' => ['or' => 3]]);

        $update = $builder->getUpdate();
        $this->assertArrayHasKey('$bit', $update);
        $this->assertEquals(['and' => 5], $update['$bit']['flags']);
        $this->assertEquals(['or' => 3], $update['$bit']['permissions']);
    }

    /**
     * Test push method
     */
    public function testPush(): void
    {
        $builder = new UpdateBuilder();
        $builder->push('tags', 'newTag');

        $update = $builder->getUpdate();
        $this->assertArrayHasKey('$push', $update);
        $this->assertEquals('newTag', $update['$push']['tags']);
    }

    /**
     * Test push with options
     */
    public function testPushWithOptions(): void
    {
        $builder = new UpdateBuilder();
        $builder->push('tags', ['tag1', 'tag2'], ['$each' => [], '$slice' => 10]);

        $update = $builder->getUpdate();
        $this->assertArrayHasKey('$each', $update['$push']['tags']);
        $this->assertEquals(10, $update['$push']['tags']['$slice']);
    }

    /**
     * Test pull method
     */
    public function testPull(): void
    {
        $builder = new UpdateBuilder();
        $builder->pull('tags', 'oldTag');

        $update = $builder->getUpdate();
        $this->assertArrayHasKey('$pull', $update);
        $this->assertEquals('oldTag', $update['$pull']['tags']);
    }

    /**
     * Test addToSet method
     */
    public function testAddToSet(): void
    {
        $builder = new UpdateBuilder();
        $builder->addToSet('tags', 'newTag');

        $update = $builder->getUpdate();
        $this->assertArrayHasKey('$addToSet', $update);
        $this->assertEquals('newTag', $update['$addToSet']['tags']);
    }

    /**
     * Test unset method
     */
    public function testUnset(): void
    {
        $builder = new UpdateBuilder();
        $builder->unset(['temp', 'debug']);

        $update = $builder->getUpdate();
        $this->assertArrayHasKey('$unset', $update);
        $this->assertEquals('', $update['$unset']['temp']);
        $this->assertEquals('', $update['$unset']['debug']);
    }

    /**
     * Test upsert option
     */
    public function testUpsert(): void
    {
        $builder = new UpdateBuilder();
        $builder->upsert(true);

        $options = $builder->getOptions();
        $this->assertTrue($options['upsert']);
    }

    /**
     * Test arrayFilters option
     */
    public function testArrayFilters(): void
    {
        $builder = new UpdateBuilder();
        $builder->arrayFilters([['element.score' => ['$gte' => 80]]]);

        $options = $builder->getOptions();
        $this->assertArrayHasKey('arrayFilters', $options);
        $this->assertCount(1, $options['arrayFilters']);
    }

    /**
     * Test hint option
     */
    public function testHint(): void
    {
        $builder = new UpdateBuilder();
        $builder->hint('name_1');

        $options = $builder->getOptions();
        $this->assertEquals('name_1', $options['hint']);
    }

    /**
     * Test hint with array
     */
    public function testHintWithArray(): void
    {
        $builder = new UpdateBuilder();
        $builder->hint(['name' => 1, 'age' => -1]);

        $options = $builder->getOptions();
        $this->assertEquals(['name' => 1, 'age' => -1], $options['hint']);
    }

    /**
     * Test method chaining
     */
    public function testMethodChaining(): void
    {
        $builder = new UpdateBuilder();
        $result = $builder->set(['name' => 'John'])
            ->inc(['count' => 1])
            ->mul(['price' => 1.1])
            ->upsert(true);

        $this->assertSame($builder, $result);
        $update = $builder->getUpdate();
        $this->assertArrayHasKey('$set', $update);
        $this->assertArrayHasKey('$inc', $update);
        $this->assertArrayHasKey('$mul', $update);
        $this->assertTrue($builder->getOptions()['upsert']);
    }

    /**
     * Test complex update scenario
     */
    public function testComplexUpdate(): void
    {
        $builder = new UpdateBuilder();
        $builder->set(['status' => 'active'])
            ->inc(['views' => 1])
            ->min(['lowestPrice' => 100])
            ->max(['highestPrice' => 1000])
            ->currentDate('lastModified')
            ->setOnInsert(['created' => '2024-01-01'])
            ->upsert(true)
            ->arrayFilters([['item.price' => ['$gt' => 100]]]);

        $update = $builder->getUpdate();
        $options = $builder->getOptions();

        $this->assertArrayHasKey('$set', $update);
        $this->assertArrayHasKey('$inc', $update);
        $this->assertArrayHasKey('$min', $update);
        $this->assertArrayHasKey('$max', $update);
        $this->assertArrayHasKey('$currentDate', $update);
        $this->assertArrayHasKey('$setOnInsert', $update);
        $this->assertTrue($options['upsert']);
        $this->assertArrayHasKey('arrayFilters', $options);
    }
}
