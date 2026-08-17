<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use ArrayObject;
use Cake\Database\Driver\Sqlserver;
use Cake\Database\Exception\DatabaseException;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\I18n\I18n;
use Closure;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\ODM\RulesChecker;
use stdClass;

/**
 * Port of `Cake\Test\TestCase\ORM\RulesCheckerIntegrationTest` for the ODM layer.
 */
class RulesCheckerIntegrationTest extends TestCase
{
    /**
     * Fixtures to be loaded
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.Crustum/Mongo.Articles', 'plugin.Crustum/Mongo.Tags', 'plugin.Crustum/Mongo.ArticlesTags',
        'plugin.Crustum/Mongo.Authors', 'plugin.Crustum/Mongo.Comments',
        'plugin.Crustum/Mongo.SpecialTags', 'plugin.Crustum/Mongo.Categories',
        'plugin.Crustum/Mongo.SiteArticles', 'plugin.Crustum/Mongo.SiteAuthors',
        'plugin.Crustum/Mongo.UniqueAuthors',
    ];

    /**
     * Registers the SiteAuthors collection with its composite primary key.
     *
     * In cake60 the `site_authors` table declares a composite primary key
     * `(id, site_id)` in the SQL schema, which makes `belongsTo('SiteAuthors')`
     * resolve to a composite binding key. Here the ODM collection mirrors that
     * with `(_id, site_id)` so `existsIn(['author_id', 'site_id'], ...)` rules
     * align their foreign keys with the association binding key.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->getCollectionLocator()->get('SiteAuthors')->setPrimaryKey(['_id', 'site_id']);
    }

    /**
     * Tests saving belongsTo association and get a validation error
     */
    public function testSaveBelongsToWithValidationError(): void
    {
        $document = new Document([
            'title' => 'A Title',
            'body' => 'A body',
        ]);
        $document->author = new Document([
            'name' => 'Jose',
        ]);

        $collection = $this->getCollectionLocator()->get('articles');
        $collection->belongsTo('authors');
        $collection->getAssociation('authors')
            ->getTarget()
            ->rulesChecker()
            ->add(
                function (Document $author, array $options) use ($collection): false {
                    $this->assertSame($options['repository'], $collection->getAssociation('authors')->getTarget());

                    return false;
                },
                ['errorField' => 'name', 'message' => 'This is an error'],
            );

        $this->assertFalse($collection->save($document));
        $this->assertTrue($document->isNew());
        $this->assertTrue($document->author->isNew());
        $this->assertNull($document->get('author_id'));
        $this->assertNotEmpty($document->author->getError('name'));
        $this->assertEquals(['This is an error'], $document->author->getError('name'));
    }

    /**
     * Tests saving hasOne association and returning a validation error will
     * abort the saving process
     */
    public function testSaveHasOneWithValidationError(): void
    {
        $document = new Document([
            'name' => 'Jose',
        ]);
        $document->article = new Document([
            'title' => 'A Title',
            'body' => 'A body',
        ]);

        $collection = $this->getCollectionLocator()->get('authors');
        $collection->hasOne('articles');
        $collection->getAssociation('articles')
            ->getTarget()
            ->rulesChecker()
            ->add(
                fn(EntityInterface $document): false => false,
                ['errorField' => 'title', 'message' => 'This is an error'],
            );

        $this->assertFalse($collection->save($document));
        $this->assertTrue($document->isNew());
        $this->assertTrue($document->article->isNew());
        $this->assertNull($document->article->id);
        $this->assertNull($document->article->get('author_id'));
        $this->assertFalse($document->article->isDirty('author_id'));
        $this->assertNotEmpty($document->article->getError('title'));
        $this->assertSame('A Title', $document->article->getInvalidField('title'));
    }

    /**
     * Tests saving multiple entities in a hasMany association and getting and
     * error while saving one of them. It should abort all the save operation
     * when options are set to defaults
     */
    public function testSaveHasManyWithErrorsAtomic(): void
    {
        $document = new Document([
            'name' => 'Jose',
        ]);
        $document->articles = [
            new Document([
                'title' => '1',
                'body' => 'A body',
            ]),
            new Document([
                'title' => 'Another Title',
                'body' => 'Another body',
            ]),
        ];

        $collection = $this->getCollectionLocator()->get('authors');
        $collection->hasMany('articles');
        $collection->getAssociation('articles')
            ->getTarget()
            ->rulesChecker()
            ->add(
                function (Document $document, array $options) use ($collection): bool {
                    $this->assertSame($collection, $options['sourceCollection']);

                    return $document->title === '1';
                },
                ['errorField' => 'title', 'message' => 'This is an error'],
            );

        $this->assertFalse($collection->save($document));
        $this->assertTrue($document->isNew());
        $this->assertTrue($document->articles[0]->isNew());
        $this->assertTrue($document->articles[1]->isNew());
        $this->assertNull($document->articles[0]->id);
        $this->assertNull($document->articles[1]->id);
        $this->assertNull($document->articles[0]->author_id);
        $this->assertNull($document->articles[1]->author_id);
        $this->assertEmpty($document->articles[0]->getErrors());
        $this->assertNotEmpty($document->articles[1]->getErrors());
    }

    /**
     * Tests that it is possible to continue saving hasMany associations
     * even if any of the records fail validation when atomic is set
     * to false
     */
    public function testSaveHasManyWithErrorsNonAtomic(): void
    {
        $document = new Document([
            'name' => 'Jose',
        ]);
        $document->articles = [
            new Document([
                'title' => 'A title',
                'body' => 'A body',
            ]),
            new Document([
                'title' => '1',
                'body' => 'Another body',
            ]),
        ];

        $collection = $this->getCollectionLocator()->get('authors');
        $collection->hasMany('articles');
        $collection->getAssociation('articles')
            ->getTarget()
            ->rulesChecker()
            ->add(
                fn(Document $article): bool => is_numeric($article->title),
                ['errorField' => 'title', 'message' => 'This is an error'],
            );

        $result = $collection->save($document, ['atomic' => false]);
        $this->assertSame($document, $result);
        $this->assertFalse($document->isNew());
        $this->assertTrue($document->articles[0]->isNew());
        $this->assertFalse($document->articles[1]->isNew());
        $this->assertNotEmpty($document->articles[1]->getId());
        $this->assertNull($document->articles[0]->getId());
        $this->assertNotEmpty($document->articles[0]->getError('title'));
    }

    /**
     * Tests saving belongsToMany records with a validation error in a joint entity
     */
    public function testSaveBelongsToManyWithValidationErrorInJointEntity(): void
    {
        $document = new Document([
            'title' => 'A Title',
            'body' => 'A body',
        ]);
        $document->tags = [
            new Document([
                'name' => 'Something New',
            ]),
            new Document([
                'name' => '100',
            ]),
        ];
        $collection = $this->getCollectionLocator()->get('articles');
        $collection->belongsToMany('tags');
        $collection->getAssociation('tags')
            ->junction()
            ->rulesChecker()
            ->add(fn(Document $document): false => false);

        $this->assertFalse($collection->save($document));
        $this->assertTrue($document->isNew());
        $this->assertTrue($document->tags[0]->isNew());
        $this->assertTrue($document->tags[1]->isNew());
        $this->assertNull($document->tags[0]->getId());
        $this->assertNull($document->tags[1]->getId());
        $this->assertNull($document->tags[0]->_joinData);
        $this->assertNull($document->tags[1]->_joinData);
    }

