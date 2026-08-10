<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         3.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Crustum\Mongo\Test\TestCase\ODM\Rule;

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
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
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
        $entity = new Document([
            'title' => 'A Title',
            'body' => 'A body',
        ]);
        $entity->author = new Document([
            'name' => 'Jose',
        ]);

        $table = $this->getCollectionLocator()->get('articles');
        $table->belongsTo('authors');
        $table->getAssociation('authors')
            ->getTarget()
            ->rulesChecker()
            ->add(
                function (Document $author, array $options) use ($table) {
                    $this->assertSame($options['repository'], $table->getAssociation('authors')->getTarget());

                    return false;
                },
                ['errorField' => 'name', 'message' => 'This is an error'],
            );

        $this->assertFalse($table->save($entity));
        $this->assertTrue($entity->isNew());
        $this->assertTrue($entity->author->isNew());
        $this->assertNull($entity->get('author_id'));
        $this->assertNotEmpty($entity->author->getError('name'));
        $this->assertEquals(['This is an error'], $entity->author->getError('name'));
    }

    /**
     * Tests saving hasOne association and returning a validation error will
     * abort the saving process
     */
    public function testSaveHasOneWithValidationError(): void
    {
        $entity = new Document([
            'name' => 'Jose',
        ]);
        $entity->article = new Document([
            'title' => 'A Title',
            'body' => 'A body',
        ]);

        $table = $this->getCollectionLocator()->get('authors');
        $table->hasOne('articles');
        $table->getAssociation('articles')
            ->getTarget()
            ->rulesChecker()
            ->add(
                function (EntityInterface $entity) {
                    return false;
                },
                ['errorField' => 'title', 'message' => 'This is an error'],
            );

        $this->assertFalse($table->save($entity));
        $this->assertTrue($entity->isNew());
        $this->assertTrue($entity->article->isNew());
        $this->assertNull($entity->article->id);
        $this->assertNull($entity->article->get('author_id'));
        $this->assertFalse($entity->article->isDirty('author_id'));
        $this->assertNotEmpty($entity->article->getError('title'));
        $this->assertSame('A Title', $entity->article->getInvalidField('title'));
    }

    /**
     * Tests saving multiple entities in a hasMany association and getting and
     * error while saving one of them. It should abort all the save operation
     * when options are set to defaults
     */
    public function testSaveHasManyWithErrorsAtomic(): void
    {
        $entity = new Document([
            'name' => 'Jose',
        ]);
        $entity->articles = [
            new Document([
                'title' => '1',
                'body' => 'A body',
            ]),
            new Document([
                'title' => 'Another Title',
                'body' => 'Another body',
            ]),
        ];

        $table = $this->getCollectionLocator()->get('authors');
        $table->hasMany('articles');
        $table->getAssociation('articles')
            ->getTarget()
            ->rulesChecker()
            ->add(
                function (Document $entity, $options) use ($table) {
                    $this->assertSame($table, $options['_sourceTable']);

                    return $entity->title === '1';
                },
                ['errorField' => 'title', 'message' => 'This is an error'],
            );

        $this->assertFalse($table->save($entity));
        $this->assertTrue($entity->isNew());
        $this->assertTrue($entity->articles[0]->isNew());
        $this->assertTrue($entity->articles[1]->isNew());
        $this->assertNull($entity->articles[0]->id);
        $this->assertNull($entity->articles[1]->id);
        $this->assertNull($entity->articles[0]->author_id);
        $this->assertNull($entity->articles[1]->author_id);
        $this->assertEmpty($entity->articles[0]->getErrors());
        $this->assertNotEmpty($entity->articles[1]->getErrors());
    }

    /**
     * Tests that it is possible to continue saving hasMany associations
     * even if any of the records fail validation when atomic is set
     * to false
     */
    public function testSaveHasManyWithErrorsNonAtomic(): void
    {
        $entity = new Document([
            'name' => 'Jose',
        ]);
        $entity->articles = [
            new Document([
                'title' => 'A title',
                'body' => 'A body',
            ]),
            new Document([
                'title' => '1',
                'body' => 'Another body',
            ]),
        ];

        $table = $this->getCollectionLocator()->get('authors');
        $table->hasMany('articles');
        $table->getAssociation('articles')
            ->getTarget()
            ->rulesChecker()
            ->add(
                function (Document $article) {
                    return is_numeric($article->title);
                },
                ['errorField' => 'title', 'message' => 'This is an error'],
            );

        $result = $table->save($entity, ['atomic' => false]);
        $this->assertSame($entity, $result);
        $this->assertFalse($entity->isNew());
        $this->assertTrue($entity->articles[0]->isNew());
        $this->assertFalse($entity->articles[1]->isNew());
        $this->assertNotEmpty($entity->articles[1]->getId());
        $this->assertNull($entity->articles[0]->getId());
        $this->assertNotEmpty($entity->articles[0]->getError('title'));
    }

    /**
     * Tests saving belongsToMany records with a validation error in a joint entity
     */
    public function testSaveBelongsToManyWithValidationErrorInJointEntity(): void
    {
        $entity = new Document([
            'title' => 'A Title',
            'body' => 'A body',
        ]);
        $entity->tags = [
            new Document([
                'name' => 'Something New',
            ]),
            new Document([
                'name' => '100',
            ]),
        ];
        $table = $this->getCollectionLocator()->get('articles');
        $table->belongsToMany('tags');
        $table->getAssociation('tags')
            ->junction()
            ->rulesChecker()
            ->add(function (Document $entity) {
                return false;
            });

        $this->assertFalse($table->save($entity));
        $this->assertTrue($entity->isNew());
        $this->assertTrue($entity->tags[0]->isNew());
        $this->assertTrue($entity->tags[1]->isNew());
        $this->assertNull($entity->tags[0]->getId());
        $this->assertNull($entity->tags[1]->getId());
        $this->assertNull($entity->tags[0]->_joinData);
        $this->assertNull($entity->tags[1]->_joinData);
    }

    /**
     * Tests saving belongsToMany records with a validation error in a joint entity
     * and atomic set to false
     */
    public function testSaveBelongsToManyWithValidationErrorInJointEntityNonAtomic(): void
    {
        $entity = new Document([
            'title' => 'A Title',
            'body' => 'A body',
        ]);
        $entity->tags = [
            new Document([
                'name' => 'Something New',
            ]),
            new Document([
                'name' => 'New one',
            ]),
        ];
        $table = $this->getCollectionLocator()->get('articles');
        $table->belongsToMany('tags');
        $table->getAssociation('tags')
            ->junction()
            ->rulesChecker()
            ->add(function (Document $entity) {
                return false;
            });

        $this->assertSame($entity, $table->save($entity, ['atomic' => false]));
        $this->assertFalse($entity->isNew());
        $this->assertFalse($entity->tags[0]->isNew());
        $this->assertFalse($entity->tags[1]->isNew());
        $this->assertNotEmpty($entity->tags[0]->getId());
        $this->assertNotEmpty($entity->tags[1]->getId());
        $this->assertTrue($entity->tags[0]->_joinData->isNew());
        $this->assertTrue($entity->tags[1]->_joinData->isNew());
        $this->assertSame($entity->getId(), $entity->tags[0]->_joinData->article_id);
        $this->assertSame($entity->tags[0]->getId(), $entity->tags[0]->_joinData->tag_id);
        $this->assertSame($entity->tags[1]->getId(), $entity->tags[1]->_joinData->tag_id);
    }

    /**
     * Test adding rule with name
     */
    public function testAddingRuleWithName(): void
    {
        $entity = new Document([
            'name' => 'larry',
        ]);

        $table = $this->getCollectionLocator()->get('Authors');
        $rules = $table->rulesChecker();
        $rules->add(
            function () {
                return false;
            },
            'ruleName',
            ['errorField' => 'name'],
        );

        $this->assertFalse($table->save($entity));
        $this->assertEquals(['ruleName' => 'invalid'], $entity->getError('name'));
    }

    /**
     * Ensure that add(isUnique()) only invokes a rule once.
     */
    public function testIsUniqueRuleSingleInvocation(): void
    {
        $entity = new Document([
            'name' => 'larry',
        ]);

        $table = $this->getCollectionLocator()->get('Authors');
        $rules = $table->rulesChecker();
        $rules->add($rules->isUnique(['name']), 'isUnique', ['errorField' => 'title']);
        $this->assertFalse($table->save($entity));

        $this->assertEquals(
            ['isUnique' => 'This value is already in use'],
            $entity->getError('title'),
            'Provided field should have errors',
        );
        $this->assertEmpty($entity->getError('name'), 'Errors should not apply to original field.');
    }

    /**
     * Tests the isUnique domain rule
     */
    public function testIsUniqueDomainRule(): void
    {
        $entity = new Document([
            'name' => 'larry',
        ]);

        $table = $this->getCollectionLocator()->get('Authors');
        $rules = $table->rulesChecker();
        $rules->add($rules->isUnique(['name']));

        $this->assertFalse($table->save($entity));
        $this->assertEquals(['isUnique' => 'This value is already in use'], $entity->getError('name'));

        $entity->name = 'jose';
        $this->assertSame($entity, $table->save($entity));

        $entity = $table->get('000000000000000000000001');
        $entity->setDirty('name', true);
        $this->assertSame($entity, $table->save($entity));
    }

    /**
     * Tests isUnique with multiple fields
     */
    public function testIsUniqueMultipleFields(): void
    {
        $entity = new Document([
            'author_id' => '000000000000000000000001',
            'title' => 'First Article',
        ]);

        $table = $this->getCollectionLocator()->get('Articles');
        $rules = $table->rulesChecker();
        $rules->add($rules->isUnique(['title', 'author_id'], 'Nope'));

        $this->assertFalse($table->save($entity));
        $this->assertEquals(['title' => ['isUnique' => 'Nope']], $entity->getErrors());

        $entity->clean();
        $entity->author_id = '000000000000000000000002';
        $this->assertSame($entity, $table->save($entity));
    }

    /**
     * Tests isUnique with non-unique null values
     */
    public function testIsUniqueNonUniqueNulls(): void
    {
        $table = $this->getCollectionLocator()->get('UniqueAuthors');
        $rules = $table->rulesChecker();
        $rules->add($rules->isUnique(
            ['first_author_id', 'second_author_id'],
            ['allowMultipleNulls' => false],
        ));

        $entity = new Document([
            'first_author_id' => null,
            'second_author_id' => '000000000000000000000001',
        ]);
        $this->assertFalse($table->save($entity));
        $this->assertEquals(['first_author_id' => ['isUnique' => 'This value is already in use']], $entity->getErrors());
    }

    /**
     * Tests isUnique with allowMultipleNulls
     */
    public function testIsUniqueAllowMultipleNulls(): void
    {
        $this->skipIf(ConnectionManager::get('test')->getDriver() instanceof Sqlserver);

        $table = $this->getCollectionLocator()->get('UniqueAuthors');
        $rules = $table->rulesChecker();
        $rules->add($rules->isUnique(
            ['first_author_id', 'second_author_id'],
        ));

        $entity = new Document([
            'first_author_id' => null,
            'second_author_id' => '000000000000000000000001',
        ]);
        $this->assertNotEmpty($table->save($entity));

        $entity->first_author_id = '000000000000000000000002';
        $this->assertSame($entity, $table->save($entity));

        $entity = new Document([
            'first_author_id' => '000000000000000000000002',
            'second_author_id' => '000000000000000000000001',
        ]);
        $this->assertFalse($table->save($entity));
        $this->assertEquals(['first_author_id' => ['isUnique' => 'This value is already in use']], $entity->getErrors());
    }

    /**
     * Tests the existsIn domain rule
     */
    public function testExistsInDomainRule(): void
    {
        $entity = new Document([
            'title' => 'An Article',
            'author_id' => '507f1f77bcf86cd799439011',
        ]);

        $table = $this->getCollectionLocator()->get('Articles');
        $table->belongsTo('Authors');
        $rules = $table->rulesChecker();
        $rules->add($rules->existsIn('author_id', 'Authors'));

        $this->assertFalse($table->save($entity));
        $this->assertEquals(['existsIn' => 'This value does not exist'], $entity->getError('author_id'));
    }

    /**
     * Ensure that add(existsIn()) only invokes a rule once.
     */
    public function testExistsInRuleSingleInvocation(): void
    {
        $entity = new Document([
            'title' => 'larry',
            'author_id' => '507f1f77bcf86cd799439011',
        ]);

        $table = $this->getCollectionLocator()->get('Articles');
        $table->belongsTo('Authors');
        $rules = $table->rulesChecker();
        $rules->add($rules->existsIn('author_id', 'Authors'), 'existsIn', ['errorField' => 'other']);
        $this->assertFalse($table->save($entity));

        $this->assertEquals(
            ['existsIn' => 'This value does not exist'],
            $entity->getError('other'),
            'Provided field should have errors',
        );
        $this->assertEmpty($entity->getError('author_id'), 'Errors should not apply to original field.');
    }

    /**
     * Tests the existsIn domain rule when passing an object
     */
    public function testExistsInDomainRuleWithObject(): void
    {
        $entity = new Document([
            'title' => 'An Article',
            'author_id' => '507f1f77bcf86cd799439011',
        ]);

        $table = $this->getCollectionLocator()->get('Articles');
        $rules = $table->rulesChecker();
        $rules->add($rules->existsIn('author_id', $this->getCollectionLocator()->get('Authors'), 'Nope'));

        $this->assertFalse($table->save($entity));
        $this->assertEquals(['existsIn' => 'Nope'], $entity->getError('author_id'));
    }

    /**
     * ExistsIn uses the schema to verify that nullable fields are ok.
     */
    public function testExistsInNullValue(): void
    {
        $entity = new Document([
            'title' => 'An Article',
            'author_id' => null,
        ]);

        $table = $this->getCollectionLocator()->get('Articles');
        $table->belongsTo('Authors');
        $rules = $table->rulesChecker();
        $rules->add($rules->existsIn('author_id', 'Authors'));

        $this->assertEquals($entity, $table->save($entity));
        $this->assertEquals([], $entity->getError('author_id'));
    }

    /**
     * Test ExistsIn on a new entity that doesn't have the field populated.
     *
     * This use case is important for saving records and their
     * associated belongsTo records in one pass.
     */
    public function testExistsInNotNullValueNewEntity(): void
    {
        $entity = new Document([
            'name' => 'A Category',
        ]);
        $table = $this->getCollectionLocator()->get('Categories');
        $table->belongsTo('Categories', [
            'foreignKey' => 'parent_id',
            'bindingKey' => 'id',
        ]);
        $rules = $table->rulesChecker();
        $rules->add($rules->existsIn('parent_id', 'Categories'));
        $this->assertTrue($table->checkRules($entity, RulesChecker::CREATE));
        $this->assertEmpty($entity->getError('parent_id'));
    }

    /**
     * Tests exists in uses the bindingKey of the association
     */
    public function testExistsInWithBindingKey(): void
    {
        $entity = new Document([
            'title' => 'An Article',
        ]);

        $table = $this->getCollectionLocator()->get('Articles');
        $table->belongsTo('Authors', [
            'bindingKey' => 'name',
            'foreignKey' => 'title',
        ]);
        $rules = $table->rulesChecker();
        $rules->add($rules->existsIn('title', 'Authors'));

        $this->assertFalse($table->save($entity));
        $this->assertNotEmpty($entity->getError('title'));

        $entity->clean();
        $entity->title = 'larry';
        $this->assertEquals($entity, $table->save($entity));
    }

    /**
     * Tests existsIn with invalid associations
     */
    public function testExistsInInvalidAssociation(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('ExistsIn rule for `author_id` is invalid. `NotValid` is not associated with `Crustum\Mongo\ODM\BaseCollection`.');
        $entity = new Document([
            'title' => 'An Article',
            'author_id' => '507f1f77bcf86cd799439011',
        ]);

        $table = $this->getCollectionLocator()->get('Articles');
        $table->belongsTo('Authors');
        $rules = $table->rulesChecker();
        $rules->add($rules->existsIn('author_id', 'NotValid'));

        $table->save($entity);
    }

    /**
     * Tests existsIn does not prevent new entities from saving if parent entity is new
     */
    public function testExistsInHasManyNewEntities(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $table->hasMany('Comments');
        $table->Comments->belongsTo('Articles');

        $rules = $table->Comments->rulesChecker();
        $rules->add($rules->existsIn(['article_id'], $table));

        $article = $table->newEntity([
            'title' => 'new article',
            'comments' => [
                $table->Comments->newEntity([
                    'user_id' => '000000000000000000000001',
                    'comment' => 'comment 1',
                ]),
                $table->Comments->newEntity([
                    'user_id' => '000000000000000000000001',
                    'comment' => 'comment 2',
                ]),
            ],
        ]);

        $this->assertNotFalse($table->save($article));
    }

    /**
     * Tests existsIn does not prevent new entities from saving if parent entity is new,
     * getting the parent entity from the association
     */
    public function testExistsInHasManyNewEntitiesViaAssociation(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $table->hasMany('Comments');
        $table->Comments->belongsTo('Articles');

        $rules = $table->Comments->rulesChecker();
        $rules->add($rules->existsIn(['article_id'], 'Articles'));

        $article = $table->newEntity([
            'title' => 'test',
        ]);

        $article->comments = [
            $table->Comments->newEntity([
                'user_id' => '000000000000000000000001',
                'comment' => 'test',
            ]),
        ];

        $this->assertNotFalse($table->save($article));
    }

    /**
     * Tests the checkRules save option
     */
    public function testSkipRulesChecking(): void
    {
        $entity = new Document([
            'title' => 'An Article',
            'author_id' => '507f1f77bcf86cd799439011',
        ]);

        $table = $this->getCollectionLocator()->get('Articles');
        $rules = $table->rulesChecker();
        $rules->add($rules->existsIn('author_id', $this->getCollectionLocator()->get('Authors'), 'Nope'));

        $this->assertSame($entity, $table->save($entity, ['checkRules' => false]));
    }

    /**
     * Tests the beforeRules event
     */
    public function testUseBeforeRules(): void
    {
        $entity = new Document([
            'title' => 'An Article',
            'author_id' => '507f1f77bcf86cd799439011',
        ]);

        $table = $this->getCollectionLocator()->get('Articles');
        $rules = $table->rulesChecker();
        $rules->add($rules->existsIn('author_id', $this->getCollectionLocator()->get('Authors'), 'Nope'));

        $table->getEventManager()->on(
            'Collection.beforeRules',
            function (EventInterface $event, EntityInterface $entity, ArrayObject $options, $operation): void {
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

        $this->assertSame($entity, $table->save($entity));
    }

    /**
     * Tests the afterRules event
     */
    public function testUseAfterRules(): void
    {
        $entity = new Document([
            'title' => 'An Article',
            'author_id' => '507f1f77bcf86cd799439011',
        ]);

        $table = $this->getCollectionLocator()->get('Articles');
        $rules = $table->rulesChecker();
        $rules->add($rules->existsIn('author_id', $this->getCollectionLocator()->get('Authors'), 'Nope'));

        $table->getEventManager()->on(
            'Collection.afterRules',
            function (EventInterface $event, EntityInterface $entity, ArrayObject $options, $result, $operation): void {
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

        $this->assertSame($entity, $table->save($entity));
    }

    /**
     * Tests that rules can be changed using the buildRules event
     */
    public function testUseBuildRulesEvent(): void
    {
        $entity = new Document([
            'title' => 'An Article',
            'author_id' => '507f1f77bcf86cd799439011',
        ]);

        $table = $this->getCollectionLocator()->get('Articles');
        $table->getEventManager()->on('Collection.buildRules', function (EventInterface $event, RulesChecker $rules): void {
            $rules->add($rules->existsIn('author_id', $this->getCollectionLocator()->get('Authors'), 'Nope'));
        });

        $this->assertFalse($table->save($entity));
    }

    /**
     * Tests isUnique with untouched fields
     */
    public function testIsUniqueWithCleanFields(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $entity = $table->get('000000000000000000000001');
        $rules = $table->rulesChecker();
        $rules->add($rules->isUnique(['title', 'author_id'], 'Nope'));

        $entity->body = 'Foo';
        $this->assertSame($entity, $table->save($entity));

        $entity->title = 'Third Article';
        $this->assertFalse($table->save($entity));
    }

    /**
     * Tests isUnique rule with conflicting columns
     */
    public function testIsUniqueAliasPrefix(): void
    {
        $entity = new Document([
            'title' => 'An Article',
            'author_id' => '000000000000000000000001',
        ]);

        $table = $this->getCollectionLocator()->get('Articles');
        $table->belongsTo('Authors');
        $rules = $table->rulesChecker();
        $rules->add($rules->isUnique(['author_id']));

        $table->Authors->getEventManager()->on('Collection.beforeFind', function (EventInterface $event, $query): void {
            $query->leftJoin(['a2' => 'authors']);
        });

        $this->assertFalse($table->save($entity));
        $this->assertEquals(['isUnique' => 'This value is already in use'], $entity->getError('author_id'));
    }

    /**
     * Tests the existsIn rule when passing non dirty fields
     */
    public function testExistsInWithCleanFields(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $table->belongsTo('Authors');
        $rules = $table->rulesChecker();
        $rules->add($rules->existsIn('author_id', 'Authors'));

        $entity = $table->get('000000000000000000000001');
        $entity->title = 'Foo';
        $entity->author_id = '507f1f77bcf86cd799439011';
        $entity->setDirty('author_id', false);
        $this->assertSame($entity, $table->save($entity));
    }

    /**
     * Tests the existsIn with conflicting columns
     */
    public function testExistsInAliasPrefix(): void
    {
        $entity = new Document([
            'title' => 'An Article',
            'author_id' => '507f1f77bcf86cd799439011',
        ]);

        $table = $this->getCollectionLocator()->get('Articles');
        $table->belongsTo('Authors');
        $rules = $table->rulesChecker();
        $rules->add($rules->existsIn('author_id', 'Authors'));

        $table->Authors->getEventManager()->on('Collection.beforeFind', function (EventInterface $event, $query): void {
            $query->leftJoin(['a2' => 'authors']);
        });

        $this->assertFalse($table->save($entity));
        $this->assertEquals(['existsIn' => 'This value does not exist'], $entity->getError('author_id'));
    }

    /**
     * Tests that using an array in existsIn() sets the error message correctly
     */
    public function testExistsInErrorWithArrayField(): void
    {
        $entity = new Document([
            'title' => 'An Article',
            'author_id' => '507f1f77bcf86cd799439011',
        ]);

        $table = $this->getCollectionLocator()->get('Articles');
        $table->belongsTo('Authors');
        $rules = $table->rulesChecker();
        $rules->add($rules->existsIn(['author_id'], 'Authors'));

        $this->assertFalse($table->save($entity));
        $this->assertEquals(['existsIn' => 'This value does not exist'], $entity->getError('author_id'));
    }

    /**
     * Tests new allowNullableNulls flag with author id set to null
     */
    public function testExistsInAllowNullableNullsOn(): void
    {
        $entity = new Document([
            'id' => 10,
            'author_id' => null,
            'site_id' => '000000000000000000000001',
            'name' => 'New Site Article without Author',
        ]);
        $table = $this->getCollectionLocator()->get('SiteArticles');
        $table->belongsTo('SiteAuthors');
        $rules = $table->rulesChecker();

        $rules->add($rules->existsIn(['author_id', 'site_id'], 'SiteAuthors', [
            'allowNullableNulls' => true,
        ]));
        $this->assertInstanceOf(Document::class, $table->save($entity));
    }

    /**
     * Tests new allowNullableNulls flag with author id set to null
     */
    public function testExistsInAllowNullableNullsOff(): void
    {
        $entity = new Document([
            'id' => 10,
            'author_id' => null,
            'site_id' => '000000000000000000000001',
            'name' => 'New Site Article without Author',
        ]);
        $table = $this->getCollectionLocator()->get('SiteArticles');
        $table->belongsTo('SiteAuthors');
        $rules = $table->rulesChecker();

        $rules->add($rules->existsIn(['author_id', 'site_id'], 'SiteAuthors', [
            'allowNullableNulls' => false,
        ]));
        $this->assertFalse($table->save($entity));
    }

    /**
     * Tests new allowNullableNulls flag with author id set to null
     */
    public function testExistsInAllowNullableNullsDefaultValue(): void
    {
        $entity = new Document([
            'id' => 10,
            'author_id' => null,
            'site_id' => '000000000000000000000001',
            'name' => 'New Site Article without Author',
        ]);
        $table = $this->getCollectionLocator()->get('SiteArticles');
        $table->belongsTo('SiteAuthors');
        $rules = $table->rulesChecker();

        $rules->add($rules->existsIn(['author_id', 'site_id'], 'SiteAuthors'));
        $this->assertFalse($table->save($entity));
    }

    /**
     * Tests new allowNullableNulls flag with author id set to null
     */
    public function testExistsInAllowNullableNullsCustomMessage(): void
    {
        $entity = new Document([
            'id' => 10,
            'author_id' => null,
            'site_id' => '000000000000000000000001',
            'name' => 'New Site Article without Author',
        ]);
        $table = $this->getCollectionLocator()->get('SiteArticles');
        $table->belongsTo('SiteAuthors');
        $rules = $table->rulesChecker();

        $rules->add($rules->existsIn(['author_id', 'site_id'], 'SiteAuthors', [
            'allowNullableNulls' => false,
            'message' => 'Niente',
        ]));
        $this->assertFalse($table->save($entity));
        $this->assertEquals(['author_id' => ['existsIn' => 'Niente']], $entity->getErrors());
    }

    /**
     * Tests new allowNullableNulls flag with author id set to 1
     */
    public function testExistsInAllowNullableNullsOnAllKeysSet(): void
    {
        $entity = new Document([
            'id' => 10,
            'author_id' => '000000000000000000000001',
            'site_id' => '000000000000000000000001',
            'name' => 'New Site Article with Author',
        ]);
        $table = $this->getCollectionLocator()->get('SiteArticles');
        $table->belongsTo('SiteAuthors');
        $rules = $table->rulesChecker();

        $rules->add($rules->existsIn(['author_id', 'site_id'], 'SiteAuthors', ['allowNullableNulls' => true]));
        $this->assertInstanceOf(Document::class, $table->save($entity));
    }

    /**
     * Tests new allowNullableNulls flag with author id set to 1
     */
    public function testExistsInAllowNullableNullsOffAllKeysSet(): void
    {
        $entity = new Document([
            'id' => 10,
            'author_id' => '000000000000000000000001',
            'site_id' => '000000000000000000000001',
            'name' => 'New Site Article with Author',
        ]);
        $table = $this->getCollectionLocator()->get('SiteArticles');
        $table->belongsTo('SiteAuthors');
        $rules = $table->rulesChecker();

        $rules->add($rules->existsIn(['author_id', 'site_id'], 'SiteAuthors', ['allowNullableNulls' => false]));
        $this->assertInstanceOf(Document::class, $table->save($entity));
    }

    /**
     * Tests new allowNullableNulls flag with author id set to 1
     */
    public function testExistsInAllowNullableNullsOnAllKeysCustomMessage(): void
    {
        $entity = new Document([
            'id' => 10,
            'author_id' => '000000000000000000000001',
            'site_id' => '000000000000000000000001',
            'name' => 'New Site Article with Author',
        ]);
        $table = $this->getCollectionLocator()->get('SiteArticles');
        $table->belongsTo('SiteAuthors');
        $rules = $table->rulesChecker();

        $rules->add($rules->existsIn(['author_id', 'site_id'], 'SiteAuthors', [
            'allowNullableNulls' => true,
            'message' => 'will not error']));
        $this->assertInstanceOf(Document::class, $table->save($entity));
    }

    /**
     * Tests new allowNullableNulls flag with author id set to 99999999 (does not exist)
     */
    public function testExistsInAllowNullableNullsOnInvalidKey(): void
    {
        $entity = new Document([
            'id' => 10,
            'author_id' => '507f1f77bcf86cd799439011',
            'site_id' => '000000000000000000000001',
            'name' => 'New Site Article with Author',
        ]);
        $table = $this->getCollectionLocator()->get('SiteArticles');
        $table->belongsTo('SiteAuthors');
        $rules = $table->rulesChecker();

        $rules->add($rules->existsIn(['author_id', 'site_id'], 'SiteAuthors', [
            'allowNullableNulls' => true,
            'message' => 'will error']));
        $this->assertFalse($table->save($entity));
        $this->assertEquals(['author_id' => ['existsIn' => 'will error']], $entity->getErrors());
    }

    /**
     * Tests new allowNullableNulls flag with author id set to 99999999 (does not exist)
     * and site_id set to 99999999 (does not exist)
     */
    public function testExistsInAllowNullableNullsOnInvalidKeys(): void
    {
        $entity = new Document([
            'id' => 10,
            'author_id' => '507f1f77bcf86cd799439011',
            'site_id' => '507f1f77bcf86cd799439011',
            'name' => 'New Site Article with Author',
        ]);
        $table = $this->getCollectionLocator()->get('SiteArticles');
        $table->belongsTo('SiteAuthors');
        $rules = $table->rulesChecker();

        $rules->add($rules->existsIn(['author_id', 'site_id'], 'SiteAuthors', [
            'allowNullableNulls' => true,
            'message' => 'will error']));
        $this->assertFalse($table->save($entity));
        $this->assertEquals(['author_id' => ['existsIn' => 'will error']], $entity->getErrors());
    }

    /**
     * Tests new allowNullableNulls flag with author id set to 1 (does exist)
     * and site_id set to 99999999 (does not exist)
     */
    public function testExistsInAllowNullableNullsOnInvalidKeySecond(): void
    {
        $entity = new Document([
            'id' => 10,
            'author_id' => '000000000000000000000001',
            'site_id' => '507f1f77bcf86cd799439011',
            'name' => 'New Site Article with Author',
        ]);
        $table = $this->getCollectionLocator()->get('SiteArticles');
        $table->belongsTo('SiteAuthors');
        $rules = $table->rulesChecker();

        $rules->add($rules->existsIn(['author_id', 'site_id'], 'SiteAuthors', [
            'allowNullableNulls' => true,
            'message' => 'will error']));
        $this->assertFalse($table->save($entity));
        $this->assertEquals(['author_id' => ['existsIn' => 'will error']], $entity->getErrors());
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
        $table = $this->getCollectionLocator()->get('SiteArticles');
        $table->belongsTo('SiteAuthors');
        $rules = $table->rulesChecker();

        $rules->add($rules->existsIn(['author_id', 'site_id'], 'SiteAuthors', [
            'allowNullableNulls' => true,
            'message' => 'will error with array_combine warning']));
        $result = $table->saveMany($entities);
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
        $entity = new Document([
            'id' => 10,
            'author_id' => null,
            'site_id' => '000000000000000000000001',
            'name' => 'New Site Article without Author',
        ]);
        $table = $this->getCollectionLocator()->get('SiteArticles');
        $table->belongsTo('SiteAuthors');
        $rules = $table->rulesChecker();

        $rules->add($rules->existsInNullable(['author_id', 'site_id'], 'SiteAuthors'));
        $this->assertInstanceOf(Document::class, $table->save($entity));
    }

    /**
     * Tests existsInNullable helper method with valid values
     */
    public function testExistsInNullableMethodWithValidValues(): void
    {
        $entity = new Document([
            'id' => 10,
            'author_id' => '000000000000000000000001',
            'site_id' => '000000000000000000000001',
            'name' => 'New Site Article with Author',
        ]);
        $table = $this->getCollectionLocator()->get('SiteArticles');
        $table->belongsTo('SiteAuthors');
        $rules = $table->rulesChecker();

        $rules->add($rules->existsInNullable(['author_id', 'site_id'], 'SiteAuthors'));
        $this->assertInstanceOf(Document::class, $table->save($entity));
    }

    /**
     * Tests existsInNullable helper method with invalid values
     */
    public function testExistsInNullableMethodWithInvalidValues(): void
    {
        $entity = new Document([
            'id' => 10,
            'author_id' => '507f1f77bcf86cd799439011',
            'site_id' => '000000000000000000000001',
            'name' => 'New Site Article with Invalid Author',
        ]);
        $table = $this->getCollectionLocator()->get('SiteArticles');
        $table->belongsTo('SiteAuthors');
        $rules = $table->rulesChecker();

        $rules->add($rules->existsInNullable(['author_id', 'site_id'], 'SiteAuthors'));
        $this->assertFalse($table->save($entity));
        $this->assertEquals(['author_id' => ['_existsIn' => 'This value does not exist']], $entity->getErrors());
    }

    /**
     * Tests existsInNullable helper method with custom message
     */
    public function testExistsInNullableMethodWithCustomMessage(): void
    {
        $entity = new Document([
            'id' => 10,
            'author_id' => '507f1f77bcf86cd799439011',
            'site_id' => '000000000000000000000001',
            'name' => 'New Site Article',
        ]);
        $table = $this->getCollectionLocator()->get('SiteArticles');
        $table->belongsTo('SiteAuthors');
        $rules = $table->rulesChecker();

        $rules->add($rules->existsInNullable(['author_id', 'site_id'], 'SiteAuthors', 'Custom nullable message'));
        $this->assertFalse($table->save($entity));
        $this->assertEquals(['author_id' => ['_existsIn' => 'Custom nullable message']], $entity->getErrors());
    }

    /**
     * Tests using rules to prevent delete operations
     */
    public function testDeleteRules(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $rules = $table->rulesChecker();
        $rules->addDelete(function ($entity) {
            return false;
        });

        $entity = $table->get('000000000000000000000001');
        $this->assertFalse($table->delete($entity));
    }

    /**
     * Checks that it is possible to pass custom options to rules when saving
     */
    public function testCustomOptionsPassingSave(): void
    {
        $entity = new Document([
            'name' => 'jose',
        ]);

        $table = $this->getCollectionLocator()->get('Authors');
        $rules = $table->rulesChecker();
        $rules->add(function ($entity, $options) {
            $this->assertSame('bar', $options['foo']);
            $this->assertSame('option', $options['another']);

            return false;
        }, ['another' => 'option']);

        $this->assertFalse($table->save($entity, ['foo' => 'bar']));
    }

    /**
     * Tests passing custom options to rules from delete
     */
    public function testCustomOptionsPassingDelete(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $rules = $table->rulesChecker();
        $rules->addDelete(function ($entity, $options) {
            $this->assertSame('bar', $options['foo']);
            $this->assertSame('option', $options['another']);

            return false;
        }, ['another' => 'option']);

        $entity = $table->get('000000000000000000000001');
        $this->assertFalse($table->delete($entity, ['foo' => 'bar']));
    }

    /**
     * Test adding rules that return error string
     */
    public function testCustomErrorMessageFromRule(): void
    {
        $entity = new Document([
            'name' => 'larry',
        ]);

        $table = $this->getCollectionLocator()->get('Authors');
        $rules = $table->rulesChecker();
        $rules->add(function () {
            return 'So much nope';
        }, ['errorField' => 'name']);

        $this->assertFalse($table->save($entity));
        $this->assertEquals(['So much nope'], $entity->getError('name'));
    }

    /**
     * Test adding rules with no errorField now sets errors under _rule key
     */
    public function testCustomErrorMessageFromRuleNoErrorField(): void
    {
        $entity = new Document([
            'name' => 'larry',
        ]);

        $table = $this->getCollectionLocator()->get('Authors');
        $rules = $table->rulesChecker();
        $rules->add(function () {
            return 'So much nope';
        });

        $this->assertFalse($table->save($entity));
        $this->assertNotEmpty($entity->getErrors());
        $this->assertEquals(['So much nope'], $entity->getError('_rule'));
    }

    /**
     * Tests that using existsIn for a hasMany association will not be called
     * as the foreign key for the association was automatically validated already.
     */
    public function testAvoidExistsInOnAutomaticSaving(): void
    {
        $entity = new Document([
            'name' => 'Jose',
        ]);
        $entity->articles = [
            new Document([
                'title' => '1',
                'body' => 'A body',
            ]),
            new Document([
                'title' => 'Another Title',
                'body' => 'Another body',
            ]),
        ];

        $table = $this->getCollectionLocator()->get('authors');
        $table->hasMany('articles');
        $table->getAssociation('articles')->belongsTo('authors');
        $checker = $table->getAssociation('articles')->getTarget()->rulesChecker();
        $checker->add(function ($entity, $options) use ($checker) {
            $rule = $checker->existsIn('author_id', 'authors');
            $id = $entity->author_id;
            $entity->author_id = '507f1f77bcf86cd799439011';
            $result = $rule($entity, $options);
            $this->assertTrue($result);
            $entity->author_id = $id;

            return true;
        });

        $this->assertSame($entity, $table->save($entity));
    }

    /**
     * Tests the existsIn domain rule respects the conditions set for the associations
     */
    public function testExistsInDomainRuleWithAssociationConditions(): void
    {
        $entity = new Document([
            'title' => 'An Article',
            'author_id' => '000000000000000000000001',
        ]);

        $table = $this->getCollectionLocator()->get('Articles');
        $table->belongsTo('Authors', [
            'conditions' => ['Authors.name !=' => 'mariano'],
        ]);
        $rules = $table->rulesChecker();
        $rules->add($rules->existsIn('author_id', 'Authors'));

        $this->assertFalse($table->save($entity));
        $this->assertEquals(['existsIn' => 'This value does not exist'], $entity->getError('author_id'));
    }

    /**
     * Tests that associated items have a count of X.
     */
    public function testCountOfAssociatedItems(): void
    {
        $entity = new Document([
            'title' => 'A Title',
            'body' => 'A body',
        ]);
        $entity->tags = [
            new Document([
                'name' => 'Something New',
            ]),
            new Document([
                'name' => '100',
            ]),
        ];

        $this->getCollectionLocator()->get('ArticlesTags');

        $table = $this->getCollectionLocator()->get('articles');
        $table->belongsToMany('tags');

        $rules = $table->rulesChecker();
        $rules->add($rules->validCount('tags', 3));

        $this->assertFalse($table->save($entity));
        $this->assertEquals($entity->getErrors(), [
            'tags' => [
                'validCount' => 'The count does not match >3',
            ],
        ]);

        // Testing that undesired types fail
        $entity->tags = null;
        $this->assertFalse($table->save($entity));

        $entity->tags = new stdClass();
        $this->assertFalse($table->save($entity));

        $entity->tags = 'string';
        $this->assertFalse($table->save($entity));

        $entity->tags = 123456;
        $this->assertFalse($table->save($entity));

        $entity->tags = 0.512;
        $this->assertFalse($table->save($entity));
    }

    /**
     * Tests that the error field name is inferred from the association name in case no name is provided.
     */
    public function testIsLinkedToInferFieldFromAssociationName(): void
    {
        $Comments = $this->getCollectionLocator()->get('Comments');
        $Comments->belongsTo('Articles');

        $comment = $Comments->save($Comments->newEntity([
            'article_id' => '507f1f77bcf86cd799439011',
            'user_id' => '000000000000000000000001',
            'comment' => 'Orphaned Comment',
        ]));

        /** @var \\Crustum\\Mongo\\ODM\\RulesChecker $rulesChecker */
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

        /** @var \\Crustum\\Mongo\\ODM\\RulesChecker $rulesChecker */
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

            public function buildRules(\Cake\Datasource\RulesChecker $rules): \Cake\Datasource\RulesChecker
            {
                return $rules->addUpdate(
                    $rules->isLinkedTo('Articles'),
                    ['repository' => $this],
                );
            }
        };

        $comment = $Comments->save($Comments->newEntity([
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

            public function buildRules(\Cake\Datasource\RulesChecker $rules): \Cake\Datasource\RulesChecker
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

        $comment = $Comments->save($Comments->newEntity([
            'article_id' => '507f1f77bcf86cd799439011',
            'user_id' => '000000000000000000000001',
            'comment' => 'Orphaned Comment',
        ]));

        /** @var \\Crustum\\Mongo\\ODM\\RulesChecker $rulesChecker */
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

        /** @var \\Crustum\\Mongo\\ODM\\RulesChecker $rulesChecker */
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

        $comment = $Comments->save($Comments->newEntity([
            'article_id' => '507f1f77bcf86cd799439011',
            'user_id' => '000000000000000000000001',
            'comment' => 'Orphaned Comment',
        ]));

        /** @var \\Crustum\\Mongo\\ODM\\RulesChecker $rulesChecker */
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

        /** @var \\Crustum\\Mongo\\ODM\\RulesChecker $rulesChecker */
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

        $comment = $Comments->save($Comments->newEntity([
            'article_id' => '507f1f77bcf86cd799439011',
            'user_id' => '000000000000000000000001',
            'comment' => 'Orphaned Comment',
        ]));

        /** @var \\Crustum\\Mongo\\ODM\\RulesChecker $rulesChecker */
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

        /** @var \\Crustum\\Mongo\\ODM\\RulesChecker $rulesChecker */
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

        $comment = $Comments->save($Comments->newEntity([
            'article_id' => '507f1f77bcf86cd799439011',
            'user_id' => '000000000000000000000001',
            'comment' => 'Orphaned Comment',
        ]));

        /** @var \\Crustum\\Mongo\\ODM\\RulesChecker $rulesChecker */
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

        /** @var \\Crustum\\Mongo\\ODM\\RulesChecker $rulesChecker */
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

        $comment = $Comments->save($Comments->newEntity([
            'article_id' => '507f1f77bcf86cd799439011',
            'user_id' => '000000000000000000000001',
            'comment' => 'Orphaned Comment',
        ]));

        /** @var \\Crustum\\Mongo\\ODM\\RulesChecker $rulesChecker */
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

        /** @var \\Crustum\\Mongo\\ODM\\RulesChecker $rulesChecker */
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

        /** @var \\Crustum\\Mongo\\ODM\\RulesChecker $rulesChecker */
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

        /** @var \\Crustum\\Mongo\\ODM\\RulesChecker $rulesChecker */
        $rulesChecker = $Articles->rulesChecker();
        $rulesChecker->addDelete(
            $rulesChecker->isNotLinkedTo('Comments', 'comments'),
        );

        $article = $Articles->get('000000000000000000000003');
        $this->assertTrue($Articles->delete($article));
    }
}
