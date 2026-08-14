<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\View\Form;

use Cake\Core\PluginApplicationInterface;
use Cake\Http\ServerRequest;
use Crustum\Mongo\MongoPlugin;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use Crustum\Mongo\View\Form\DocumentContext;
use PHPUnit\Framework\Attributes\CoversClass;
use TestApp\Model\Collection\UsersCollection;

#[CoversClass(DocumentContext::class)]
class DocumentContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (new MongoPlugin())->bootstrap($this->createStub(PluginApplicationInterface::class));
    }

    public function testPrimaryKeyDefaultsToId(): void
    {
        $request = new ServerRequest();
        $context = new DocumentContext($request, [
            'entity' => new Document(),
            'collection' => new UsersCollection(),
        ]);

        $this->assertSame(['_id'], $context->getPrimaryKey());
        $this->assertTrue($context->isPrimaryKey('_id'));
        $this->assertFalse($context->isPrimaryKey('name'));
    }

    public function testCreateStateFromDocument(): void
    {
        $request = new ServerRequest();
        $collection = new UsersCollection();

        $newDocument = new Document();
        $context = new DocumentContext($request, [
            'entity' => $newDocument,
            'collection' => $collection,
        ]);
        $this->assertTrue($context->isCreate());

        $existing = new Document(['_id' => 'abc'], ['markNew' => false]);
        $context = new DocumentContext($request, [
            'entity' => $existing,
            'collection' => $collection,
        ]);
        $this->assertFalse($context->isCreate());
    }

    public function testValReadsFromRequestThenDocument(): void
    {
        $request = new ServerRequest(['post' => ['name' => 'posted']]);
        $context = new DocumentContext($request, [
            'entity' => new Document(['name' => 'entity']),
            'collection' => new UsersCollection(),
        ]);

        $this->assertSame('posted', $context->val('name'));

        $request = new ServerRequest();
        $context = new DocumentContext($request, [
            'entity' => new Document(['name' => 'entity']),
            'collection' => new UsersCollection(),
        ]);

        $this->assertSame('entity', $context->val('name'));
        $this->assertNull($context->val('missing'));
    }

    public function testResolvesCollectionFromDocumentSource(): void
    {
        $request = new ServerRequest();
        $document = new Document(['_id' => 'abc']);
        $document->setSource('Users');

        $context = new DocumentContext($request, ['entity' => $document]);

        $this->assertSame(['_id'], $context->getPrimaryKey());
    }

    public function testFieldNamesAndTypeAreNullSafeWithoutSchema(): void
    {
        $request = new ServerRequest();
        $context = new DocumentContext($request, [
            'entity' => new Document(),
            'collection' => new UsersCollection(),
        ]);

        // Schema is lazily introspected from the database (cake6 parity):
        // fieldNames() returns the live collection columns.
        $this->assertSame(['username', 'password', 'created', 'updated', '_id'], $context->fieldNames());
        $this->assertNull($context->type('name'));
        $this->assertSame([], $context->attributes('name'));
    }

    public function testErrorsFromDocument(): void
    {
        $document = new Document(['name' => 'x']);
        $document->setErrors(['name' => ['_required' => 'required']]);

        $request = new ServerRequest();
        $context = new DocumentContext($request, [
            'entity' => $document,
            'collection' => new UsersCollection(),
        ]);

        $this->assertTrue($context->hasError('name'));
        $this->assertSame(['_required' => 'required'], $context->error('name'));
        $this->assertFalse($context->hasError('missing'));
    }
}
