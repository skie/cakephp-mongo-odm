<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\View\Form;

use ArrayIterator;
use ArrayObject;
use Cake\Collection\Collection;
use Cake\Core\PluginApplicationInterface;
use Cake\Http\ServerRequest;
use Cake\Validation\Validator;
use Crustum\Mongo\MongoPlugin;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use Crustum\Mongo\View\Form\DocumentContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use TestApp\Model\Document\Article;

#[CoversClass(DocumentContext::class)]
class DocumentContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (new MongoPlugin())->bootstrap($this->createStub(PluginApplicationInterface::class));
    }

    protected function context(array $context): DocumentContext
    {
        $request = new ServerRequest();

        return new DocumentContext($request, $context);
    }

    public function testGetRequiredMessage(): void
    {
        $this->setupCollections();

        $context = $this->context([
            'entity' => new Article(),
            'collection' => 'Articles',
            'validator' => 'create',
        ]);

        $this->assertNull($context->getRequiredMessage('body'));
        $this->assertSame("Don't forget a title!", $context->getRequiredMessage('title'));
    }

    public function testEntity(): void
    {
        $row = new Article();
        $context = $this->context(['entity' => $row]);
        $this->assertSame($row, $context->entity());
    }

    public function testPrimaryKey(): void
    {
        $row = new Article();
        $context = $this->context(['entity' => $row]);
        $this->assertEquals(['_id'], $context->getPrimaryKey());
    }

    public function testIsPrimaryKey(): void
    {
        $this->setupCollections();

        $row = new Article();
        $context = $this->context(['entity' => $row]);
        $this->assertTrue($context->isPrimaryKey('_id'));
        $this->assertFalse($context->isPrimaryKey('title'));
        $this->assertTrue($context->isPrimaryKey('1._id'));
        $this->assertTrue($context->isPrimaryKey('Articles.1._id'));
    }

    public function testIsCreateSingle(): void
    {
        $row = new Article();
        $context = $this->context(['entity' => $row]);
        $this->assertTrue($context->isCreate());

        $row->setNew(false);
        $this->assertFalse($context->isCreate());

        $row->setNew(true);
        $this->assertTrue($context->isCreate());
    }

    #[DataProvider('collectionProvider')]
    public function testIsCreateCollection(ArrayObject|ArrayIterator|Collection|array $collection): void
    {
        $context = $this->context(['entity' => $collection]);
        $this->assertTrue($context->isCreate());
    }

    public function testInvalidCollection(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to find collection class for current entity');
        $row = new stdClass();
        $this->context(['entity' => $row]);
    }

    public function testDefaultEntityError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to find collection class for current entity');
        $this->context(['entity' => new Document()]);
    }

    public function testCollectionFromEntitySource(): void
    {
        $entity = new Document();
        $entity->setSource('Articles');

        $context = $this->context(['entity' => $entity]);
        $this->assertEquals(
            $this->getCollectionLocator()->get('Articles')->getSchema()->columns(),
            $context->fieldNames(),
        );
    }

    public function testOperationsNoEntity(): void
    {
        $context = $this->context(['collection' => 'Articles']);

        $this->assertNull($context->val('title'));
        $this->assertNull($context->isRequired('title'));
        $this->assertFalse($context->hasError('title'));
        $this->assertSame('string', $context->type('title'));
        $this->assertEquals([], $context->error('title'));
    }

    public function testOperationsNoCollectionArg(): void
    {
        $row = new Article([
            'title' => 'Test entity',
            'body' => 'Something new',
        ]);
        $row->setError('title', ['Title is required.']);

        $context = $this->context(['entity' => $row]);

        $this->assertEquals('Test entity', $context->val('title'));
        $this->assertEquals($row->getError('title'), $context->error('title'));
        $this->assertTrue($context->hasError('title'));
    }

    #[DataProvider('collectionProvider')]
    public function testCollectionOperationsNoCollectionArg(ArrayObject|ArrayIterator|Collection|array $collection): void
    {
        $context = $this->context(['entity' => $collection]);

        $this->assertSame('First post', $context->val('0.title'));
        $this->assertEquals(['Not long enough'], $context->error('1.body'));
        $this->assertNull($context->val('0'));
    }

    public static function collectionProvider(): array
    {
        $one = new Article([
            'title' => 'First post',
            'body' => 'Stuff',
            'user' => new Document(['username' => 'mark']),
        ]);
        $one->setError('title', 'Required field');

        $two = new Article([
            'title' => 'Second post',
            'body' => 'Some text',
            'user' => new Document(['username' => 'jose']),
        ]);
        $two->setError('body', 'Not long enough');

        return [
            'array' => [[$one, $two]],
            'basic iterator' => [new ArrayObject([$one, $two])],
            'array iterator' => [new ArrayIterator([$one, $two])],
            'collection' => [new Collection([$one, $two])],
        ];
    }

    #[DataProvider('collectionProvider')]
    public function testValOnCollections(ArrayObject|ArrayIterator|Collection|array $collection): void
    {
        $context = $this->context([
            'entity' => $collection,
            'collection' => 'Articles',
        ]);

        $this->assertSame('First post', $context->val('0.title'));
        $this->assertSame('mark', $context->val('0.user.username'));
        $this->assertSame('Second post', $context->val('1.title'));
        $this->assertSame('jose', $context->val('1.user.username'));
        $this->assertNull($context->val('nope'));
        $this->assertNull($context->val('99.title'));
    }

    #[DataProvider('collectionProvider')]
    public function testValOnCollectionsWithRootName(ArrayObject|ArrayIterator|Collection|array $collection): void
    {
        $context = $this->context([
            'entity' => $collection,
            'collection' => 'Articles',
        ]);

        $this->assertSame('First post', $context->val('Articles.0.title'));
        $this->assertSame('mark', $context->val('Articles.0.user.username'));
        $this->assertNull($context->val('Articles.99.title'));
    }

    #[DataProvider('collectionProvider')]
    public function testErrorsOnCollections(ArrayObject|ArrayIterator|Collection|array $collection): void
    {
        $context = $this->context([
            'entity' => $collection,
            'collection' => 'Articles',
        ]);

        $this->assertTrue($context->hasError('0.title'));
        $this->assertEquals(['Required field'], $context->error('0.title'));
        $this->assertFalse($context->hasError('0.body'));
        $this->assertFalse($context->hasError('1.title'));
        $this->assertEquals(['Not long enough'], $context->error('1.body'));
        $this->assertFalse($context->hasError('nope'));
    }

    public function testValBasic(): void
    {
        $row = new Article([
            'title' => 'Test entity',
            'body' => 'Something new',
        ]);
        $context = $this->context([
            'entity' => $row,
            'collection' => 'Articles',
        ]);
        $this->assertEquals('Test entity', $context->val('title'));
        $this->assertEquals('Something new', $context->val('body'));
        $this->assertNull($context->val('nope'));
    }

    public function testValInvalid(): void
    {
        $row = new Article([
            'title' => 'Valid title',
        ]);
        $row->setInvalidField('title', 'Invalid title');

        $context = $this->context([
            'entity' => $row,
            'collection' => 'Articles',
        ]);
        $this->assertSame('Invalid title', $context->val('title'));
    }

    public function testValDefaultArray(): void
    {
        $context = $this->context([
            'entity' => new Article(['prop' => ['title' => 'foo']]),
            'collection' => 'Articles',
        ]);
        $this->assertSame('foo', $context->val('prop.title', ['default' => 'bar']));
        $this->assertSame('bar', $context->val('prop.nope', ['default' => 'bar']));
    }

    public function testValGetArrayValue(): void
    {
        $row = new Article([
            'title' => 'Test entity',
            'types' => [1, 2, 3],
            'tag' => ['name' => 'Test tag'],
            'author' => new Document([
                'roles' => ['admin', 'publisher'],
                'aliases' => new ArrayObject(['dave', 'david']),
            ]),
        ]);
        $context = $this->context([
            'entity' => $row,
            'collection' => 'Articles',
        ]);
        $this->assertEquals([1, 2, 3], $context->val('types'));
        $this->assertEquals(['admin', 'publisher'], $context->val('author.roles'));
        $this->assertEquals('Test tag', $context->val('tag.name'));
        $this->assertEquals('dave', $context->val('author.aliases.0'));
        $this->assertNull($context->val('author.aliases.3'));
        $this->assertNull($context->val('tag.nope'));
        $this->assertNull($context->val('author.roles.3'));
    }

    public function testValAssociated(): void
    {
        $row = new Article([
            'title' => 'Test entity',
            'user' => new Document(['username' => 'mark', 'fname' => 'Mark']),
            'comments' => [
                new Document(['comment' => 'Test comment']),
                new Document(['comment' => 'Second comment']),
            ],
        ]);
        $context = $this->context([
            'entity' => $row,
            'collection' => 'Articles',
        ]);

        $this->assertEquals('Mark', $context->val('user.fname'));
        $this->assertEquals('Test comment', $context->val('comments.0.comment'));
        $this->assertEquals('Second comment', $context->val('comments.1.comment'));
        $this->assertNull($context->val('comments.0.nope'));
        $this->assertNull($context->val('comments.0.nope.no_way'));
    }

    public function testValMissingAssociation(): void
    {
        $row = new Article(['_id' => '1']);
        $context = $this->context([
            'entity' => $row,
            'collection' => 'Articles',
        ]);

        $this->assertEquals('1', $context->val('_id'));
        $this->assertNull($context->val('profile.id'));
    }

    public function testValAssociatedHasMany(): void
    {
        $row = new Article([
            'title' => 'First post',
            'user' => new Document([
                'username' => 'mark',
                'fname' => 'Mark',
                'articles' => [
                    new Article(['title' => 'First post']),
                    new Article(['title' => 'Second post']),
                ],
            ]),
        ]);
        $context = $this->context([
            'entity' => $row,
            'collection' => 'Articles',
        ]);

        $this->assertSame('First post', $context->val('user.articles.0.title'));
        $this->assertSame('Second post', $context->val('user.articles.1.title'));
    }

    public function testValAssociatedDefaultIds(): void
    {
        $row = new Article([
            'title' => 'First post',
            'user' => new Document([
                'username' => 'mark',
                'fname' => 'Mark',
                'sections' => [
                    new Document(['title' => 'PHP', '_id' => '1']),
                    new Document(['title' => 'Javascript', '_id' => '2']),
                ],
            ]),
        ]);
        $context = $this->context([
            'entity' => $row,
            'collection' => 'Articles',
        ]);

        $this->assertEquals(['1', '2'], $context->val('user.sections._ids'));
    }

    public function testIsRequiredStringValidator(): void
    {
        $this->setupCollections();

        $context = $this->context([
            'entity' => new Document(),
            'collection' => 'Articles',
            'validator' => 'create',
        ]);

        $this->assertTrue($context->isRequired('title'));
        $this->assertFalse($context->isRequired('body'));
        $this->assertNull($context->isRequired('Herp.derp.derp'));
        $this->assertNull($context->isRequired('nope'));
        $this->assertNull($context->isRequired(''));
    }

    public function testIsRequiredWithCallableAllowEmpty(): void
    {
        $this->setupCollections();

        $articles = $this->getCollectionLocator()->get('Articles');
        $validator = new Validator();
        $validator->notEmptyString('title', 'Title is required', fn(array $context) => $context['newRecord']);

        $articles->setValidator('default', $validator);

        $context = $this->context([
            'entity' => new Article(),
            'collection' => 'Articles',
        ]);

        $this->assertNull($context->isRequired('title'));
    }

    public function testIsRequiredAssociatedHasMany(): void
    {
        $this->setupCollections();

        $comments = $this->getCollectionLocator()->get('Comments');
        $validator = $comments->getValidator();
        $validator->add('user_id', 'number', ['rule' => 'numeric']);

        $row = new Article([
            'title' => 'My title',
            'comments' => [
                new Document(['comment' => 'First comment']),
                new Document(['comment' => 'Second comment']),
            ],
        ]);
        $context = $this->context([
            'entity' => $row,
            'collection' => 'Articles',
            'validator' => 'default',
        ]);

        $this->assertTrue($context->isRequired('comments.0.user_id'));
        $this->assertNull($context->isRequired('comments.0.other'));
        $this->assertNull($context->isRequired('user.0.other'));
        $this->assertNull($context->isRequired(''));
    }

    public function testType(): void
    {
        $this->setupCollections();

        $row = new Article([
            'title' => 'My title',
            'body' => 'Some content',
        ]);
        $context = $this->context([
            'entity' => $row,
            'collection' => 'Articles',
        ]);

        $this->assertSame('string', $context->type('title'));
        $this->assertSame('text', $context->type('body'));
        $this->assertSame('objectid', $context->type('user_id'));
        $this->assertNull($context->type('nope'));
    }

    public function testTypeAssociated(): void
    {
        $this->setupCollections();

        $row = new Article([
            'title' => 'My title',
            'user' => new Document(['username' => 'Mark']),
        ]);
        $context = $this->context([
            'entity' => $row,
            'collection' => 'Articles',
        ]);

        $this->assertSame('string', $context->type('user.username'));
        $this->assertNull($context->type('user.nope'));
    }

    public function testHasError(): void
    {
        $this->setupCollections();

        $row = new Article([
            'title' => 'My title',
            'user' => new Document(['username' => 'Mark']),
        ]);
        $row->setError('title', []);
        $row->setError('body', 'Gotta have one');
        $row->setError('user_id', ['Required field']);

        $context = $this->context([
            'entity' => $row,
            'collection' => 'Articles',
        ]);

        $this->assertFalse($context->hasError('title'));
        $this->assertFalse($context->hasError('nope'));
        $this->assertTrue($context->hasError('body'));
        $this->assertTrue($context->hasError('user_id'));
    }

    public function testHasErrorAssociated(): void
    {
        $this->setupCollections();

        $row = new Article([
            'title' => 'My title',
            'user' => new Document(['username' => 'Mark']),
        ]);
        $row->setError('title', []);
        $row->setError('body', 'Gotta have one');
        $row->user->setError('username', ['Required']);

        $context = $this->context([
            'entity' => $row,
            'collection' => 'Articles',
        ]);

        $this->assertTrue($context->hasError('user.username'));
        $this->assertFalse($context->hasError('user.nope'));
        $this->assertFalse($context->hasError('no.nope'));
    }

    public function testError(): void
    {
        $this->setupCollections();

        $row = new Article([
            'title' => 'My title',
            'user' => new Document(['username' => 'Mark']),
        ]);
        $row->setError('title', []);
        $row->setError('body', 'Gotta have one');
        $row->setError('user_id', ['Required field']);
        $row->user->setError('username', ['Required']);

        $context = $this->context([
            'entity' => $row,
            'collection' => 'Articles',
        ]);

        $this->assertEquals([], $context->error('title'));
        $this->assertEquals(['Gotta have one'], $context->error('body'));
        $this->assertEquals(['Required'], $context->error('user.username'));
    }

    public function testErrorAssociatedHasMany(): void
    {
        $this->setupCollections();

        $row = new Article([
            'title' => 'My title',
            'comments' => [
                new Document(['comment' => '']),
                new Document(['comment' => 'Second comment']),
            ],
        ]);
        $row->comments[0]->setError('comment', ['Is required']);
        $row->comments[0]->setError('article_id', ['Is required']);

        $context = $this->context([
            'entity' => $row,
            'collection' => 'Articles',
            'validator' => 'default',
        ]);

        $this->assertEquals([], $context->error('title'));
        $this->assertEquals([], $context->error('comments.0.user_id'));
        $this->assertEquals([], $context->error('comments.0'));
        $this->assertEquals(['Is required'], $context->error('comments.0.comment'));
        $this->assertEquals(['Is required'], $context->error('comments.0.article_id'));
        $this->assertEquals([], $context->error('comments.1'));
        $this->assertEquals([], $context->error('comments.1.comment'));
    }

    public function testErrorNestedValidator(): void
    {
        $this->setupCollections();

        $row = new Article([
            'title' => 'My title',
            'options' => ['subpages' => ''],
        ]);
        $row->setError('options', ['subpages' => ['_empty' => 'required value']]);

        $context = $this->context([
            'entity' => $row,
            'collection' => 'Articles',
        ]);
        $this->assertEquals(['_empty' => 'required value'], $context->error('options.subpages'));
    }

    public function testFieldNames(): void
    {
        $context = $this->context([
            'entity' => new Document(),
            'collection' => 'Articles',
        ]);
        $articles = $this->getCollectionLocator()->get('Articles');
        $this->assertEquals($articles->getSchema()->columns(), $context->fieldNames());
    }

    public function testValidatorEntityProvider(): void
    {
        $row = new Article([
            'title' => 'Test entity',
            'body' => 'Something new',
        ]);
        $context = $this->context([
            'entity' => $row,
            'collection' => 'Articles',
        ]);
        $context->isRequired('title');

        $articles = $this->getCollectionLocator()->get('Articles');
        $this->assertSame($row, $articles->getValidator()->getProvider('entity'));
    }

    protected function setupCollections(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->belongsTo('Users');
        $articles->hasMany('Comments');
        $articles->setSchema([
            '_id' => 'objectid',
            'title' => 'string',
            'user_id' => 'objectid',
            'body' => 'text',
        ]);

        $validator = new Validator();
        $validator->notEmptyString('title', "Don't forget a title!");
        $validator->add('title', 'minlength', ['rule' => ['minlength', 10]]);
        $validator->add('body', 'maxlength', ['rule' => ['maxlength', 1000]])->allowEmptyString('body');
        $articles->setValidator('create', $validator);

        $users = $this->getCollectionLocator()->get('Users');
        $users->setSchema([
            '_id' => 'objectid',
            'username' => 'string',
            'bio' => 'text',
        ]);

        $comments = $this->getCollectionLocator()->get('Comments');
        $comments->setSchema([
            '_id' => 'objectid',
            'comment' => 'string',
            'user_id' => 'objectid',
            'article_id' => 'objectid',
        ]);

        $validator = new Validator();
        $validator->notEmptyString('user_id');

        $comments->setValidator('default', $validator);

        $validator = new Validator();
        $validator->add('comment', 'length', ['rule' => ['minlength', 10]]);

        $comments->setValidator('custom', $validator);
    }
}
