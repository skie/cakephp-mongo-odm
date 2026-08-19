<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Expression;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Expression\FunctionExpression;
use Crustum\Mongo\Database\FunctionsBuilder;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the FunctionsBuilder (Cake-style aggregation functions factory).
 */
#[CoversClass(FunctionsBuilder::class)]
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
     * Test countField builds a grouped COUNT(column) via $sum/$cond/$ifNull.
     *
     * @return void
     */
    public function testCountField(): void
    {
        $this->assertSame(
            [
                '$sum' => [
                    '$cond' => [
                        ['$ne' => [['$ifNull' => ['$articles._id', null]], null]],
                        1,
                        0,
                    ],
                ],
            ],
            $this->functions->countField('articles._id')->getConditions(),
        );
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
     * Operators without a dedicated method (toUpper, toLower, binarySize, …)
     * must resolve through `__call` → FunctionExpression.
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

        // __call — no toUpper()/toLower()/binarySize() methods on FunctionsBuilder.
        $this->assertFalse(method_exists($this->functions, 'toUpper'));
        $this->assertFalse(method_exists($this->functions, 'toLower'));
        $this->assertSame(
            ['$toUpper' => '$title'],
            $this->functions->toUpper('$title')->getConditions(),
        );
        $this->assertSame(
            ['$toLower' => '$name'],
            $this->functions->toLower('$name')->getConditions(),
        );
        $this->assertSame(
            ['$binarySize' => '$data'],
            $this->functions->binarySize('$data')->getConditions(),
        );
    }

    /**
     * Test filter() builds a $filter array-filter operator.
     *
     * The `cond` accepts an `$expr`-style condition (e.g. `eq`), which renders
     * as an operator document — no raw arrays required on the caller side.
     *
     * @return void
     */
    public function testFilter(): void
    {
        $func = $this->functions;
        $cond = $func->and([$func->eq('$$item.highlighted', true)]);

        $this->assertSame(
            ['$filter' => [
                'input' => '$_join_tags',
                'as' => 'item',
                'cond' => ['$and' => [['$eq' => ['$$item.highlighted', true]]]],
            ]],
            $func->filter('$_join_tags', 'item', $cond)->getConditions(),
        );
    }

    /**
     * Test map() builds a $map array-projection operator.
     *
     * @return void
     */
    public function testMap(): void
    {
        $func = $this->functions;
        $projection = [
            'id' => '$$item.id',
            'name' => '$$item.name',
        ];

        $this->assertSame(
            ['$map' => [
                'input' => '$tags',
                'as' => 'item',
                'in' => $projection,
            ]],
            $func->map('$tags', 'item', $projection)->getConditions(),
        );
    }

    /**
     * Test sortArray() builds a $sortArray in-document sort operator.
     *
     * @return void
     */
    public function testSortArray(): void
    {
        $func = $this->functions;

        $this->assertSame(
            ['$sortArray' => [
                'input' => '$tags',
                'sortBy' => ['name' => 1],
            ]],
            $func->sortArray('$tags', ['name' => 1])->getConditions(),
        );
    }

    /**
     * Test comparison/conjunction operators render $expr-style documents.
     *
     * These are used as `$filter` conditions over in-document arrays (junction
     * through-rows), so they must render operator documents, not `{field: value}`
     * query documents.
     *
     * @return void
     */
    public function testComparisonAndConjunctionOperators(): void
    {
        $func = $this->functions;

        $this->assertSame(
            ['$eq' => ['$$item.highlighted', true]],
            $func->eq('$$item.highlighted', true)->getConditions(),
        );
        $this->assertSame(
            ['$and' => [
                ['$eq' => ['$$item.a', 1]],
                ['$eq' => ['$$item.b', 2]],
            ]],
            $func->and([
                $func->eq('$$item.a', 1),
                $func->eq('$$item.b', 2),
            ])->getConditions(),
        );
        $this->assertSame(
            ['$or' => [
                ['$eq' => ['$$item.a', 1]],
                ['$eq' => ['$$item.a', 2]],
            ]],
            $func->or([
                $func->eq('$$item.a', 1),
                $func->eq('$$item.a', 2),
            ])->getConditions(),
        );
        $this->assertSame(
            ['$nor' => [
                ['$eq' => ['$$item.a', 1]],
            ]],
            $func->nor([$func->eq('$$item.a', 1)])->getConditions(),
        );
        $this->assertSame(
            ['$gt' => ['$$item.age', 18]],
            $func->gt('$$item.age', 18)->getConditions(),
        );
        $this->assertSame(
            ['$gte' => ['$$item.age', 18]],
            $func->gte('$$item.age', 18)->getConditions(),
        );
        $this->assertSame(
            ['$lt' => ['$$item.age', 18]],
            $func->lt('$$item.age', 18)->getConditions(),
        );
        $this->assertSame(
            ['$lte' => ['$$item.age', 18]],
            $func->lte('$$item.age', 18)->getConditions(),
        );
        $this->assertSame(
            ['$not' => ['$eq' => ['$$item.a', 1]]],
            $func->not($func->eq('$$item.a', 1))->getConditions(),
        );
        $this->assertSame(
            ['$regexMatch' => ['input' => '$$item.name', 'regex' => '^tag.*$']],
            $func->regexMatch('$$item.name', '^tag.*$')->getConditions(),
        );
        $this->assertSame(
            ['$expr' => ['$eq' => [1, 0]]],
            $func->expr($func->eq(1, 0))->getConditions(),
        );
    }

    /**
     * Test type/inArray/isMissingOrNull and condWhenPresent for optional unwind paths.
     *
     * @return void
     */
    public function testTypeAndCondWhenPresent(): void
    {
        $func = $this->functions;

        $this->assertSame(['$type' => '$author'], $func->type('$author')->getConditions());
        $this->assertSame(['$type' => '$author'], $func->type('author')->getConditions());
        $this->assertSame(
            ['$in' => [['$type' => '$author'], ['missing', 'null']]],
            $func->isMissingOrNull('$author')->getConditions(),
        );
        $this->assertSame(
            [
                '$cond' => [
                    ['$in' => [['$type' => '$author'], ['missing', 'null']]],
                    null,
                    ['name' => '$author.name'],
                ],
            ],
            $func->condWhenPresent('$author', ['name' => '$author.name'])->getConditions(),
        );
    }

    /**
     * Test string/conversion/math operators render their $-operator documents.
     *
     * @return void
     */
    public function testStringConversionAndMathOperators(): void
    {
        $func = $this->functions;

        $this->assertSame(['$toString' => '$_id'], $func->toString('$_id')->getConditions());
        $this->assertSame(['$toInt' => '$digit'], $func->toInt('$digit')->getConditions());
        $this->assertSame(
            ['$substrCP' => ['$name', 0, 3]],
            $func->substr('$name', 0, 3)->getConditions(),
        );
        $this->assertSame(['$strLenCP' => '$name'], $func->strLenCP('$name')->getConditions());
        $this->assertSame(['$subtract' => ['$a', '$b']], $func->subtract('$a', '$b')->getConditions());
        $this->assertSame(
            ['$add' => ['$a', '$b', 1]],
            $func->add('$a', '$b', 1)->getConditions(),
        );
        $this->assertSame(
            ['$multiply' => ['$price', '$qty']],
            $func->multiply('$price', '$qty')->getConditions(),
        );
        $this->assertSame(['$mod' => ['$n', 2]], $func->mod('$n', 2)->getConditions());
    }

    /**
     * Test nested operator composition resolves recursively.
     *
     * Models the SQL `id % 2` parity rewrite: the last hex digit of `_id`
     * (string -> length -> last char -> int -> mod) must render as nested
     * `$`-operator documents with no raw arrays on the caller side.
     *
     * @return void
     */
    public function testNestedOperatorComposition(): void
    {
        $func = $this->functions;
        $str = $func->toString('$_id');
        $lastIndex = $func->subtract($func->strLenCP($str), 1);
        $lastChar = $func->toInt($func->substr($str, $lastIndex, 1));
        $odd = $func->mod($lastChar, 2);

        $this->assertSame(
            ['$mod' => [
                ['$toInt' => [
                    '$substrCP' => [
                        ['$toString' => '$_id'],
                        ['$subtract' => [['$strLenCP' => ['$toString' => '$_id']], 1]],
                        1,
                    ],
                ]],
                2,
            ]],
            $odd->getConditions(),
        );
    }
}