    /**
     * Tests saving belongsToMany records with a validation error in a joint entity
     * and atomic set to false
     */
    public function testSaveBelongsToManyWithValidationErrorInJointEntityNonAtomic(): void
    {
        $document = new Document([
            'title' => 'A Title',
            'body' => 'A body',
        ]);
        $document->tags = [
            new Document([
                'name' => 'Something New',
            ]),
            new Document([
                'name' => 'New one',
            ]),
        ];
        $collection = $this->getCollectionLocator()->get('articles');
        $collection->belongsToMany('tags');
        $collection->getAssociation('tags')
            ->junction()
            ->rulesChecker()
            ->add(fn(Document $document): false => false);

        $this->assertSame($document, $collection->save($document, ['atomic' => false]));
        $this->assertFalse($document->isNew());
        $this->assertFalse($document->tags[0]->isNew());
        $this->assertFalse($document->tags[1]->isNew());
        $this->assertNotEmpty($document->tags[0]->getId());
        $this->assertNotEmpty($document->tags[1]->getId());
        $this->assertTrue($document->tags[0]->_joinData->isNew());
        $this->assertTrue($document->tags[1]->_joinData->isNew());
        $this->assertSame($document->getId(), $document->tags[0]->_joinData->article_id);
        $this->assertSame($document->tags[0]->getId(), $document->tags[0]->_joinData->tag_id);
        $this->assertSame($document->tags[1]->getId(), $document->tags[1]->_joinData->tag_id);
    }

    /**
     * Test adding rule with name
     */
    public function testAddingRuleWithName(): void
    {
        $document = new Document([
            'name' => 'larry',
        ]);

        $collection = $this->getCollectionLocator()->get('Authors');
        $rules = $collection->rulesChecker();
        $rules->add(
            fn(): false => false,
            'ruleName',
            ['errorField' => 'name'],
        );

        $this->assertFalse($collection->save($document));
        $this->assertEquals(['ruleName' => 'invalid'], $document->getError('name'));
    }

    /**
     * Ensure that add(isUnique()) only invokes a rule once.
     */
    public function testIsUniqueRuleSingleInvocation(): void
    {
        $document = new Document([
            'name' => 'larry',
        ]);

        $collection = $this->getCollectionLocator()->get('Authors');
        $rules = $collection->rulesChecker();
        $rules->add($rules->isUnique(['name']), 'isUnique', ['errorField' => 'title']);
        $this->assertFalse($collection->save($document));

        $this->assertEquals(
            ['isUnique' => 'This value is already in use'],
            $document->getError('title'),
            'Provided field should have errors',
        );
        $this->assertEmpty($document->getError('name'), 'Errors should not apply to original field.');
    }

    /**
     * Tests the isUnique domain rule
     */
    public function testIsUniqueDomainRule(): void
    {
        $document = new Document([
            'name' => 'larry',
        ]);

        $collection = $this->getCollectionLocator()->get('Authors');
        $rules = $collection->rulesChecker();
        $rules->add($rules->isUnique(['name']));

        $this->assertFalse($collection->save($document));
        $this->assertEquals(['isUnique' => 'This value is already in use'], $document->getError('name'));

        $document->name = 'jose';
        $this->assertSame($document, $collection->save($document));

        $document = $collection->get('000000000000000000000001');
        $document->setDirty('name', true);
        $this->assertSame($document, $collection->save($document));
    }

    /**
     * Tests isUnique with multiple fields
     */
    public function testIsUniqueMultipleFields(): void
    {
        $document = new Document([
            'author_id' => '000000000000000000000001',
            'title' => 'First Article',
        ]);

        $collection = $this->getCollectionLocator()->get('Articles');
        $rules = $collection->rulesChecker();
        $rules->add($rules->isUnique(['title', 'author_id'], 'Nope'));

        $this->assertFalse($collection->save($document));
        $this->assertEquals(['title' => ['isUnique' => 'Nope']], $document->getErrors());

        $document->clean();
        $document->author_id = '000000000000000000000002';
        $this->assertSame($document, $collection->save($document));
    }

    /**
     * Tests isUnique with non-unique null values
     */
    public function testIsUniqueNonUniqueNulls(): void
    {
        $collection = $this->getCollectionLocator()->get('UniqueAuthors');
        $rules = $collection->rulesChecker();
        $rules->add($rules->isUnique(
            ['first_author_id', 'second_author_id'],
            ['allowMultipleNulls' => false],
        ));

        $document = new Document([
            'first_author_id' => null,
            'second_author_id' => '000000000000000000000001',
        ]);
        $this->assertFalse($collection->save($document));
        $this->assertEquals(['first_author_id' => ['isUnique' => 'This value is already in use']], $document->getErrors());
    }

    /**
     * Tests isUnique with allowMultipleNulls
     */
    public function testIsUniqueAllowMultipleNulls(): void
    {
        $this->skipIf(ConnectionManager::get('test')->getDriver() instanceof Sqlserver);

        $collection = $this->getCollectionLocator()->get('UniqueAuthors');
        $rules = $collection->rulesChecker();
        $rules->add($rules->isUnique(
            ['first_author_id', 'second_author_id'],
        ));

        $document = new Document([
            'first_author_id' => null,
            'second_author_id' => '000000000000000000000001',
        ]);
        $this->assertNotEmpty($collection->save($document));

        $document->first_author_id = '000000000000000000000002';
        $this->assertSame($document, $collection->save($document));

        $document = new Document([
            'first_author_id' => '000000000000000000000002',
            'second_author_id' => '000000000000000000000001',
        ]);
        $this->assertFalse($collection->save($document));
        $this->assertEquals(['first_author_id' => ['isUnique' => 'This value is already in use']], $document->getErrors());
    }

    /**
     * Tests the existsIn domain rule
     */
    public function testExistsInDomainRule(): void
    {
        $document = new Document([
            'title' => 'An Article',
            'author_id' => '507f1f77bcf86cd799439011',
        ]);

        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->belongsTo('Authors');

        $rules = $collection->rulesChecker();
        $rules->add($rules->existsIn('author_id', 'Authors'));

        $this->assertFalse($collection->save($document));
        $this->assertEquals(['existsIn' => 'This value does not exist'], $document->getError('author_id'));
    }

    /**
     * Ensure that add(existsIn()) only invokes a rule once.
     */
    public function testExistsInRuleSingleInvocation(): void
    {
        $document = new Document([
            'title' => 'larry',
            'author_id' => '507f1f77bcf86cd799439011',
        ]);

        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->belongsTo('Authors');

        $rules = $collection->rulesChecker();
        $rules->add($rules->existsIn('author_id', 'Authors'), 'existsIn', ['errorField' => 'other']);
        $this->assertFalse($collection->save($document));

        $this->assertEquals(
            ['existsIn' => 'This value does not exist'],
            $document->getError('other'),
            'Provided field should have errors',
        );
        $this->assertEmpty($document->getError('author_id'), 'Errors should not apply to original field.');
    }

