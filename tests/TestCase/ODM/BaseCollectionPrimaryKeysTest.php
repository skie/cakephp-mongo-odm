<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use Crustum\Mongo\ODM\BaseCollection;
use TestApp\Model\Document\IntPrimaryItem;
use TestApp\Model\Document\UuidPrimaryItem;

/**
 * Tests that the ODM supports application-level primary keys that are not the
 * Mongo `_id` field: an integer auto-increment `id` and a UUID `id`, with `_id`
 * as a separate driver-generated identifier.
 *
 * @covers \Crustum\Mongo\ODM\BaseCollection
 */
class BaseCollectionPrimaryKeysTest extends TestCase
{
    /**
     * fixtures
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.Crustum/Mongo.IntPrimaryItems',
        'plugin.Crustum/Mongo.UuidPrimaryItems',
    ];

    protected function intCollection(): BaseCollection
    {
        $collection = $this->getCollectionLocator()->get('IntPrimaryItems', [
            'documentClass' => IntPrimaryItem::class,
            'primaryKey' => 'id',
        ]);
        $collection->setSchemaFromDocument(IntPrimaryItem::class);

        return $collection;
    }

    protected function uuidCollection(): BaseCollection
    {
        $collection = $this->getCollectionLocator()->get('UuidPrimaryItems', [
            'documentClass' => UuidPrimaryItem::class,
            'primaryKey' => 'id',
        ]);
        $collection->setSchemaFromDocument(UuidPrimaryItem::class);

        return $collection;
    }

    public function testIntRead(): void
    {
        $item = $this->intCollection()->get(10);
        $this->assertSame(10, $item->get('id'));
        $this->assertSame('Item 1', $item->get('name'));
    }

    public function testUuidRead(): void
    {
        $uuid = '481fc6d0-b920-43e0-a40d-6d1740cf8569';
        $item = $this->uuidCollection()->get($uuid);
        $this->assertSame($uuid, $item->get('id'));
        $this->assertSame('Item 1', $item->get('name'));
    }

    public function testIntSaveNew(): void
    {
        $collection = $this->intCollection();
        $item = new IntPrimaryItem(['id' => 99, 'name' => 'New Item']);
        $result = $collection->save($item);
        $this->assertNotFalse($result, 'Item should save.');
        $this->assertSame(99, $result->get('id'));
        $this->assertNotNull($result->getId(), 'Mongo _id should be assigned.');
    }

    public function testUuidSaveNew(): void
    {
        $collection = $this->uuidCollection();
        $item = new UuidPrimaryItem(['id' => 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d', 'name' => 'New Item']);
        $result = $collection->save($item);
        $this->assertNotFalse($result, 'Item should save.');
        $this->assertSame('a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d', $result->get('id'));
        $this->assertNotNull($result->getId(), 'Mongo _id should be assigned.');
    }

    public function testIntAutoIncrement(): void
    {
        $collection = $this->intCollection();
        $first = new IntPrimaryItem(['name' => 'Auto 1']);
        $result = $collection->save($first);
        $this->assertNotFalse($result, 'Item should save.');
        $this->assertSame(1, $result->get('id'), 'First auto-increment id.');
        $this->assertNotNull($result->getId(), 'Mongo _id should be assigned.');

        $saved = $collection->find()->where(['id' => $result->get('id')])->first();
        $this->assertSame('Auto 1', $saved->get('name'), 'Document is readable by its natural id.');

        $second = new IntPrimaryItem(['name' => 'Auto 2']);
        $result = $collection->save($second);
        $this->assertNotFalse($result, 'Item should save.');
        $this->assertSame(2, $result->get('id'), 'Sequential ids keep incrementing.');
    }

    public function testIntSaveExisting(): void
    {
        $collection = $this->intCollection();
        $item = $collection->get(10);
        $item->set('name', 'Updated 1');
        $result = $collection->save($item);
        $this->assertNotFalse($result, 'Item should save.');

        $reloaded = $collection->get(10);
        $this->assertSame('Updated 1', $reloaded->get('name'));
    }

    public function testUuidSaveExisting(): void
    {
        $collection = $this->uuidCollection();
        $uuid = '481fc6d0-b920-43e0-a40d-6d1740cf8569';
        $item = $collection->get($uuid);
        $item->set('name', 'Updated 1');
        $result = $collection->save($item);
        $this->assertNotFalse($result, 'Item should save.');

        $reloaded = $collection->get($uuid);
        $this->assertSame('Updated 1', $reloaded->get('name'));
    }

    public function testIntUpdate(): void
    {
        $collection = $this->intCollection();
        $updated = $collection->updateAll(['name' => 'Bulk Updated'], ['id' => 20]);
        $this->assertSame(1, $updated);

        $item = $collection->get(20);
        $this->assertSame('Bulk Updated', $item->get('name'));
    }

    public function testUuidUpdate(): void
    {
        $collection = $this->uuidCollection();
        $uuid = '48298a29-81c0-4c26-a7fb-413140cf8569';
        $updated = $collection->updateAll(['name' => 'Bulk Updated'], ['id' => $uuid]);
        $this->assertSame(1, $updated);

        $item = $collection->get($uuid);
        $this->assertSame('Bulk Updated', $item->get('name'));
    }

    public function testIntDelete(): void
    {
        $collection = $this->intCollection();
        $item = $collection->get(10);
        $this->assertTrue($collection->delete($item));
        $this->assertNull($collection->find()->where(['id' => 10])->first());
    }

    public function testUuidDelete(): void
    {
        $collection = $this->uuidCollection();
        $uuid = '481fc6d0-b920-43e0-a40d-6d1740cf8569';
        $item = $collection->get($uuid);
        $this->assertTrue($collection->delete($item));
        $this->assertNull($collection->find()->where(['id' => $uuid])->first());
    }
}
