<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use Crustum\Mongo\ODM\Document;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONDocument;

final class DocumentTest extends TestCase
{
    public function testIdAndNewState(): void
    {
        $id = new ObjectId();
        $document = new Document(['_id' => $id, 'name' => 'one']);

        $this->assertSame((string)$id, $document->getId());
        $this->assertSame((string)$id, $document->id);
        $this->assertFalse($document->isNew());

        $new = new Document();
        $this->assertTrue($new->isNew());
        $new->setId((string)$id);
        $this->assertFalse($new->isNew());
    }

    public function testBsonConstructionAndExport(): void
    {
        $id = new ObjectId();
        $document = new Document(new BSONDocument([
            '_id' => $id,
            'when' => new UTCDateTime(1704067200000),
            'amount' => new Decimal128('12.50'),
            'nested' => new BSONDocument(['value' => $id]),
        ]));

        $data = $document->toArray();
        $this->assertSame((string)$id, $data['_id']);
        $this->assertSame('2024-01-01 00:00:00', $data['when']);
        $this->assertSame('12.50', $data['amount']);
        $this->assertSame((string)$id, $data['nested']['value']);
    }

    public function testDirtyOriginalVirtualAndHiddenStateUsesEntityTrait(): void
    {
        $document = new Document(['name' => 'one']);
        $document->clean();
        $document->setHidden(['name']);
        $document->setVirtual(['display']);
        $document->set('display', 'ONE');

        $this->assertTrue($document->isDirty('display'));
        $this->assertSame(['name'], $document->getOriginalFields());
        $this->assertSame(['display' => 'ONE'], $document->toArray());
    }
}
