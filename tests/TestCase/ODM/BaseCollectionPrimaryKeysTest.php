<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use TestApp\Model\Document\IntPrimaryItem;
use TestApp\Model\Document\UuidPrimaryItem;

/**
 * Tests that the ODM supports application-level primary keys that are not the
 * Mongo `_id` field: an integer `id` and a UUID `id`, with `_id` as a separate
 * driver-generated identifier.
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

    public function testIntRead(): void
    {
        $collection = $this->getCollectionLocator()->get('IntPrimaryItems', [
            'documentClass' => IntPrimaryItem::class,
            'primaryKey' => 'id',
        ]);
        $item = $collection->get(1);
        $this->assertSame(1, $item->get('id'));
        $this->assertSame('Item 1', $item->get('name'));
    }

    public function testUuidRead(): void
    {
        $collection = $this->getCollectionLocator()->get('UuidPrimaryItems', [
            'documentClass' => UuidPrimaryItem::class,
            'primaryKey' => 'id',
        ]);
        $uuid = '481fc6d0-b920-43e0-a40d-6d1740cf8569';
        $item = $collection->get($uuid);
        $this->assertSame($uuid, $item->get('id'));
        $this->assertSame('Item 1', $item->get('name'));
    }

    public function testIntSaveNew(): void
    {
        $collection = $this->getCollectionLocator()->get('IntPrimaryItems', [
            'documentClass' => IntPrimaryItem::class,
            'primaryKey' => 'id',
        ]);
        $item = new IntPrimaryItem(['id' => 99, 'name' => 'New Item']);
        $result = $collection->save($item);
        $this->assertNotFalse($result, 'Item should save.');
        $this->assertSame(99, $result->get('id'));
        $this->assertNotNull($result->getId(), 'Mongo _id should be assigned.');
    }

    public function testUuidSaveNew(): void
    {
        $collection = $this->getCollectionLocator()->get('UuidPrimaryItems', [
            'documentClass' => UuidPrimaryItem::class,
            'primaryKey' => 'id',
        ]);
        $item = new UuidPrimaryItem(['id' => 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d', 'name' => 'New Item']);
        $result = $collection->save($item);
        $this->assertNotFalse($result, 'Item should save.');
        $this->assertSame('a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d', $result->get('id'));
        $this->assertNotNull($result->getId(), 'Mongo _id should be assigned.');
    }

    public function testIntSaveExisting(): void
    {
        $collection = $this->getCollectionLocator()->get('IntPrimaryItems', [
            'documentClass' => IntPrimaryItem::class,
            'primaryKey' => 'id',
        ]);
        $item = $collection->get(1);
        $item->set('name', 'Updated 1');
        $result = $collection->save($item);
        $this->assertNotFalse($result, 'Item should save.');

        $reloaded = $collection->get(1);
        $this->assertSame('Updated 1', $reloaded->get('name'));
    }

    public function testUuidSaveExisting(): void
    {
        $collection = $this->getCollectionLocator()->get('UuidPrimaryItems', [
            'documentClass' => UuidPrimaryItem::class,
            'primaryKey' => 'id',
        ]);
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
        $collection = $this->getCollectionLocator()->get('IntPrimaryItems', [
            'documentClass' => IntPrimaryItem::class,
            'primaryKey' => 'id',
        ]);
        $updated = $collection->updateAll(['name' => 'Bulk Updated'], ['id' => 2]);
        $this->assertSame(1, $updated);

        $item = $collection->get(2);
        $this->assertSame('Bulk Updated', $item->get('name'));
    }

    public function testUuidUpdate(): void
    {
        $collection = $this->getCollectionLocator()->get('UuidPrimaryItems', [
            'documentClass' => UuidPrimaryItem::class,
            'primaryKey' => 'id',
        ]);
        $uuid = '48298a29-81c0-4c26-a7fb-413140cf8569';
        $updated = $collection->updateAll(['name' => 'Bulk Updated'], ['id' => $uuid]);
        $this->assertSame(1, $updated);

        $item = $collection->get($uuid);
        $this->assertSame('Bulk Updated', $item->get('name'));
    }

    public function testIntDelete(): void
    {
        $collection = $this->getCollectionLocator()->get('IntPrimaryItems', [
            'documentClass' => IntPrimaryItem::class,
            'primaryKey' => 'id',
        ]);
        $item = $collection->get(1);
        $this->assertTrue($collection->delete($item));
        $this->assertNull($collection->find()->where(['id' => 1])->first());
    }

    public function testUuidDelete(): void
    {
        $collection = $this->getCollectionLocator()->get('UuidPrimaryItems', [
            'documentClass' => UuidPrimaryItem::class,
            'primaryKey' => 'id',
        ]);
        $uuid = '481fc6d0-b920-43e0-a40d-6d1740cf8569';
        $item = $collection->get($uuid);
        $this->assertTrue($collection->delete($item));
        $this->assertNull($collection->find()->where(['id' => $uuid])->first());
    }
}
