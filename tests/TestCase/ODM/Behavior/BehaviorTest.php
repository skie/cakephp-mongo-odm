<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Behavior;

use ArrayObject;
use Cake\Event\Event;
use Crustum\Mongo\ODM\Behavior\TimestampBehavior;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use MongoDB\BSON\UTCDateTime;

final class BehaviorTest extends TestCase
{
    public function testTimestampUsesUtcDate(): void
    {
        $behavior = new TimestampBehavior(new BehaviorCollection());
        $document = new Document([], ['markNew' => true]);

        $behavior->handleEvent(new Event('Model.beforeSave'), $document, new ArrayObject());

        $this->assertInstanceOf(UTCDateTime::class, $document->get('created'));
        $this->assertInstanceOf(UTCDateTime::class, $document->get('modified'));
        $this->assertSame(0, $document->get('created')->toDateTime()->getOffset());
    }

    public function testDirtyTimestampIsNotOverwritten(): void
    {
        $behavior = new TimestampBehavior(new BehaviorCollection());
        $existing = new UTCDateTime(1577836800000);
        $document = new Document(['modified' => $existing], ['markNew' => true]);

        $behavior->handleEvent(new Event('Model.beforeSave'), $document, new ArrayObject());

        $this->assertSame($existing, $document->get('modified'));
    }
}
