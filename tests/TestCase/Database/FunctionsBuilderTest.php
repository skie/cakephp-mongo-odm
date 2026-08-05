<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Expression;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Expression\FunctionExpression;
use Crustum\Mongo\Database\FunctionsBuilder;

/**
 * Tests the FunctionsBuilder (Cake-style aggregation functions factory).
 */
class FunctionsBuilderTest extends TestCase
{
    /**
     * The functions builder.
     *
     * @var \Crustum\Mongo\Database\FunctionsBuilder
     */
    protected FunctionsBuilder $functions;

    /**
     * Set up before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->functions = new FunctionsBuilder();
    }

    /**
     * Test the accumulator aggregates.
     *
     * @return void
     */
    public function testAggregates(): void
    {
        $this->assertSame(['$sum' => '$qty'], $this->functions->sum('$qty')->getConditions());
        $this->assertSame(['$avg' => '$qty'], $this->functions->avg('$qty')->getConditions());
        $this->assertSame(['$max' => '$qty'], $this->functions->max('$qty')->getConditions());
        $this->assertSame(['$min' => '$qty'], $this->functions->min('$qty')->getConditions());
    }

    /**
     * Test count renders an empty operator document.
     *
     * @return void
     */
    public function testCount(): void
    {
        $this->assertEquals(['$count' => (object)[]], $this->functions->count()->getConditions());
    }

    /**
     * Test rand and rowNumber render empty operator documents.
     *
     * @return void
     */
    public function testEmptyDocumentOperators(): void
    {
        $this->assertEquals(['$rand' => (object)[]], $this->functions->rand()->getConditions());
        $this->assertEquals(['$documentNumber' => (object)[]], $this->functions->rowNumber()->getConditions());
    }

    /**
     * Test concat and coalesce build array operators.
     *
     * @return void
     */
    public function testConcatAndCoalesce(): void
    {
        $this->assertSame(
            ['$concat' => ['$first', '$last']],
            $this->functions->concat(['$first', '$last'])->getConditions(),
        );
        $this->assertSame(
            ['$ifNull' => ['$nickname', '$username']],
            $this->functions->coalesce(['$nickname', '$username'])->getConditions(),
        );
    }

    /**
     * Test cast builds a $convert operator.
     *
     * @return void
     */
    public function testCast(): void
    {
        $this->assertSame(
            ['$convert' => ['input' => '$age', 'to' => 'int']],
            $this->functions->cast('$age', 'int')->getConditions(),
        );
    }

    /**
     * Test date-related operators.
     *
     * @return void
     */
    public function testDateOperators(): void
    {
        $this->assertSame(
            ['$dateDiff' => ['startDate' => '$a', 'endDate' => '$b', 'unit' => 'day', 'timezone' => null]],
            $this->functions->dateDiff('$a', '$b', 'day')->getConditions(),
        );
        $this->assertSame(
            ['$dateAdd' => ['startDate' => '$installed', 'unit' => 'day', 'amount' => 30]],
            $this->functions->dateAdd('$installed', 'day', 30)->getConditions(),
        );
        $this->assertSame(
            ['$year' => '$created'],
            $this->functions->extract('year', '$created')->getConditions(),
        );
        $this->assertSame(
            ['$dayOfWeek' => '$created'],
            $this->functions->dayOfWeek('$created')->getConditions(),
        );
        $this->assertSame(
            ['$dayOfWeek' => '$created'],
            $this->functions->weekday('$created')->getConditions(),
        );
        $this->assertSame(
            ['$year' => '$created'],
            $this->functions->datePart('year', '$created')->getConditions(),
        );
    }

    /**
     * Test now() returns the current date system variable.
     *
     * @return void
     */
    public function testNow(): void
    {
        $this->assertSame('$$NOW', $this->functions->now());
    }

    /**
     * Test lag/lead map to a window $shift.
     *
     * @return void
     */
    public function testLagLead(): void
    {
        $this->assertSame(
            ['$shift' => ['output' => '$amount', 'by' => 2, 'default' => 0]],
            $this->functions->lag('$amount', 2, 0)->getConditions(),
        );
        $this->assertSame(
            ['$shift' => ['output' => '$amount', 'by' => -2]],
            $this->functions->lead('$amount', 2)->getConditions(),
        );
    }

    /**
     * Test jsonValue reads a document field.
     *
     * @return void
     */
    public function testJsonValue(): void
    {
        $this->assertSame(
            ['$getField' => ['field' => 'meta', 'input' => '$doc']],
            $this->functions->jsonValue('meta', '$doc')->getConditions(),
        );
        $this->assertSame(
            ['$getField' => ['field' => 'meta']],
            $this->functions->jsonValue('meta')->getConditions(),
        );
    }

    /**
     * Test aggregate and __call build an arbitrary operator.
     *
     * @return void
     */
    public function testAggregateAndMagicCall(): void
    {
        $this->assertInstanceOf(FunctionExpression::class, $this->functions->aggregate('$toLower', ['$name']));
        $this->assertSame(
            ['$toLower' => '$name'],
            $this->functions->aggregate('toLower', ['$name'])->getConditions(),
        );
        $this->assertSame(
            ['$binarySize' => '$data'],
            $this->functions->binarySize('$data')->getConditions(),
        );
    }
}