    /**
     * Tests the existsIn domain rule when passing an object
     */
    public function testExistsInDomainRuleWithObject(): void
    {
        $document = new Document([
            'title' => 'An Article',
            'author_id' => '507f1f77bcf86cd799439011',
        ]);

        $collection = $this->getCollectionLocator()->get('Articles');
        $rules = $collection->rulesChecker();
        $rules->add($rules->existsIn('author_id', $this->getCollectionLocator()->get('Authors'), 'Nope'));

        $this->assertFalse($collection->save($document));
        $this->assertEquals(['existsIn' => 'Nope'], $document->getError('author_id'));
    }

    /**
     * ExistsIn uses the schema to verify that nullable fields are ok.
     */
    public function testExistsInNullValue(): void
    {
        $document = new Document([
            'title' => 'An Article',
            'author_id' => null,
        ]);

        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->belongsTo('Authors');

        $rules = $collection->rulesChecker();
        $rules->add($rules->existsIn('author_id', 'Authors'));

        $this->assertEquals($document, $collection->save($document));
        $this->assertEquals([], $document->getError('author_id'));
    }

    /**
     * Test ExistsIn on a new entity that doesn't have the field populated.
     *
     * This use case is important for saving records and their
     * associated belongsTo records in one pass.
     */
    public function testExistsInNotNullValueNewEntity(): void
    {
        $document = new Document([
            'name' => 'A Category',
        ]);
        $collection = $this->getCollectionLocator()->get('Categories');
        $collection->belongsTo('Categories', [
            'foreignKey' => 'parent_id',
            'bindingKey' => 'id',
        ]);
        $rules = $collection->rulesChecker();
        $rules->add($rules->existsIn('parent_id', 'Categories'));
        $this->assertTrue($collection->checkRules($document, RulesChecker::CREATE));
        $this->assertEmpty($document->getError('parent_id'));
    }

    /**
     * Tests exists in uses the bindingKey of the association
     */
    public function testExistsInWithBindingKey(): void
    {
        $document = new Document([
            'title' => 'An Article',
        ]);

        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->belongsTo('Authors', [
            'bindingKey' => 'name',
            'foreignKey' => 'title',
        ]);
        $rules = $collection->rulesChecker();
        $rules->add($rules->existsIn('title', 'Authors'));

        $this->assertFalse($collection->save($document));
        $this->assertNotEmpty($document->getError('title'));

        $document->clean();
        $document->title = 'larry';
        $this->assertEquals($document, $collection->save($document));
    }

    /**
     * Tests existsIn with invalid associations
     */
    public function testExistsInInvalidAssociation(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('ExistsIn rule for `author_id` is invalid. `NotValid` is not associated with `Crustum\Mongo\ODM\BaseCollection`.');
        $document = new Document([
            'title' => 'An Article',
            'author_id' => '507f1f77bcf86cd799439011',
        ]);

        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->belongsTo('Authors');

        $rules = $collection->rulesChecker();
        $rules->add($rules->existsIn('author_id', 'NotValid'));

        $collection->save($document);
    }

    /**
     * Tests existsIn does not prevent new entities from saving if parent entity is new
     */
    public function testExistsInHasManyNewEntities(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->hasMany('Comments');
        $collection->Comments->belongsTo('Articles');

        $rules = $collection->Comments->rulesChecker();
        $rules->add($rules->existsIn(['article_id'], $collection));

        $article = $collection->newDocument([
            'title' => 'new article',
            'comments' => [
                $collection->Comments->newDocument([
                    'user_id' => '000000000000000000000001',
                    'comment' => 'comment 1',
                ]),
                $collection->Comments->newDocument([
                    'user_id' => '000000000000000000000001',
                    'comment' => 'comment 2',
                ]),
            ],
        ]);

        $this->assertNotFalse($collection->save($article));
    }

    /**
     * Tests existsIn does not prevent new entities from saving if parent entity is new,
     * getting the parent entity from the association
     */
    public function testExistsInHasManyNewEntitiesViaAssociation(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->hasMany('Comments');
        $collection->Comments->belongsTo('Articles');

        $rules = $collection->Comments->rulesChecker();
        $rules->add($rules->existsIn(['article_id'], 'Articles'));

        $article = $collection->newDocument([
            'title' => 'test',
        ]);

        $article->comments = [
            $collection->Comments->newDocument([
                'user_id' => '000000000000000000000001',
                'comment' => 'test',
            ]),
        ];

        $this->assertNotFalse($collection->save($article));
    }

    /**
     * Tests the checkRules save option
     */
    public function testSkipRulesChecking(): void
    {
        $document = new Document([
            'title' => 'An Article',
            'author_id' => '507f1f77bcf86cd799439011',
        ]);

        $collection = $this->getCollectionLocator()->get('Articles');
        $rules = $collection->rulesChecker();
        $rules->add($rules->existsIn('author_id', $this->getCollectionLocator()->get('Authors'), 'Nope'));

        $this->assertSame($document, $collection->save($document, ['checkRules' => false]));
    }

    /**
     * Tests the beforeRules event
     */
    public function testUseBeforeRules(): void
    {
        $document = new Document([
            'title' => 'An Article',
            'author_id' => '507f1f77bcf86cd799439011',
        ]);

        $collection = $this->getCollectionLocator()->get('Articles');
        $rules = $collection->rulesChecker();
        $rules->add($rules->existsIn('author_id', $this->getCollectionLocator()->get('Authors'), 'Nope'));

        $collection->getEventManager()->on(
            'Collection.beforeRules',
            function (EventInterface $event, EntityInterface $document, ArrayObject $options, $operation): void {
                $this->assertEquals(
                    [
                        'atomic' => true,
                        'associated' => true,
                        'checkRules' => true,
                        'checkExisting' => true,
                        '_primary' => true,
                        '_cleanOnSuccess' => true,
                    ],
                    $options->getArrayCopy(),
                );
                $this->assertSame('create', $operation);
                $event->stopPropagation();

                $event->setResult(true);
            },
        );

        $this->assertSame($document, $collection->save($document));
    }

    /**
     * Tests the afterRules event
     */
    public function testUseAfterRules(): void
    {
        $document = new Document([
            'title' => 'An Article',
            'author_id' => '507f1f77bcf86cd799439011',
        ]);

        $collection = $this->getCollectionLocator()->get('Articles');
        $rules = $collection->rulesChecker();
        $rules->add($rules->existsIn('author_id', $this->getCollectionLocator()->get('Authors'), 'Nope'));

        $collection->getEventManager()->on(
            'Collection.afterRules',
            function (EventInterface $event, EntityInterface $document, ArrayObject $options, $result, $operation): void {
                $this->assertEquals(
                    [
                        'atomic' => true,
                        'associated' => true,
                        'checkRules' => true,
                        'checkExisting' => true,
                        '_primary' => true,
                        '_cleanOnSuccess' => true,
                    ],
                    $options->getArrayCopy(),
                );
                $this->assertSame('create', $operation);
                $this->assertFalse($result);
                $event->stopPropagation();

                $event->setResult(true);
            },
        );

        $this->assertSame($document, $collection->save($document));
    }

