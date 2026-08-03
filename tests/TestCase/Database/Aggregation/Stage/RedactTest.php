<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\Redact;

/**
 * Test case for Redact aggregation stage
 */
class RedactTest extends TestCase
{
    /**
     * Test basic redact stage
     */
    public function testBasicRedact(): void
    {
        $builder = new AggregationBuilder();
        $builder->redact(['$eq' => ['$level', 5]]);

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$redact', $pipeline[0]);
        $this->assertEquals(['$eq' => ['$level', 5]], $pipeline[0]['$redact']);
    }

    /**
     * Test redact with string expression
     */
    public function testRedactWithString(): void
    {
        $builder = new AggregationBuilder();
        $builder->redact('$$PRUNE');

        $pipeline = $builder->getPipeline();
        $this->assertEquals('$$PRUNE', $pipeline[0]['$redact']);
    }

    /**
     * Test redact stage directly
     */
    public function testRedactStageDirect(): void
    {
        $builder = new AggregationBuilder();
        $stage = new Redact($builder, ['$cond' => ['if' => ['$gt' => ['$level', 5]], 'then' => '$$PRUNE', 'else' => '$$KEEP']]);

        $expression = $stage->getExpression();
        $this->assertArrayHasKey('$redact', $expression);
        $this->assertArrayHasKey('$cond', $expression['$redact']);
    }
}
