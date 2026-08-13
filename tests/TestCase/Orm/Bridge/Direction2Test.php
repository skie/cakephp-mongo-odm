<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Orm\Bridge;

use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\Orm\Bridge\Orm\BelongsToOrm;
use Crustum\Mongo\Orm\Bridge\Orm\HasManyOrm;
use Crustum\Mongo\Orm\Bridge\Orm\HasOneOrm;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use TestApp\Model\Collection\FilesCollection;
use TestApp\Model\Table\FilesTable;

/**
 * Tests the P7 Direction-2 bridge associations (ODM Collection → ORM Table).
 *
 * The Mongo `Files` collection documents carry an `_id`; the SQL `files`
 * table rows reference it via `file_ref`. `belongsToOrm` loads one SQL row,
 * `hasManyOrm` loads all matching rows.
 */
class Direction2Test extends TestCase
{
    /**
     * SQL + Mongo fixtures.
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.Crustum/Mongo.Orm/Files',
    ];

    /**
     * The Direction-2 source collection.
     *
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected BaseCollection $Files;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->Files = $this->getCollectionLocator()->get('Files', [
            'className' => FilesCollection::class,
        ]);
        $this->assertInstanceOf(FilesCollection::class, $this->Files);

        $this->Files->deleteAll([]);
        $this->Files->saveOrFail($this->Files->newDocument(['_id' => '000000000000000000000001', 'path' => '/a']));
        $this->Files->saveOrFail($this->Files->newDocument(['_id' => '000000000000000000000002', 'path' => '/b']));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        unset($this->Files);

        parent::tearDown();
    }

    /**
     * Tests that belongsToOrm loads the single matching SQL row.
     *
     * @return void
     */
    public function testBelongsToOrmLoadsSingleRow(): void
    {
        $association = new BelongsToOrm('FileRow', $this->Files, [
            'foreignKey' => 'file_ref',
            'property' => 'fileRow',
            'className' => FilesTable::class,
        ]);

        $document = $this->Files->find()->where(['_id' => '000000000000000000000001'])->first();
        $association->load([$document]);

        $this->assertNotNull($document->get('fileRow'));
        $this->assertSame('report.pdf', $document->get('fileRow')->get('name'));
    }

    /**
     * Tests that hasManyOrm loads all matching SQL rows.
     *
     * @return void
     */
    public function testHasManyOrmLoadsRows(): void
    {
        $association = new HasManyOrm('FileRows', $this->Files, [
            'foreignKey' => 'file_ref',
            'property' => 'fileRows',
            'className' => FilesTable::class,
        ]);

        $document = $this->Files->find()->where(['_id' => '000000000000000000000001'])->first();
        $association->load([$document]);

        $this->assertCount(1, $document->get('fileRows'));
        $this->assertSame('report.pdf', $document->get('fileRows')[0]->get('name'));
    }

    /**
     * Tests that the registered sugar resolves via getOrmAssociation().
     *
     * @return void
     */
    public function testRegisteredSugarAssociations(): void
    {
        $this->assertInstanceOf(BelongsToOrm::class, $this->Files->getOrmAssociation('FileRow'));
        $this->assertInstanceOf(HasManyOrm::class, $this->Files->getOrmAssociation('FileRows'));
    }

    /**
     * Tests that find() on a Direction-2 association is lazy.
     *
     * @return void
     */
    public function testFindReturnsLazyQuery(): void
    {
        $association = new HasOneOrm('FileRow', $this->Files, [
            'foreignKey' => 'file_ref',
            'property' => 'row',
            'className' => FilesTable::class,
        ]);

        $query = $association->find();
        $this->assertCount(1, $query->all());
    }
}
