<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\FunctionsBuilder;
use Crustum\Mongo\Database\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests QueryBuilder condition parsing helpers used by find and aggregation.
 */
#[CoversClass(QueryBuilder::class)]
class QueryBuilderTest extends TestCase
{
    /**
     * Test splitConditionKey separates field names from operator suffixes.
     *
     * @return void
     */
    public function testSplitConditionKey(): void
    {
        $this->assertSame(['age', '>'], QueryBuilder::splitConditionKey('age >'));
        $this->assertSame(['name', 'not like'], QueryBuilder::splitConditionKey('name NOT LIKE'));
        $this->assertSame(['Authors._id', 'in'], QueryBuilder::splitConditionKey('Authors._id IN'));
        $this->assertSame(['title', 'like'], QueryBuilder::splitConditionKey('title LIKE'));
        $this->assertSame(['title', 'regex'], QueryBuilder::splitConditionKey('title REGEX'));
        $this->assertSame(['highlighted', '='], QueryBuilder::splitConditionKey('highlighted'));
    }

    /**
     * Test conditionFields strips operators and optional aliases.
     *
     * @return void
     */
    public function testConditionFields(): void
    {
        $builder = new QueryBuilder();
        $builder->setFieldResolver(static function (string $field): string {
            $dot = strpos($field, '.');
            if ($dot === false) {
                return $field;
            }

            return substr($field, $dot + 1);
        });

        $fields = $builder->conditionFields([
            'Tags.name LIKE' => 'tag%',
            'Tags.age >' => 18,
            'OR' => ['Tags.name' => 'x'],
            '$and' => [['Tags.name' => 'y']],
        ]);

        $this->assertSame(['name', 'age'], $fields);
    }

    /**
     * Test aggregationCompare compiles Cake where keys through parseCondition.
     *
     * @return void
     */
    public function testAggregationCompare(): void
    {
        $compiler = new QueryBuilder();
        $func = new FunctionsBuilder();

        $this->assertSame(
            ['$eq' => ['$$item.highlighted', true]],
            $compiler->aggregationCompare($func, '$$item.highlighted', 'highlighted', true)->getConditions(),
        );
        $this->assertSame(
            ['$gt' => ['$$item.age', 18]],
            $compiler->aggregationCompare($func, '$$item.age', 'age >', 18)->getConditions(),
        );
        $this->assertSame(
            ['$in' => ['$$item._id', [1, 2]]],
            $compiler->aggregationCompare($func, '$$item._id', '_id IN', [1, 2])->getConditions(),
        );
        $this->assertSame(
            ['$regexMatch' => ['input' => '$$item.name', 'regex' => '^tag.*$']],
            $compiler->aggregationCompare($func, '$$item.name', 'name LIKE', 'tag%')->getConditions(),
        );
        $this->assertSame(
            ['$not' => ['$regexMatch' => ['input' => '$$item.name', 'regex' => '^foo.*$']]],
            $compiler->aggregationCompare($func, '$$item.name', 'name NOT LIKE', 'foo%')->getConditions(),
        );
        $this->assertSame(
            ['$ne' => ['$$item.status', 'draft']],
            $compiler->aggregationCompare($func, '$$item.status', 'status !=', 'draft')->getConditions(),
        );
        $this->assertSame(
            ['$regexMatch' => ['input' => '$$item.title', 'regex' => '^Title #2$']],
            $compiler->aggregationCompare($func, '$$item.title', 'title REGEX', '^Title #2$')->getConditions(),
        );
    }

    /**
     * Test parse compiles LIKE and REGEX the same way find filters do.
     *
     * @return void
     */
    public function testParseLikeAndRegex(): void
    {
        $builder = new QueryBuilder();

        $this->assertSame(
            ['title' => ['$regex' => '^Title.*$']],
            $builder->parse(['title LIKE' => 'Title%']),
        );
        $this->assertSame(
            ['title' => ['$regex' => '^Title #2$']],
            $builder->parse(['title REGEX' => '^Title #2$']),
        );
    }
}