    /**
     * Tests that rules can be changed using the buildRules event
     */
    public function testUseBuildRulesEvent(): void
    {
        $document = new Document([
            'title' => 'An Article',
            'author_id' => '507f1f77bcf86cd799439011',
        ]);

        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->getEventManager()->on('Collection.buildRules', function (EventInterface $event, RulesChecker $rules): void {
            $rules->add($rules->existsIn('author_id', $this->getCollectionLocator()->get('Authors'), 'Nope'));
        });

        $this->assertFalse($collection->save($document));
    }

    /**
     * Tests isUnique with untouched fields
     */
    public function testIsUniqueWithCleanFields(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $document = $collection->get('000000000000000000000001');
        $rules = $collection->rulesChecker();
        $rules->add($rules->isUnique(['title', 'author_id'], 'Nope'));

        $document->body = 'Foo';
        $this->assertSame($document, $collection->save($document));

        $document->title = 'Third Article';
        $this->assertFalse($collection->save($document));
    }

    /**
     * Tests isUnique rule with conflicting columns
     */
    public function testIsUniqueAliasPrefix(): void
    {
        $document = new Document([
            'title' => 'An Article',
            'author_id' => '000000000000000000000001',
        ]);

        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->belongsTo('Authors');

        $rules = $collection->rulesChecker();
        $rules->add($rules->isUnique(['author_id']));

        $collection->Authors->getEventManager()->on('Collection.beforeFind', function (EventInterface $event, $query): void {
            $query->leftJoin(['a2' => 'authors']);
        });

        $this->assertFalse($collection->save($document));
        $this->assertEquals(['isUnique' => 'This value is already in use'], $document->getError('author_id'));
    }

    /**
     * Tests the existsIn rule when passing non dirty fields
     */
    public function testExistsInWithCleanFields(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->belongsTo('Authors');

        $rules = $collection->rulesChecker();
        $rules->add($rules->existsIn('author_id', 'Authors'));

        $document = $collection->get('000000000000000000000001');
        $document->title = 'Foo';
        $document->author_id = '507f1f77bcf86cd799439011';
        $document->setDirty('author_id', false);
        $this->assertSame($document, $collection->save($document));
    }

    /**
     * Tests the existsIn with conflicting columns.
     *
     * Cake used a 1-arg SQL `leftJoin(['a2' => 'authors'])`; ODM `leftJoin`
     * takes a target + builder. existsIn must still fail for a missing FK.
     */
    public function testExistsInAliasPrefix(): void
    {
        $document = new Document([
            'title' => 'An Article',
            'author_id' => '507f1f77bcf86cd799439011',
        ]);

        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->belongsTo('Authors');

        $rules = $collection->rulesChecker();
        $rules->add($rules->existsIn('author_id', 'Authors'));

        $collection->Authors->getEventManager()->on('Collection.beforeFind', function (EventInterface $event, $query): void {
            $query->leftJoin(['a2' => 'authors'], function ($q): void {
                $q->where(fn($exp) => $exp->equalFields('Authors._id', 'a2._id'));
            });
        });

        $this->assertFalse($collection->save($document));
        $this->assertEquals(['existsIn' => 'This value does not exist'], $document->getError('author_id'));
    }

    /**
     * Tests that using an array in existsIn() sets the error message correctly
     */
    public function testExistsInErrorWithArrayField(): void
    {
        $document = new Document([
            'title' => 'An Article',
            'author_id' => '507f1f77bcf86cd799439011',
        ]);

        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->belongsTo('Authors');

        $rules = $collection->rulesChecker();
        $rules->add($rules->existsIn(['author_id'], 'Authors'));

        $this->assertFalse($collection->save($document));
        $this->assertEquals(['existsIn' => 'This value does not exist'], $document->getError('author_id'));
    }

    /**
     * Tests new allowNullableNulls flag with author id set to null
     */
    public function testExistsInAllowNullableNullsOn(): void
    {
        $document = new Document([
            'id' => 10,
            'author_id' => null,
            'site_id' => '000000000000000000000001',
            'name' => 'New Site Article without Author',
        ]);
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $collection->belongsTo('SiteAuthors');

        $rules = $collection->rulesChecker();

        $rules->add($rules->existsIn(['author_id', 'site_id'], 'SiteAuthors', [
            'allowNullableNulls' => true,
        ]));
        $this->assertInstanceOf(Document::class, $collection->save($document));
    }

    /**
     * Tests new allowNullableNulls flag with author id set to null
     */
    public function testExistsInAllowNullableNullsOff(): void
    {
        $document = new Document([
            'id' => 10,
            'author_id' => null,
            'site_id' => '000000000000000000000001',
            'name' => 'New Site Article without Author',
        ]);
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $collection->belongsTo('SiteAuthors');

        $rules = $collection->rulesChecker();

        $rules->add($rules->existsIn(['author_id', 'site_id'], 'SiteAuthors', [
            'allowNullableNulls' => false,
        ]));
        $this->assertFalse($collection->save($document));
    }

    /**
     * Tests new allowNullableNulls flag with author id set to null
     */
    public function testExistsInAllowNullableNullsDefaultValue(): void
    {
        $document = new Document([
            'id' => 10,
            'author_id' => null,
            'site_id' => '000000000000000000000001',
            'name' => 'New Site Article without Author',
        ]);
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $collection->belongsTo('SiteAuthors');

        $rules = $collection->rulesChecker();

        $rules->add($rules->existsIn(['author_id', 'site_id'], 'SiteAuthors'));
        $this->assertFalse($collection->save($document));
    }

    /**
     * Tests new allowNullableNulls flag with author id set to null
     */
    public function testExistsInAllowNullableNullsCustomMessage(): void
    {
        $document = new Document([
            'id' => 10,
            'author_id' => null,
            'site_id' => '000000000000000000000001',
            'name' => 'New Site Article without Author',
        ]);
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $collection->belongsTo('SiteAuthors');

        $rules = $collection->rulesChecker();

        $rules->add($rules->existsIn(['author_id', 'site_id'], 'SiteAuthors', [
            'allowNullableNulls' => false,
            'message' => 'Niente',
        ]));
        $this->assertFalse($collection->save($document));
        $this->assertEquals(['author_id' => ['existsIn' => 'Niente']], $document->getErrors());
    }

    /**
     * Tests new allowNullableNulls flag with author id set to 1
     */
    public function testExistsInAllowNullableNullsOnAllKeysSet(): void
    {
        $document = new Document([
            'id' => 10,
            'author_id' => '000000000000000000000001',
            'site_id' => '000000000000000000000001',
            'name' => 'New Site Article with Author',
        ]);
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $collection->belongsTo('SiteAuthors');

        $rules = $collection->rulesChecker();

        $rules->add($rules->existsIn(['author_id', 'site_id'], 'SiteAuthors', ['allowNullableNulls' => true]));
        $this->assertInstanceOf(Document::class, $collection->save($document));
    }

    /**
     * Tests new allowNullableNulls flag with author id set to 1
     */
    public function testExistsInAllowNullableNullsOffAllKeysSet(): void
    {
        $document = new Document([
            'id' => 10,
            'author_id' => '000000000000000000000001',
            'site_id' => '000000000000000000000001',
            'name' => 'New Site Article with Author',
        ]);
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $collection->belongsTo('SiteAuthors');

        $rules = $collection->rulesChecker();

        $rules->add($rules->existsIn(['author_id', 'site_id'], 'SiteAuthors', ['allowNullableNulls' => false]));
        $this->assertInstanceOf(Document::class, $collection->save($document));
    }

