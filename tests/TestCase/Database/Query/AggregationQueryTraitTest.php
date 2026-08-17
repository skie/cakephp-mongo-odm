<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Query;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Query\SelectQuery;

/**
 * Tests the aggregation sugar methods provided by AggregationQueryTrait.
 */
class AggregationQueryTraitTest extends TestCase
{
    public function testLookupSimpleForm(): void
    {
        $query = (new SelectQuery())
            ->lookup('Comments', [
                'localField' => 'author_id',
                'foreignField' => '_id',
                'as' => 'comments',
            ]);

        self::assertSame([
            ['$lookup' => ['from' => 'Comments', 'localField' => 'author_id', 'foreignField' => '_id', 'as' => 'comments']],
        ], $query->compile()['pipeline']);
    }

    public function testLookupPipelineClosure(): void
    {
        $query = (new SelectQuery())
            ->lookup('Comments', [
                'let' => ['authorId' => '$_id'],
                'pipeline' => static fn(SelectQuery $q): SelectQuery => $q->where(['approved' => true]),
                'as' => 'comments',
            ]);

        self::assertSame([
            ['$lookup' => [
                'from' => 'Comments',
                'let' => ['authorId' => '$_id'],
                'pipeline' => [['$match' => ['approved' => true]]],
                'as' => 'comments',
            ]],
        ], $query->compile()['pipeline']);
    }

    public function testStageSugarMethodsAppendInOrder(): void
    {
        $query = (new SelectQuery())
            ->unwind('$comments')
            ->addFields(['score' => ['$multiply' => ['$x', 2]]])
            ->sample(10)
            ->sortByCount('category')
            ->unsetStage('obsolete');

        self::assertSame([
            ['$unwind' => ['path' => '$comments']],
            ['$addFields' => ['score' => ['$multiply' => ['$x', 2]]]],
            ['$sample' => ['size' => 10]],
            ['$sortByCount' => '$category'],
            ['$unset' => ['obsolete']],
        ], $query->compile()['pipeline']);
    }

    public function testReplaceAndSetSugarMethods(): void
    {
        $query = (new SelectQuery())
            ->replaceRoot('$author')
            ->setFields(['published' => true])
            ->unionWith('archives');

        self::assertSame([
            ['$replaceRoot' => ['newRoot' => '$author']],
            ['$set' => ['published' => true]],
            ['$unionWith' => ['coll' => 'archives']],
        ], $query->compile()['pipeline']);
    }

    /**
     * addFields/setFields accept nested func() expressions (not only raw BSON).
     */
    public function testAddFieldsWithNestedFuncExpressions(): void
    {
        $query = new SelectQuery();
        $f = $query->func();
        $query->addFields([
            'title_upper' => $f->toUpper(['title' => 'identifier']),
            'score' => $f->multiply(
                $f->add('$x', 1),
                2,
            ),
        ]);

        self::assertSame([
            ['$addFields' => [
                'title_upper' => ['$toUpper' => '$title'],
                'score' => ['$multiply' => [
                    ['$add' => ['$x', 1]],
                    2,
                ]],
            ]],
        ], $query->compile()['pipeline']);
    }

    /**
     * setFields also renders func() values directly (and nested).
     */
    public function testSetFieldsWithNestedFuncExpressions(): void
    {
        $query = new SelectQuery();
        $f = $query->func();
        $query->setFields([
            'snippet' => $f->substr(['title' => 'identifier'], 0, 5),
        ]);

        self::assertSame([
            ['$set' => [
                'snippet' => ['$substrCP' => ['$title', 0, 5]],
            ]],
        ], $query->compile()['pipeline']);
    }
}
