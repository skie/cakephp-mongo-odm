<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Behavior;

use ArrayObject;
use Cake\Event\Event;
use Cake\I18n\DateTime;
use Crustum\Mongo\ODM\Behavior\TimestampBehavior;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;

final class BehaviorTest extends TestCase
{
    public function testTimestampUsesUtcDate(): void
    {
        $behavior = new TimestampBehavior(new BehaviorCollection());
        $document = new Document([], ['markNew' => true]);

        $behavior->handleEvent(new Event('Collection.beforeSave'), $document, new ArrayObject());

        $this->assertInstanceOf(DateTime::class, $document->get('created'));
        $this->assertInstanceOf(DateTime::class, $document->get('modified'));
        $this->assertSame(0, $document->get('created')->getOffset());
    }

    public function testDirtyTimestampIsNotOverwritten(): void
    {
        $behavior = new TimestampBehavior(new BehaviorCollection());
        $existing = new DateTime('2020-01-01 00:00:00');
        $document = new Document(['modified' => $existing], ['markNew' => true]);

        $behavior->handleEvent(new Event('Collection.beforeSave'), $document, new ArrayObject());

        $this->assertSame($existing, $document->get('modified'));
    }
}