    /**
     * Tests new allowNullableNulls flag with author id set to 1
     */
    public function testExistsInAllowNullableNullsOnAllKeysCustomMessage(): void
    {
        $document = new Document([
            'id' => 10,
            'author_id' => '000000000000000000000001',
            'site_id' => '000000000000000000000001',
            'name' => 'New Site Article with Author',
        ]);
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $collection->belongsTo('SiteAuthors');

        $rules = $collection->rulesChecker();

        $rules->add($rules->existsIn(['author_id', 'site_id'], 'SiteAuthors', [
            'allowNullableNulls' => true,
            'message' => 'will not error']));
        $this->assertInstanceOf(Document::class, $collection->save($document));
    }

    /**
     * Tests new allowNullableNulls flag with author id set to 99999999 (does not exist)
     */
    public function testExistsInAllowNullableNullsOnInvalidKey(): void
    {
        $document = new Document([
            'id' => 10,
            'author_id' => '507f1f77bcf86cd799439011',
            'site_id' => '000000000000000000000001',
            'name' => 'New Site Article with Author',
        ]);
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $collection->belongsTo('SiteAuthors');

        $rules = $collection->rulesChecker();

        $rules->add($rules->existsIn(['author_id', 'site_id'], 'SiteAuthors', [
            'allowNullableNulls' => true,
            'message' => 'will error']));
        $this->assertFalse($collection->save($document));
        $this->assertEquals(['author_id' => ['existsIn' => 'will error']], $document->getErrors());
    }

    /**
     * Tests new allowNullableNulls flag with author id set to 99999999 (does not exist)
     * and site_id set to 99999999 (does not exist)
     */
    public function testExistsInAllowNullableNullsOnInvalidKeys(): void
    {
        $document = new Document([
            'id' => 10,
            'author_id' => '507f1f77bcf86cd799439011',
            'site_id' => '507f1f77bcf86cd799439011',
            'name' => 'New Site Article with Author',
        ]);
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $collection->belongsTo('SiteAuthors');

        $rules = $collection->rulesChecker();

        $rules->add($rules->existsIn(['author_id', 'site_id'], 'SiteAuthors', [
            'allowNullableNulls' => true,
            'message' => 'will error']));
        $this->assertFalse($collection->save($document));
        $this->assertEquals(['author_id' => ['existsIn' => 'will error']], $document->getErrors());
    }

    /**
     * Tests new allowNullableNulls flag with author id set to 1 (does exist)
     * and site_id set to 99999999 (does not exist)
     */
    public function testExistsInAllowNullableNullsOnInvalidKeySecond(): void
    {
        $document = new Document([
            'id' => 10,
            'author_id' => '000000000000000000000001',
            'site_id' => '507f1f77bcf86cd799439011',
            'name' => 'New Site Article with Author',
        ]);
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $collection->belongsTo('SiteAuthors');

        $rules = $collection->rulesChecker();

        $rules->add($rules->existsIn(['author_id', 'site_id'], 'SiteAuthors', [
            'allowNullableNulls' => true,
            'message' => 'will error']));
        $this->assertFalse($collection->save($document));
        $this->assertEquals(['author_id' => ['existsIn' => 'will error']], $document->getErrors());
    }

    /**
     * Tests new allowNullableNulls with saveMany
     */
    public function testExistsInAllowNullableNullsSaveMany(): void
    {
        $entities = [
            new Document([
                'id' => 1,
                'author_id' => null,
                'site_id' => '000000000000000000000001',
                'name' => 'New Site Article without Author',
            ]),
            new Document([
                'id' => 2,
                'author_id' => '000000000000000000000001',
                'site_id' => '000000000000000000000001',
                'name' => 'New Site Article with Author',
            ]),
        ];
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $collection->belongsTo('SiteAuthors');

        $rules = $collection->rulesChecker();

        $rules->add($rules->existsIn(['author_id', 'site_id'], 'SiteAuthors', [
            'allowNullableNulls' => true,
            'message' => 'will error with array_combine warning']));
        $result = $collection->saveMany($entities);
        $this->assertCount(2, $result);

        $this->assertInstanceOf(Document::class, $result[0]);
        $this->assertEmpty($result[0]->getErrors());

        $this->assertInstanceOf(Document::class, $result[1]);
        $this->assertEmpty($result[1]->getErrors());
    }

    /**
     * Tests existsInNullable helper method with null value
     */
    public function testExistsInNullableMethod(): void
    {
        $document = new Document([
            'id' => 10,
            'author_id' => null,
            'site_id' => '000000000000000000000001',
            'name' => 'New Site Article without Author',
        ]);
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $collection->belongsTo('SiteAuthors');

        $rules = $collection->rulesChecker();

        $rules->add($rules->existsInNullable(['author_id', 'site_id'], 'SiteAuthors'));
        $this->assertInstanceOf(Document::class, $collection->save($document));
    }

    /**
     * Tests existsInNullable helper method with valid values
     */
    public function testExistsInNullableMethodWithValidValues(): void
    {
        $document = new Document([
            'id' => 10,
            'author_id' => '000000000000000000000001',
            'site_id' => '000000000000000000000001',
            'name' => 'New Site Article with Author',
        ]);
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $collection->belongsTo('SiteAuthors');

        $rules = $collection->rulesChecker();

        $rules->add($rules->existsInNullable(['author_id', 'site_id'], 'SiteAuthors'));
        $this->assertInstanceOf(Document::class, $collection->save($document));
    }

    /**
     * Tests existsInNullable helper method with invalid values
     */
    public function testExistsInNullableMethodWithInvalidValues(): void
    {
        $document = new Document([
            'id' => 10,
            'author_id' => '507f1f77bcf86cd799439011',
            'site_id' => '000000000000000000000001',
            'name' => 'New Site Article with Invalid Author',
        ]);
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $collection->belongsTo('SiteAuthors');

        $rules = $collection->rulesChecker();

        $rules->add($rules->existsInNullable(['author_id', 'site_id'], 'SiteAuthors'));
        $this->assertFalse($collection->save($document));
        $this->assertEquals(['author_id' => ['_existsIn' => 'This value does not exist']], $document->getErrors());
    }

    /**
     * Tests existsInNullable helper method with custom message
     */
    public function testExistsInNullableMethodWithCustomMessage(): void
    {
        $document = new Document([
            'id' => 10,
            'author_id' => '507f1f77bcf86cd799439011',
            'site_id' => '000000000000000000000001',
            'name' => 'New Site Article',
        ]);
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $collection->belongsTo('SiteAuthors');

        $rules = $collection->rulesChecker();

        $rules->add($rules->existsInNullable(['author_id', 'site_id'], 'SiteAuthors', 'Custom nullable message'));
        $this->assertFalse($collection->save($document));
        $this->assertEquals(['author_id' => ['_existsIn' => 'Custom nullable message']], $document->getErrors());
    }

