<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use Cake\Database\Exception\DatabaseException;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use Mockery;

/**
 * Tests the features related to proxying methods from the Association
 * class to the BaseCollection class
 */
class AssociationProxyTest extends TestCase
{
    /**
     * Fixtures to be loaded
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.Crustum/Mongo.Articles', 'plugin.Crustum/Mongo.Authors', 'plugin.Crustum/Mongo.Comments',
    ];

    /**
     * Tests that it is possible to get associations as a property
     */
    public function testAssociationAsProperty(): void
    {
        $articles = $this->getCollectionLocator()->get('articles');
        $articles->hasMany('comments');
        $articles->belongsTo('authors');
        $this->assertTrue(isset($articles->authors));
        $this->assertTrue(isset($articles->comments));
        $this->assertFalse(isset($articles->posts));
        $this->assertSame($articles->getAssociation('authors'), $articles->authors);
        $this->assertSame($articles->getAssociation('comments'), $articles->comments);
    }

    /**
     * Tests that getting a bad property throws exception
     */
    public function testGetBadAssociation(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('You have not defined');
        $articles = $this->getCollectionLocator()->get('articles');
        $articles->posts;
    }

    /**
     * Test that find() with empty conditions generates valid SQL
     */
    public function testFindEmptyConditions(): void
    {
        $this->markTestSkipped('ODM missing `list` finder + limit named-arg routing on association proxy — see F21');
        $collection = $this->getCollectionLocator()->get('Users');
        $collection->hasMany('Articles', [
            'foreignKey' => 'author_id',
            'conditions' => [],
        ]);
        $query = $collection->Articles->find('list', limit: 2);
        $this->assertCount(2, $query->all());
    }

    /**
     * Tests that the proxied updateAll will preserve conditions set for the association
     */
    public function testUpdateAllFromAssociation(): void
    {
        $this->markTestSkipped('ODM missing association conditions merge in `Association::updateAll` — see F20');
        $articles = $this->getCollectionLocator()->get('articles');
        $comments = $this->getCollectionLocator()->get('comments');
        $articles->hasMany('comments', ['conditions' => ['published' => 'Y']]);
        $articles->comments->updateAll(['comment' => 'changed'], ['article_id' => '000000000000000000000001']);
        $changed = $comments->find()->where(['comment' => 'changed'])->count();
        $this->assertSame(3, $changed);
    }

    /**
     * Tests that the proxied updateAll uses the association finder
     */
    public function testUpdateAllFromAssociationFinder(): void
    {
        $this->markTestSkipped('ODM missing association finder merge in `Association::updateAll` — see F20');
        $this->setAppNamespace('TestApp');

        $articles = $this->getCollectionLocator()->get('articles');
        $authors = $this->getCollectionLocator()->get('authors');
        // Exclude a record from the published finder.
        $articles->updateAll(['published' => 'N'], ['_id' => '000000000000000000000001']);

        $authors->Articles->setFinder('published');
        $authors->Articles->updateAll(['published' => '?'], '1=1');
        $missed = $articles->find()->where(['published' => 'Y'])->count();
        $this->assertSame(0, $missed);

        $remaining = $articles->find()->where(['published' => 'N'])->count();
        $this->assertSame(1, $remaining);
    }

    /**
     * Tests that the proxied deleteAll preserves conditions set for the association
     */
    public function testDeleteAllFromAssociationConditions(): void
    {
        $this->markTestSkipped('ODM missing association conditions merge in `Association::deleteAll` — see F20');
        $articles = $this->getCollectionLocator()->get('articles');
        $comments = $this->getCollectionLocator()->get('comments');
        $articles->hasMany('comments', ['conditions' => ['published' => 'Y']]);
        $articles->comments->deleteAll(['article_id' => '000000000000000000000001']);
        $remaining = $comments->find()->where(['article_id' => '000000000000000000000001'])->count();
        $this->assertSame(1, $remaining);
    }

    /**
     * Tests that the proxied deleteAll uses the association finder
     */
    public function testDeleteAllFromAssociationFinder(): void
    {
        $this->markTestSkipped('ODM missing association finder merge in `Association::deleteAll` — see F20');
        $this->setAppNamespace('TestApp');

        $articles = $this->getCollectionLocator()->get('articles');
        $authors = $this->getCollectionLocator()->get('authors');
        // Exclude a record from the published finder.
        $articles->updateAll(['published' => 'N'], ['_id' => '000000000000000000000001']);

        $authors->Articles->setFinder('published');
        $authors->Articles->deleteAll('1=1');
        $remaining = $articles->find()->all();
        $this->assertCount(1, $remaining);
        $this->assertSame(['N'], $remaining->extract('published')->toList());
    }

    /**
     * Tests that it is possible to get associations as a property
     */
    public function testAssociationAsPropertyProxy(): void
    {
        $articles = $this->getCollectionLocator()->get('articles');
        $authors = $this->getCollectionLocator()->get('authors');
        $articles->belongsTo('authors');
        $authors->hasMany('comments');
        $this->assertTrue(isset($articles->authors->comments));
        $this->assertSame($authors->getAssociation('comments'), $articles->authors->comments);
    }

    /**
     * Tests that isset on association only returns true for associations
     */
    public function testAssociationIssetOnlyChecksAssociations(): void
    {
        $articles = $this->getCollectionLocator()->get('articles');
        $authors = $this->getCollectionLocator()->get('authors');
        $articles->belongsTo('authors');
        $authors->hasMany('comments');

        // Existing association returns true
        $this->assertTrue(isset($articles->authors->comments));

        // Non-existing association returns false
        $this->assertFalse(isset($articles->authors->posts));

        // Non-association properties return false (table has these but they're not associations)
        $this->assertFalse(isset($articles->authors->_table));
        $this->assertFalse(isset($articles->authors->entityClass));
    }

    /**
     * Tests that methods are proxied from the Association to the target table
     */
    public function testAssociationMethodProxy(): void
    {
        $articles = $this->getCollectionLocator()->get('articles');
        $spy = Mockery::spy(BaseCollection::class);
        $articles->belongsTo('authors', [
            'target' => $spy,
        ]);

        $articles->authors->crazy('a', 'b');

        $spy
            ->shouldHaveReceived('crazy')
            ->with('a', 'b')
            ->once();
    }
}
