<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Expression;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Expression\QueryExpression;
use Crustum\Mongo\Database\Expression\TupleInExpression;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests TupleInExpression compilation.
 */
#[CoversClass(TupleInExpression::class)]
class TupleInExpressionTest extends TestCase
{
    public function testSingleFieldCompilesToIn(): void
    {
        $expression = new TupleInExpression(
            ['author_id'],
            [['000000000000000000000001'], ['000000000000000000000002']],
        );

        $this->assertEquals([
            'author_id' => [
                '$in' => ['000000000000000000000001', '000000000000000000000002'],
            ],
        ], $expression->getConditions());
    }

    public function testCompositeFieldsCompileToOr(): void
    {
        $expression = new TupleInExpression(
            ['author_id', 'site_id'],
            [
                ['000000000000000000000001', '000000000000000000000010'],
                ['000000000000000000000002', '000000000000000000000020'],
            ],
        );

        $this->assertEquals([
            '$or' => [
                [
                    'author_id' => '000000000000000000000001',
                    'site_id' => '000000000000000000000010',
                ],
                [
                    'author_id' => '000000000000000000000002',
                    'site_id' => '000000000000000000000020',
                ],
            ],
        ], $expression->getConditions());
    }

    public function testEmptyTuplesCompileToEmptyConditions(): void
    {
        $expression = new TupleInExpression(['author_id'], []);

        $this->assertSame([], $expression->getConditions());
    }

    public function testTupleLengthMustMatchFieldCount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected 2 values');

        new TupleInExpression(['author_id', 'site_id'], [['000000000000000000000001']]);
    }

    public function testQueryExpressionTupleIn(): void
    {
        $expression = new QueryExpression();
        $expression->tupleIn(
            ['author_id', 'site_id'],
            [['000000000000000000000001', 10]],
        );

        $this->assertEquals([
            '$or' => [
                ['author_id' => '000000000000000000000001', 'site_id' => 10],
            ],
        ], $expression->getConditions());
    }
}