    /**
     * Tests using rules to prevent delete operations
     */
    public function testDeleteRules(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $rules = $collection->rulesChecker();
        $rules->addDelete(fn($document): false => false);

        $document = $collection->get('000000000000000000000001');
        $this->assertFalse($collection->delete($document));
    }

    /**
     * Checks that it is possible to pass custom options to rules when saving
     */
    public function testCustomOptionsPassingSave(): void
    {
        $document = new Document([
            'name' => 'jose',
        ]);

        $collection = $this->getCollectionLocator()->get('Authors');
        $rules = $collection->rulesChecker();
        $rules->add(function ($document, array $options): false {
            $this->assertSame('bar', $options['foo']);
            $this->assertSame('option', $options['another']);

            return false;
        }, ['another' => 'option']);

        $this->assertFalse($collection->save($document, ['foo' => 'bar']));
    }

    /**
     * Tests passing custom options to rules from delete
     */
    public function testCustomOptionsPassingDelete(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $rules = $collection->rulesChecker();
        $rules->addDelete(function ($document, array $options): false {
            $this->assertSame('bar', $options['foo']);
            $this->assertSame('option', $options['another']);

            return false;
        }, ['another' => 'option']);

        $document = $collection->get('000000000000000000000001');
        $this->assertFalse($collection->delete($document, ['foo' => 'bar']));
    }

    /**
     * Test adding rules that return error string
     */
    public function testCustomErrorMessageFromRule(): void
    {
        $document = new Document([
            'name' => 'larry',
        ]);

        $collection = $this->getCollectionLocator()->get('Authors');
        $rules = $collection->rulesChecker();
        $rules->add(fn(): string => 'So much nope', ['errorField' => 'name']);

        $this->assertFalse($collection->save($document));
        $this->assertEquals(['So much nope'], $document->getError('name'));
    }

    /**
     * Test adding rules with no errorField now sets errors under _rule key
     */
    public function testCustomErrorMessageFromRuleNoErrorField(): void
    {
        $document = new Document([
            'name' => 'larry',
        ]);

        $collection = $this->getCollectionLocator()->get('Authors');
        $rules = $collection->rulesChecker();
        $rules->add(fn(): string => 'So much nope');

        $this->assertFalse($collection->save($document));
        $this->assertNotEmpty($document->getErrors());
        $this->assertEquals(['So much nope'], $document->getError('_rule'));
    }

    /**
     * Tests that using existsIn for a hasMany association will not be called
     * as the foreign key for the association was automatically validated already.
     */
    public function testAvoidExistsInOnAutomaticSaving(): void
    {
        $document = new Document([
            'name' => 'Jose',
        ]);
        $document->articles = [
            new Document([
                'title' => '1',
                'body' => 'A body',
            ]),
            new Document([
                'title' => 'Another Title',
                'body' => 'Another body',
            ]),
        ];

        $collection = $this->getCollectionLocator()->get('authors');
        $collection->hasMany('articles');
        $collection->getAssociation('articles')->belongsTo('authors');
        $checker = $collection->getAssociation('articles')->getTarget()->rulesChecker();
        $checker->add(function ($document, $options) use ($checker): true {
            $rule = $checker->existsIn('author_id', 'authors');
            $id = $document->author_id;
            $document->author_id = '507f1f77bcf86cd799439011';
            $result = $rule($document, $options);
            $this->assertTrue($result);
            $document->author_id = $id;

            return true;
        });

        $this->assertSame($document, $collection->save($document));
    }

    /**
     * Tests the existsIn domain rule respects the conditions set for the associations
     */
    public function testExistsInDomainRuleWithAssociationConditions(): void
    {
        $document = new Document([
            'title' => 'An Article',
            'author_id' => '000000000000000000000001',
        ]);

        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->belongsTo('Authors', [
            'conditions' => ['Authors.name !=' => 'mariano'],
        ]);
        $rules = $collection->rulesChecker();
        $rules->add($rules->existsIn('author_id', 'Authors'));

        $this->assertFalse($collection->save($document));
        $this->assertEquals(['existsIn' => 'This value does not exist'], $document->getError('author_id'));
    }

    /**
     * Tests that associated items have a count of X.
     */
    public function testCountOfAssociatedItems(): void
    {
        $document = new Document([
            'title' => 'A Title',
            'body' => 'A body',
        ]);
        $document->tags = [
            new Document([
                'name' => 'Something New',
            ]),
            new Document([
                'name' => '100',
            ]),
        ];

        $this->getCollectionLocator()->get('ArticlesTags');

        $collection = $this->getCollectionLocator()->get('articles');
        $collection->belongsToMany('tags');

        $rules = $collection->rulesChecker();
        $rules->add($rules->validCount('tags', 3));

        $this->assertFalse($collection->save($document));
        $this->assertEquals($document->getErrors(), [
            'tags' => [
                'validCount' => 'The count does not match >3',
            ],
        ]);

        // Testing that undesired types fail
        $document->tags = null;
        $this->assertFalse($collection->save($document));

        $document->tags = new stdClass();
        $this->assertFalse($collection->save($document));

        $document->tags = 'string';
        $this->assertFalse($collection->save($document));

        $document->tags = 123456;
        $this->assertFalse($collection->save($document));

        $document->tags = 0.512;
        $this->assertFalse($collection->save($document));
    }

    /**
     * Tests that the error field name is inferred from the association name in case no name is provided.
     */
    public function testIsLinkedToInferFieldFromAssociationName(): void
    {
        $Comments = $this->getCollectionLocator()->get('Comments');
        $Comments->belongsTo('Articles');

        $comment = $Comments->save($Comments->newDocument([
            'article_id' => '507f1f77bcf86cd799439011',
            'user_id' => '000000000000000000000001',
            'comment' => 'Orphaned Comment',
        ]));

        /** @var \Crustum\Mongo\ODM\RulesChecker $rulesChecker */
        $rulesChecker = $Comments->rulesChecker();
        $rulesChecker->addUpdate(
            $rulesChecker->isLinkedTo('Articles'),
        );

        $comment->setDirty('comment', true);
        $this->assertFalse($Comments->save($comment));

        $expected = [
            'article' => [
                'isLinkedTo' => 'Cannot modify row: a constraint for the `Articles` association fails.',
            ],
        ];
        $this->assertEquals($expected, $comment->getErrors());
    }

    /**
     * Tests that the error field name is inferred from the association name in case no name is provided.
     */
    public function testIsNotLinkedToInferFieldFromAssociationName(): void
    {
        $Articles = $this->getCollectionLocator()->get('Articles');
        $Articles->hasMany('Comments');

        /** @var \Crustum\Mongo\ODM\RulesChecker $rulesChecker */
        $rulesChecker = $Articles->rulesChecker();
        $rulesChecker->addDelete(
            $rulesChecker->isNotLinkedTo('Comments'),
        );

        $article = $Articles->get('000000000000000000000001');
        $this->assertFalse($Articles->delete($article));

        $expected = [
            'comments' => [
                'isNotLinkedTo' => 'Cannot modify row: a constraint for the `Comments` association fails.',
            ],
        ];
        $this->assertEquals($expected, $article->getErrors());
    }

