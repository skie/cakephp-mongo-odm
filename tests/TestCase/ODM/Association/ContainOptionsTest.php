<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Association;

use Crustum\Mongo\ODM\CollectionRegistry;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;

/**
 * Covers per-association containment options (fields/conditions/sort/limit)
 * applied by the eager-loading path — the ODM equivalent of cake60's joined
 * field selection, without SQL joins.
 */
class ContainOptionsTest extends TestCase
{
    /**
     * Fixtures to be loaded.
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.Crustum/Mongo.Articles',
        'plugin.Crustum/Mongo.Authors',
        'plugin.Crustum/Mongo.Comments',
    ];

    public function testPlainContainLoadsFullAssociatedDocument(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->belongsTo('Authors');

        $result = $articles->find()->contain('Authors')->firstOrFail();

        $this->assertNotEmpty($result->author);
        $this->assertSame('mariano', $result->author->name);
        $this->assertSame('000000000000000000000001', $result->author->getId());
    }

    public function testContainFieldsSlimsAssociatedDocument(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->belongsTo('Authors');

        $result = $articles->find()
            ->contain(['Authors' => ['fields' => ['name']]])
            ->firstOrFail();

        $this->assertNotEmpty($result->author);
        $this->assertSame('mariano', $result->author->name);
        $this->assertArrayNotHasKey('title', $result->author->toArray());
        $this->assertArrayNotHasKey('body', $result->author->toArray());
        $this->assertArrayNotHasKey('published', $result->author->toArray());
    }

    public function testContainConditionsFilterAssociatedDocuments(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->belongsTo('Authors');

        $result = $articles->find()
            ->contain(['Authors' => ['conditions' => ['name' => 'mariano']]])
            ->firstOrFail();
        $this->assertNotEmpty($result->author);
        $this->assertSame('mariano', $result->author->name);

        $noMatch = $articles->find()
            ->contain(['Authors' => ['conditions' => ['name' => 'nobody']]])
            ->firstOrFail();
        $this->assertNull($noMatch->author);
    }

    public function testContainConditionsOnHasMany(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->hasMany('Comments');

        $result = $articles->find()
            ->contain(['Comments' => ['conditions' => ['published' => 'Y']]])
            ->firstOrFail();

        $this->assertNotEmpty($result->comments);
        foreach ($result->comments as $comment) {
            $this->assertSame('Y', $comment->published);
        }
    }

    public function testContainFieldsOnHasMany(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->hasMany('Comments');

        $result = $articles->find()
            ->contain(['Comments' => ['fields' => ['comment']]])
            ->firstOrFail();

        $this->assertNotEmpty($result->comments);
        foreach ($result->comments as $comment) {
            $this->assertNotEmpty($comment->comment);
            $this->assertArrayNotHasKey('published', $comment->toArray());
        }
    }

    public function testContainSortAndLimitOnHasMany(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->hasMany('Comments');

        $result = $articles->find()
            ->contain(['Comments' => ['sort' => ['comment' => 'ASC'], 'limit' => 1]])
            ->firstOrFail();

        $this->assertNotEmpty($result->comments);
        $this->assertCount(1, $result->comments);
    }
}
