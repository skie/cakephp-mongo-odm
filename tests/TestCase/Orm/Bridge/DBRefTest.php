<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Orm\Bridge;

use Cake\ORM\Table;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\Orm\Bridge\DBRef;
use Crustum\Mongo\Orm\Bridge\MongoCollectionAwareInterface;
use Crustum\Mongo\Orm\Bridge\Row\DocumentWrapper;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use TestApp\Model\Entity\File;
use TestApp\Model\Table\FilesTable;

/**
 * Tests the Direction-1 DBRef bridge association (SQL column → Mongo doc).
 *
 * The SQL `files.file_ref` column holds a Mongo `_id` (or a DBRef array);
 * the association resolves it against the Mongo `Files` collection.
 */
#[CoversClass(DBRef::class)]
class DBRefTest extends TestCase
{
    /**
     * The Files source table (bridge-aware fake).
     *
     * @var \Cake\ORM\Table&\Crustum\Mongo\Orm\Bridge\MongoCollectionAwareInterface
     */
    protected Table&MongoCollectionAwareInterface $Files;

    /**
     * The Mongo Files collection.
     *
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected BaseCollection $Target;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->Files = $this->fetchTable(FilesTable::class);
        $this->Target = $this->getCollectionLocator()->get('Files');

        $this->Target->deleteAll([]);
        $this->Target->saveOrFail($this->Target->newDocument(['_id' => '000000000000000000000001', 'path' => '/a/b.pdf']));
        $this->Target->saveOrFail($this->Target->newDocument(['_id' => '000000000000000000000002', 'path' => '/c/d.pdf']));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        unset($this->Files, $this->Target);

        parent::tearDown();
    }

    /**
     * Tests that DBRef loads the referenced Mongo document.
     *
     * @return void
     */
    public function testLoadDbref(): void
    {
        $association = new DBRef('Files', $this->Files);

        $file = new File(['id' => 1, 'name' => 'report.pdf', 'file_ref' => '000000000000000000000001']);
        $association->load([$file]);

        $this->assertNotNull($file->file);
        $this->assertSame('/a/b.pdf', $file->file->get('path'));
    }

    /**
     * Tests that DBRef accepts a DBRef array as the stored value.
     *
     * @return void
     */
    public function testLoadDbrefFromArray(): void
    {
        $association = new DBRef('Files', $this->Files);

        $file = new File([
            'id' => 2,
            'name' => 'report2.pdf',
            'file_ref' => ['$ref' => 'files', '$id' => '000000000000000000000002'],
        ]);
        $association->load([$file]);

        $this->assertNotNull($file->file);
        $this->assertSame('/c/d.pdf', $file->file->get('path'));
    }

    /**
     * Tests that DBRef attaches null when the pointer has no match.
     *
     * @return void
     */
    public function testLoadDbrefNoMatch(): void
    {
        $association = new DBRef('Files', $this->Files);

        $file = new File(['id' => 3, 'name' => 'report3.pdf', 'file_ref' => '000000000000000000000099']);
        $association->load([$file]);

        $this->assertNull($file->file);
    }

    /**
     * Tests that DBRef honors autoWrap and wraps the loaded document.
     *
     * @return void
     */
    public function testLoadDbrefWithAutoWrap(): void
    {
        $association = new DBRef('Files', $this->Files, [
            'autoWrap' => true,
        ]);

        $file = new File(['id' => 1, 'name' => 'report.pdf', 'file_ref' => '000000000000000000000001']);
        $association->load([$file]);

        $this->assertInstanceOf(DocumentWrapper::class, $file->file);
        $this->assertSame('/a/b.pdf', $file->file->get('path'));
    }

    /**
     * Tests that DBRef save() is a no-op (no bogus target document).
     *
     * @return void
     */
    public function testSaveIsNoOp(): void
    {
        $association = new DBRef('Files', $this->Files);
        $countBefore = $this->Target->find()->count();

        $file = new File(['id' => 1, 'name' => 'report.pdf', 'file_ref' => '000000000000000000000001']);
        $result = $association->save($file, ['path' => '/bogus.pdf']);

        $this->assertTrue($result);
        $this->assertSame($countBefore, $this->Target->find()->count(), 'DBRef save() must not create a target document');
    }

    /**
     * Tests that the default foreign key follows the `{name}_ref` convention.
     *
     * @return void
     */
    public function testDefaultForeignKeyConvention(): void
    {
        $association = new DBRef('Files', $this->Files);

        $this->assertSame('file_ref', $association->getForeignKey());
        $this->assertSame('_id', $association->getBindingKey());
        $this->assertSame('file', $association->getProperty());
    }
}