    /**
     * Tests that the error field name is inferred from the association name in case no name is provided,
     * and no repository is available at the time of creating the rule.
     */
    public function testIsLinkedToInferFieldFromAssociationNameWithNoRepositoryAvailable(): void
    {
        $Comments = new class extends BaseCollection {
            public function initialize(array $config): void
            {
                $this->setAlias('Comments');
                $this->setCollection('comments');
                $this->belongsTo('Articles');
            }

            public function buildRules(RulesChecker $rules): RulesChecker
            {
                return $rules->addUpdate(
                    $rules->isLinkedTo('Articles'),
                    ['repository' => $this],
                );
            }
        };

        $comment = $Comments->save($Comments->newDocument([
            'article_id' => '507f1f77bcf86cd799439011',
            'user_id' => '000000000000000000000001',
            'comment' => 'Orphaned Comment',
        ]));

        $comment->setDirty('comment', true);
        $this->assertFalse($Comments->save($comment));

        $expected = [
            'article' => [
                'isLinkedTo' => 'Cannot modify row: a constraint for the `Articles` association fails.',
            ],
        ];
        $this->assertEquals($expected, $comment->getErrors());
    }

    /**
     * Tests that the error field name is inferred from the association name in case no name is provided,
     * and no repository is available at the time of creating the rule.
     */
    public function testIsNotLinkedToInferFieldFromAssociationNameWithNoRepositoryAvailable(): void
    {
        $Articles = new class extends BaseCollection {
            public function initialize(array $config): void
            {
                $this->setAlias('Articles');
                $this->setCollection('articles');
                $this->hasMany('Comments');
            }

            public function buildRules(RulesChecker $rules): RulesChecker
            {
                return $rules->addDelete(
                    $rules->isNotLinkedTo('Comments'),
                    ['repository' => $this],
                );
            }
        };

        $article = $Articles->get('000000000000000000000001');
        $this->assertFalse($Articles->delete($article));

        $expected = [
            'comments' => [
                'isNotLinkedTo' => 'Cannot modify row: a constraint for the `Comments` association fails.',
            ],
        ];
        $this->assertEquals($expected, $article->getErrors());
    }

    /**
     * Tests that the error field name is inferred from the association object in case no name is provided.
     */
    public function testIsLinkedToInferFieldFromAssociationObject(): void
    {
        $Comments = $this->getCollectionLocator()->get('Comments');
        $Comments->belongsTo('Articles');

        $comment = $Comments->save($Comments->newDocument([
            'article_id' => '507f1f77bcf86cd799439011',
            'user_id' => '000000000000000000000001',
            'comment' => 'Orphaned Comment',
        ]));

        /** @var \Crustum\Mongo\ODM\RulesChecker $rulesChecker */
        $rulesChecker = $Comments->rulesChecker();
        $rulesChecker->addUpdate(
            $rulesChecker->isLinkedTo($Comments->getAssociation('Articles')),
        );

        $comment->setDirty('comment', true);
        $this->assertFalse($Comments->save($comment));

        $expected = [
            'article' => [
                'isLinkedTo' => 'Cannot modify row: a constraint for the `Articles` association fails.',
            ],
        ];
        $this->assertEquals($expected, $comment->getErrors());
    }

    /**
     * Tests that the error field name is inferred from the association object in case no name is provided.
     */
    public function testIsNotLinkedToInferFieldFromAssociationObject(): void
    {
        $Articles = $this->getCollectionLocator()->get('Articles');
        $Articles->hasMany('Comments');

        /** @var \Crustum\Mongo\ODM\RulesChecker $rulesChecker */
        $rulesChecker = $Articles->rulesChecker();
        $rulesChecker->addDelete(
            $rulesChecker->isNotLinkedTo($Articles->getAssociation('Comments')),
        );

        $article = $Articles->get('000000000000000000000001');
        $this->assertFalse($Articles->delete($article));

        $expected = [
            'comments' => [
                'isNotLinkedTo' => 'Cannot modify row: a constraint for the `Comments` association fails.',
            ],
        ];
        $this->assertEquals($expected, $article->getErrors());
    }

    /**
     * Tests that the custom error field name is being used.
     */
    public function testIsLinkedToWithCustomField(): void
    {
        $Comments = $this->getCollectionLocator()->get('Comments');
        $Comments->belongsTo('Articles');

        $comment = $Comments->save($Comments->newDocument([
            'article_id' => '507f1f77bcf86cd799439011',
            'user_id' => '000000000000000000000001',
            'comment' => 'Orphaned Comment',
        ]));

        /** @var \Crustum\Mongo\ODM\RulesChecker $rulesChecker */
        $rulesChecker = $Comments->rulesChecker();
        $rulesChecker->addUpdate(
            $rulesChecker->isLinkedTo('Articles', 'custom'),
        );

        $comment->setDirty('comment', true);
        $this->assertFalse($Comments->save($comment));

        $expected = [
            'custom' => [
                'isLinkedTo' => 'Cannot modify row: a constraint for the `Articles` association fails.',
            ],
        ];
        $this->assertEquals($expected, $comment->getErrors());
    }

    /**
     * Tests that the custom error field name is being used.
     */
    public function testIsNotLinkedToWithCustomField(): void
    {
        $Articles = $this->getCollectionLocator()->get('Articles');
        $Articles->hasMany('Comments');

        /** @var \Crustum\Mongo\ODM\RulesChecker $rulesChecker */
        $rulesChecker = $Articles->rulesChecker();
        $rulesChecker->addDelete(
            $rulesChecker->isNotLinkedTo('Comments', 'custom'),
        );

        $article = $Articles->get('000000000000000000000001');
        $this->assertFalse($Articles->delete($article));

        $expected = [
            'custom' => [
                'isNotLinkedTo' => 'Cannot modify row: a constraint for the `Comments` association fails.',
            ],
        ];
        $this->assertEquals($expected, $article->getErrors());
    }

    /**
     * Tests that the custom error message is being used.
     */
    public function testIsLinkedToWithCustomMessage(): void
    {
        $Comments = $this->getCollectionLocator()->get('Comments');
        $Comments->belongsTo('Articles');

        $comment = $Comments->save($Comments->newDocument([
            'article_id' => '507f1f77bcf86cd799439011',
            'user_id' => '000000000000000000000001',
            'comment' => 'Orphaned Comment',
        ]));

        /** @var \Crustum\Mongo\ODM\RulesChecker $rulesChecker */
        $rulesChecker = $Comments->rulesChecker();
        $rulesChecker->addUpdate(
            $rulesChecker->isLinkedTo('Articles', 'article', 'custom'),
        );

        $comment->setDirty('comment', true);
        $this->assertFalse($Comments->save($comment));

        $expected = [
            'article' => [
                'isLinkedTo' => 'custom',
            ],
        ];
        $this->assertEquals($expected, $comment->getErrors());
    }

    /**
     * Tests that the custom error message is being used.
     */
    public function testIsNotLinkedToWithCustomMessage(): void
    {
        $Articles = $this->getCollectionLocator()->get('Articles');
        $Articles->hasMany('Comments');

        /** @var \Crustum\Mongo\ODM\RulesChecker $rulesChecker */
        $rulesChecker = $Articles->rulesChecker();
        $rulesChecker->addDelete(
            $rulesChecker->isNotLinkedTo('Comments', 'comments', 'custom'),
        );

        $article = $Articles->get('000000000000000000000001');
        $this->assertFalse($Articles->delete($article));

        $expected = [
            'comments' => [
                'isNotLinkedTo' => 'custom',
            ],
        ];
        $this->assertEquals($expected, $article->getErrors());
    }

