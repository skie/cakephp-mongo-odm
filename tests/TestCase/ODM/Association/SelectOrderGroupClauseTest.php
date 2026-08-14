<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Association;

use Cake\Database\Expression\OrderClauseExpression;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;

/**
 * Isolated coverage for the ODM query-layer field resolution solved in
 * `docs/ORM-Database-Boundary-Proposals.md` (P1/P2/P3/P4b/P5):
 * `select()` projection + `orderBy()`/`groupBy()` on aliased fields, closures,
 * and eager-loaded associations (belongsTo / hasOne / hasMany).
 *
 * The Database layer stays array-based; the ODM query translates
 * `Alias.field`’ bare field and `OrderClauseExpression’ `[field => dir]`
 * before the compiler sees them.
 */
class SelectOrderGroupClauseTest extends TestCase
{
    /**
     * Fixtures to be loaded.
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.Crustum/Mongo.Articles',
        'plugin.Crustum/Mongo.Authors',
        'plugin.Crustum/Mongo.Audits',
        'plugin.Crustum/Mongo.Comments',
        'plugin.Crustum/Mongo.Profiles',
        'plugin.Crustum/Mongo.Users',
    ];

    public function testBelongsToContainWithSelectTrimsRootAndLoadsAssociation(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->belongsTo('Authors');

        $result = $articles->find()
            ->select(['Articles._id', 'Articles.title', 'Articles.author_id'])
            ->contain(['Authors'])
            ->where(['Articles._id' => '000000000000000000000001'])
            ->firstOrFail();

        $this->assertSame('First Article', $result->title);
        $this->assertSame('000000000000000000000001', $result->getId());
        $this->assertNotEmpty($result->author);
        $this->assertSame('mariano', $result->author->name);
    }

    public function testBelongsToContainWithSelectExcludesUnselectedRootFields(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->belongsTo('Authors');

        $result = $articles->find()
            ->select(['Articles.title', 'Articles.author_id'])
            ->contain(['Authors'])
            ->where(['Articles._id' => '000000000000000000000001'])
            ->firstOrFail();

        $this->assertSame('First Article', $result->title);
        $this->assertArrayNotHasKey('body', $result->toArray());
        $this->assertNotEmpty($result->author);
    }

    public function testHasManyContainWithSelectAndSort(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->hasMany('Comments');

        $result = $articles->find()
            ->select(['Articles._id', 'Articles.title'])
            ->contain(['Comments' => ['sort' => ['comment' => 'DESC']]])
            ->where(['Articles._id' => '000000000000000000000001'])
            ->firstOrFail();
        $this->assertNotEmpty($result->comments);
        $comments = array_map(fn($c) => $c->comment, $result->comments);
        $sorted = $comments;
        sort($sorted);
        $this->assertSame(array_reverse($sorted), $comments, 'Comments should be DESC sorted');
    }

    public function testHasManyContainFieldsSlimsAssociatedRows(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->hasMany('Comments');

        $result = $articles->find()
            ->contain(['Comments' => ['fields' => ['comment']]])
            ->where(['Articles._id' => '000000000000000000000001'])
            ->firstOrFail();

        $this->assertNotEmpty($result->comments);
        foreach ($result->comments as $comment) {
            $this->assertNotEmpty($comment->comment);
            $this->assertArrayNotHasKey('published', $comment->toArray());
        }
    }

    public function testOrderByAliasedFieldDesc(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $rows = $articles->find()
            ->select(['Articles._id', 'Articles.title'])
            ->orderBy(['Articles.title' => 'DESC'])
            ->all()
            ->toArray();

        $this->assertCount(3, $rows);
        $titles = array_map(fn($r) => $r->title, $rows);
        $this->assertSame(['Third Article', 'Second Article', 'First Article'], $titles);
    }

    public function testOrderByStringForm(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $rows = $articles->find()
            ->select(['Articles._id', 'Articles.title'])
            ->orderBy('Articles.title ASC')
            ->all()
            ->toArray();

        $titles = array_map(fn($r) => $r->title, $rows);
        $this->assertSame(['First Article', 'Second Article', 'Third Article'], $titles);
    }

    public function testOrderByOrderClauseExpression(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $rows = $articles->find()
            ->select(['Articles._id', 'Articles.title'])
            ->orderBy(new OrderClauseExpression('Articles.title', 'DESC'))
            ->all()
            ->toArray();

        $titles = array_map(fn($r) => $r->title, $rows);
        $this->assertSame(['Third Article', 'Second Article', 'First Article'], $titles);
    }

    public function testOrderByClosure(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $rows = $articles->find()
            ->select(['Articles._id', 'Articles.title'])
            ->orderBy(fn(): array => ['Articles.title' => 'ASC'])
            ->all()
            ->toArray();

        $titles = array_map(fn($r) => $r->title, $rows);
        $this->assertSame(['First Article', 'Second Article', 'Third Article'], $titles);
    }

    public function testWhereClosureResolvesAliasedCondition(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $result = $articles->find()
            ->where(fn($exp) => $exp->eq('Articles._id', '000000000000000000000002'))
            ->firstOrFail();

        $this->assertSame('Second Article', $result->title);
    }

    public function testWhereClosureWithOrConjunction(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $rows = $articles->find()
            ->select(['Articles._id', 'Articles.title'])
            ->where(fn($exp) => $exp->or([
                ['Articles.title' => 'First Article'],
                ['Articles.title' => 'Third Article'],
            ]))
            ->orderBy(['Articles.title' => 'ASC'])
            ->all()
            ->toArray();

        $titles = array_map(fn($r) => $r->title, $rows);
        $this->assertSame(['First Article', 'Third Article'], $titles);
    }

    public function testSelectClosure(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $result = $articles->find()
            ->select(fn(): array => ['Articles._id', 'Articles.title'])
            ->where(['Articles._id' => '000000000000000000000001'])
            ->firstOrFail();

        $this->assertSame('First Article', $result->title);
        $this->assertArrayNotHasKey('body', $result->toArray());
    }

    public function testSelectClosureWithContainsBelongsTo(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->belongsTo('Authors');

        $result = $articles->find()
            ->select(fn(): array => ['Articles._id', 'Articles.title'])
            ->contain(['Authors'])
            ->where(['Articles._id' => '000000000000000000000001'])
            ->firstOrFail();

        $this->assertSame('First Article', $result->title);
        $this->assertNotEmpty($result->author);
        $this->assertSame('mariano', $result->author->name);
    }

    public function testSelectClosureWithHasMany(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->hasMany('Comments');

        $result = $articles->find()
            ->select(fn(): array => ['Articles._id', 'Articles.title'])
            ->contain(['Comments' => ['sort' => ['comment' => 'ASC']]])
            ->where(['Articles._id' => '000000000000000000000001'])
            ->firstOrFail();

        $this->assertSame('First Article', $result->title);
        $this->assertNotEmpty($result->comments);
    }

    public function testOrderByClosureWithHasMany(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->hasMany('Comments');

        $result = $articles->find()
            ->contain(['Comments' => ['sort' => fn(): array => ['comment' => 'DESC']]])
            ->where(['Articles._id' => '000000000000000000000001'])
            ->firstOrFail();

        $this->assertNotEmpty($result->comments);
        $comments = array_map(fn($c) => $c->comment, $result->comments);
        $sorted = $comments;
        sort($sorted);
        $this->assertSame(array_reverse($sorted), $comments);
    }

    public function testGroupByClosure(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $rows = $articles->find()
            ->select(['Articles.published'])
            ->groupBy(fn(): array => ['Articles.published'])
            ->all()
            ->toArray();

        $this->assertNotEmpty($rows);
    }

    public function testContainConditionsClosureOnHasMany(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->hasMany('Comments');

        $result = $articles->find()
            ->contain(['Comments' => ['conditions' => fn(): array => ['published' => 'Y']]])
            ->where(['Articles._id' => '000000000000000000000001'])
            ->firstOrFail();

        $this->assertNotEmpty($result->comments);
        foreach ($result->comments as $comment) {
            $this->assertSame('Y', $comment->published);
        }
    }

    public function testContainFieldsClosureOnHasMany(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->hasMany('Comments');

        $result = $articles->find()
            ->contain(['Comments' => ['fields' => fn(): array => ['comment']]])
            ->where(['Articles._id' => '000000000000000000000001'])
            ->firstOrFail();

        $this->assertNotEmpty($result->comments);
        foreach ($result->comments as $comment) {
            $this->assertNotEmpty($comment->comment);
            $this->assertArrayNotHasKey('published', $comment->toArray());
        }
    }

    public function testContainQueryBuilderClosureFiltersHasMany(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->hasMany('Comments');

        $result = $articles->find()
            ->contain(['Comments' => fn($q) => $q->where(['Comments.published' => 'Y'])])
            ->where(['Articles._id' => '000000000000000000000001'])
            ->firstOrFail();

        $this->assertNotEmpty($result->comments);
        foreach ($result->comments as $comment) {
            $this->assertSame('Y', $comment->published);
        }
    }

    public function testContainSortClosureOnHasMany(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->hasMany('Comments');

        $result = $articles->find()
            ->contain(['Comments' => ['sort' => fn(): array => ['comment' => 'DESC']]])
            ->where(['Articles._id' => '000000000000000000000001'])
            ->firstOrFail();

        $this->assertNotEmpty($result->comments);
        $comments = array_map(fn($c) => $c->comment, $result->comments);
        $sorted = $comments;
        sort($sorted);
        $this->assertSame(array_reverse($sorted), $comments);
    }

    public function testGroupByAliasedField(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $rows = $articles->find()
            ->select(['Articles.published'])
            ->groupBy(['Articles.published'])
            ->all()
            ->toArray();

        $this->assertNotEmpty($rows);
    }

    public function testHasOneContainWithSelect(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->hasOne('Comments');

        $result = $articles->find()
            ->select(['Articles._id', 'Articles.title'])
            ->contain(['Comments'])
            ->where(['Articles._id' => '000000000000000000000001'])
            ->firstOrFail();

        $this->assertSame('First Article', $result->title);
        $this->assertNotEmpty($result->comment);
        $this->assertArrayHasKey('comment', $result->comment->toArray());
    }

    public function testUpdateAllConditionsResolveAlias(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $affected = $articles->updateAll(
            ['published' => 'N'],
            ['Articles._id' => '000000000000000000000003'],
        );

        $this->assertSame(1, $affected);
        $reloaded = $articles->get('000000000000000000000003');
        $this->assertSame('N', $reloaded->published);
    }

    public function testDeleteAllConditionsResolveAlias(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $affected = $articles->deleteAll(['Articles._id' => '000000000000000000000003']);

        $this->assertSame(1, $affected);
        $this->assertCount(2, $articles->find('all')->all());
    }

    public function testExistsConditionsResolveAlias(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $this->assertTrue($articles->exists(['Articles._id' => '000000000000000000000001']));
        $this->assertFalse($articles->exists(['Articles._id' => '000000000000000000009999']));
    }

    public function testNestedClosureOrWithEq(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $rows = $articles->find()
            ->select(['Articles._id', 'Articles.title'])
            ->where(fn($e) => $e->or(fn($e2) => $e2->eq('Articles.title', 'First Article')))
            ->all()
            ->toArray();

        $this->assertCount(1, $rows);
        $this->assertSame('First Article', $rows[0]->title);
    }

    public function testNestedClosureAndNot(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $rows = $articles->find()
            ->select(['Articles._id', 'Articles.title'])
            ->where(fn($e) => $e->and(
                fn($e2) => $e2
                    ->not(fn($e3) => $e3->eq('Articles.title', 'First Article')),
            ))
            ->orderBy(['Articles.title' => 'ASC'])
            ->all()
            ->toArray();

        $titles = array_map(fn($r) => $r->title, $rows);
        $this->assertSame(['Second Article', 'Third Article'], $titles);
    }

    public function testWhereOperatorGreaterThan(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $rows = $articles->find()
            ->select(['Articles._id', 'Articles.title'])
            ->where(['Articles.published >' => 'N'])
            ->orderBy(['Articles.title' => 'ASC'])
            ->all()
            ->toArray();

        $this->assertNotEmpty($rows);
        $titles = array_map(fn($r) => $r->title, $rows);
        $this->assertSame(['First Article', 'Second Article', 'Third Article'], $titles);
    }

    public function testWhereInAliasedField(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $rows = $articles->find()
            ->select(['Articles._id', 'Articles.title'])
            ->where(['Articles._id IN' => ['000000000000000000000001', '000000000000000000000003']])
            ->orderBy(['Articles.title' => 'ASC'])
            ->all()
            ->toArray();

        $titles = array_map(fn($r) => $r->title, $rows);
        $this->assertSame(['First Article', 'Third Article'], $titles);
    }

    public function testSelectExcludeForm(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $result = $articles->find()
            ->select(['Articles.body' => 0])
            ->where(['Articles._id' => '000000000000000000000001'])
            ->firstOrFail();

        $this->assertSame('First Article', $result->title);
        $this->assertArrayNotHasKey('body', $result->toArray());
    }

    public function testNestedContainBelongsToThenHasMany(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->belongsTo('Authors');
        $articles->hasMany('Comments');

        $result = $articles->find()
            ->contain(['Authors', 'Comments'])
            ->where(['Articles._id' => '000000000000000000000001'])
            ->firstOrFail();

        $this->assertNotEmpty($result->author);
        $this->assertSame('mariano', $result->author->name);
        $this->assertNotEmpty($result->comments);
    }

    public function testDeepContainCommentsUsers(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $comments = $this->getCollectionLocator()->get('Comments');
        $comments->belongsTo('Users');

        $articles->hasMany('Comments', ['strategy' => 'select']);

        $result = $articles->find()
            ->contain(['Comments' => ['Users']])
            ->where(['Articles._id' => '000000000000000000000001'])
            ->firstOrFail();

        $this->assertNotEmpty($result->comments);
        $this->assertNotEmpty($result->comments[0]->user);
    }

    public function testThreeLevelContain(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $comments = $this->getCollectionLocator()->get('Comments');
        $users = $this->getCollectionLocator()->get('Users');

        $comments->belongsTo('Users');
        $users->hasMany('Comments');

        $articles->hasMany('Comments', ['strategy' => 'select']);

        $result = $articles->find()
            ->contain(['Comments' => ['Users' => ['Comments']]])
            ->where(['Articles._id' => '000000000000000000000001'])
            ->firstOrFail();

        $this->assertNotEmpty($result->comments);
        $this->assertNotEmpty($result->comments[0]->user);
        $this->assertArrayHasKey('comments', $result->comments[0]->user->toArray());
    }

    public function testEqualFieldsCompilesToExpr(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $result = $articles->find()
            ->where(fn($exp) => $exp->equalFields('Articles.author_id', 'Articles.published'))
            ->first();

        $this->assertNull($result);
    }

    public function testJoinPolymorphic(): void
    {
        $users = $this->getCollectionLocator()->get('Users');

        $result = $users->find()
            ->join('profiles', function ($q): void {
                $q->where(fn($exp) => $exp
                    ->equalFields('Users.username', 'profiles.first_name'));
            }, ['asArray' => true])
            ->where(['Users.username' => 'nate'])
            ->firstOrFail();

        $this->assertNotEmpty($result->profiles);
        $this->assertSame('abele', $result->profiles[0]->last_name);
    }

    public function testJoinWithValueCondition(): void
    {
        $users = $this->getCollectionLocator()->get('Users');

        $result = $users->find()
            ->join('profiles', function ($q): void {
                $q->where(fn($exp) => $exp
                    ->equalFields('Users.username', 'profiles.first_name')
                    ->eq('profiles.is_active', false));
            }, ['asArray' => true])
            ->where(['Users.username' => 'nate'])
            ->firstOrFail();

        $this->assertNotEmpty($result->profiles);
        $this->assertSame('abele', $result->profiles[0]->last_name);
    }

    public function testJoinAliased(): void
    {
        $users = $this->getCollectionLocator()->get('Users');

        $result = $users->find()
            ->join(['prof' => 'profiles'], function ($q): void {
                $q->where(fn($exp) => $exp
                    ->equalFields('Users.username', 'prof.first_name'));
            }, ['asArray' => true])
            ->where(['Users.username' => 'mariano'])
            ->firstOrFail();

        $this->assertNotEmpty($result->prof);
        $this->assertSame('iglesias', $result->prof[0]->last_name);
    }

    public function testLeftJoinPreservesNulls(): void
    {
        $users = $this->getCollectionLocator()->get('Users');

        // leftJoin keeps source rows even when the joined collection has no
        // match (SQL LEFT JOIN semantics via $unwind preserveNull).
        $result = $users->find()
            ->leftJoin('profiles', function ($q): void {
                $q->where(fn($exp) => $exp
                    ->equalFields('Users.username', 'profiles.first_name'));
            })
            ->where(['Users.username' => 'mariano'])
            ->firstOrFail();

        $this->assertSame('mariano', $result->username);
        $this->assertArrayHasKey('profiles', $result->toArray());
    }

    public function testInnerJoinDropsNoMatch(): void
    {
        $users = $this->getCollectionLocator()->get('Users');

        // `join` is INNER: a username that matches no profile is dropped.
        $rows = $users->find()
            ->join('profiles', function ($q): void {
                $q->where(fn($exp) => $exp
                    ->equalFields('Users.username', 'profiles.first_name'));
            })
            ->where(['Users.username' => 'mariano'])
            ->all()
            ->toArray();

        $this->assertCount(1, $rows);
        $this->assertSame('mariano', $rows[0]->username);
    }

    public function testLeftJoinAsArray(): void
    {
        $users = $this->getCollectionLocator()->get('Users');

        // `asArray` keeps the joined docs as a nested array (no $unwind).
        $result = $users->find()
            ->leftJoin('profiles', function ($q): void {
                $q->where(fn($exp) => $exp
                    ->equalFields('Users.username', 'profiles.first_name'));
            }, ['asArray' => true])
            ->where(['Users.username' => 'mariano'])
            ->firstOrFail();

        $this->assertNotEmpty($result->profiles);
        $this->assertCount(1, $result->profiles);
    }

    public function testJoinPolymorphicForeignKey(): void
    {
        $users = $this->getCollectionLocator()->get('Users');

        // `foreign_key` does not end in `_id`; type resolution comes from the
        // schema type map (objectid), so `Users._id` matches `audits.foreign_key`.
        $result = $users->find()
            ->join('audits', function ($q): void {
                $q->where(fn($exp) => $exp
                    ->equalFields('Users._id', 'audits.foreign_key')
                    ->eq('audits.model', 'Users'));
            }, ['asArray' => true])
            ->where(['Users._id' => '000000000000000000000002'])
            ->firstOrFail();

        $this->assertNotEmpty($result->audits);
        $this->assertCount(1, $result->audits);
        $this->assertSame('updated user 2', $result->audits[0]->note);
    }
}