    /**
     * Tests that the default error message can be translated.
     */
    public function testIsLinkedToMessageWithI18n(): void
    {
        /** @var \Cake\I18n\Translator $translator */
        $translator = I18n::getTranslator('cake');

        $messageId = 'Cannot modify row: a constraint for the `{0}` association fails.';
        $translator->getPackage()->addMessage(
            $messageId,
            'Zeile kann nicht geändert werden: Eine Einschränkung für die "{0}" Beziehung schlägt fehl.',
        );

        $Comments = $this->getCollectionLocator()->get('Comments');
        $Comments->belongsTo('Articles');

        $comment = $Comments->save($Comments->newDocument([
            'article_id' => '507f1f77bcf86cd799439011',
            'user_id' => '000000000000000000000001',
            'comment' => 'Orphaned Comment',
        ]));

        /** @var \Crustum\Mongo\ODM\RulesChecker $rulesChecker */
        $rulesChecker = $Comments->rulesChecker();

        $rulesChecker->addUpdate(
            $rulesChecker->isLinkedTo('Articles', 'article'),
        );

        $comment->setDirty('comment', true);
        $this->assertFalse($Comments->save($comment));

        $expected = [
            'article' => [
                'isLinkedTo' => 'Zeile kann nicht geändert werden: Eine Einschränkung für die "Articles" Beziehung schlägt fehl.',
            ],
        ];
        $this->assertEquals($expected, $comment->getErrors());

        $translator->getPackage()->addMessage($messageId, '');
    }

    /**
     * Tests that the default error message can be translated.
     */
    public function testIsNotLinkedToMessageWithI18n(): void
    {
        /** @var \Cake\I18n\Translator $translator */
        $translator = I18n::getTranslator('cake');

        $messageId = 'Cannot modify row: a constraint for the `{0}` association fails.';
        $translator->getPackage()->addMessage(
            $messageId,
            'Zeile kann nicht geändert werden: Eine Einschränkung für die "{0}" Beziehung schlägt fehl.',
        );

        $Comments = $this->getCollectionLocator()->get('Comments');
        $Comments->belongsTo('Articles');

        /** @var \Crustum\Mongo\ODM\RulesChecker $rulesChecker */
        $rulesChecker = $Comments->rulesChecker();

        $rulesChecker->addUpdate(
            $rulesChecker->isNotLinkedTo('Articles', 'articles'),
        );

        $comment = $Comments->get('000000000000000000000001');
        $comment->setDirty('comment', true);
        $this->assertFalse($Comments->save($comment));

        $expected = [
            'articles' => [
                'isNotLinkedTo' => 'Zeile kann nicht geändert werden: Eine Einschränkung für die "Articles" Beziehung schlägt fehl.',
            ],
        ];
        $this->assertEquals($expected, $comment->getErrors());

        $translator->getPackage()->addMessage($messageId, '');
    }

    /**
     * Tests that the default error message works without I18n.
     */
    public function testIsLinkedToMessageWithoutI18n(): void
    {
        /** @var \Cake\I18n\Translator $translator */
        $translator = I18n::getTranslator('cake');

        $messageId = 'Cannot modify row: a constraint for the `{0}` association fails.';
        $translator->getPackage()->addMessage(
            $messageId,
            'translated',
        );

        $Comments = $this->getCollectionLocator()->get('Comments');
        $Comments->belongsTo('Articles');

        $comment = $Comments->save($Comments->newDocument([
            'article_id' => '507f1f77bcf86cd799439011',
            'user_id' => '000000000000000000000001',
            'comment' => 'Orphaned Comment',
        ]));

        /** @var \Crustum\Mongo\ODM\RulesChecker $rulesChecker */
        $rulesChecker = $Comments->rulesChecker();

        Closure::bind(
            function () use ($rulesChecker): void {
                $rulesChecker->{'useI18n'} = false;
            },
            null,
            RulesChecker::class,
        )();

        $rulesChecker->addUpdate(
            $rulesChecker->isLinkedTo('Articles', 'article'),
        );

        $comment->setDirty('comment', true);
        $this->assertFalse($Comments->save($comment));

        $expected = [
            'article' => [
                'isLinkedTo' => 'Cannot modify row: a constraint for the `Articles` association fails.',
            ],
        ];
        $this->assertEquals($expected, $comment->getErrors());

        $translator->getPackage()->addMessage($messageId, '');
    }

    /**
     * Tests that the default error message works without I18n.
     */
    public function testIsNotLinkedToMessageWithoutI18n(): void
    {
        /** @var \Cake\I18n\Translator $translator */
        $translator = I18n::getTranslator('cake');

        $messageId = 'Cannot modify row: a constraint for the `{0}` association fails.';
        $translator->getPackage()->addMessage(
            $messageId,
            'translated',
        );

        $Comments = $this->getCollectionLocator()->get('Comments');
        $Comments->belongsTo('Articles');

        /** @var \Crustum\Mongo\ODM\RulesChecker $rulesChecker */
        $rulesChecker = $Comments->rulesChecker();

        Closure::bind(
            function () use ($rulesChecker): void {
                $rulesChecker->{'useI18n'} = false;
            },
            null,
            RulesChecker::class,
        )();

        $rulesChecker->addUpdate(
            $rulesChecker->isNotLinkedTo('Articles', 'articles'),
        );

        $comment = $Comments->get('000000000000000000000001');
        $comment->setDirty('comment', true);
        $this->assertFalse($Comments->save($comment));

        $expected = [
            'articles' => [
                'isNotLinkedTo' => 'Cannot modify row: a constraint for the `Articles` association fails.',
            ],
        ];
        $this->assertEquals($expected, $comment->getErrors());

        $translator->getPackage()->addMessage($messageId, '');
    }

    /**
     * Tests that the rule can pass.
     */
    public function testIsLinkedToIsLinked(): void
    {
        $Comments = $this->getCollectionLocator()->get('Comments');
        $Comments->belongsTo('Articles');

        /** @var \Crustum\Mongo\ODM\RulesChecker $rulesChecker */
        $rulesChecker = $Comments->rulesChecker();
        $rulesChecker->addUpdate(
            $rulesChecker->isLinkedTo('Articles', 'articles'),
        );

        $comment = $Comments->get('000000000000000000000001');
        $comment->setDirty('comment', true);
        $this->assertNotFalse($Comments->save($comment));
    }

    /**
     * Tests that the rule can pass.
     */
    public function testIsNotLinkedToIsNotLinked(): void
    {
        $Articles = $this->getCollectionLocator()->get('Articles');
        $Articles->hasMany('Comments');

        /** @var \Crustum\Mongo\ODM\RulesChecker $rulesChecker */
        $rulesChecker = $Articles->rulesChecker();
        $rulesChecker->addDelete(
            $rulesChecker->isNotLinkedTo('Comments', 'comments'),
        );

        $article = $Articles->get('000000000000000000000003');
        $this->assertTrue($Articles->delete($article));
    }
}
