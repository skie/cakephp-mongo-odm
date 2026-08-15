<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\CollectionEventsTrait;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CollectionEventsTrait::class)]
#[CoversClass(BaseCollection::class)]
class CollectionImplementedEventsTest extends TestCase
{
    /**
     * Check that defining methods inside table classes will result in event listeners
     */
    public function testImplementedEvents(): void
    {
        $collection = new ImplementedEventsCollection();
        $result = $collection->implementedEvents();
        $expected = [
            'Collection.beforeMarshal' => 'beforeMarshal',
            'Collection.buildValidator' => 'buildValidator',
            'Collection.beforeFind' => 'beforeFind',
            'Collection.beforeSave' => 'beforeSave',
            'Collection.afterSave' => 'afterSave',
            'Collection.beforeDelete' => 'beforeDelete',
            'Collection.afterDelete' => 'afterDelete',
            'Collection.afterRules' => 'afterRules',
        ];
        $this->assertEquals($expected, $result, 'Events do not match.');
    }

    public function testImplementedEventsWithCollectionEventsTrait(): void
    {
        $collection = new ImplementedAllEventsCollection();
        $result = $collection->implementedEvents();
        $expected = [
            'Collection.beforeMarshal' => 'beforeMarshal',
            'Collection.afterMarshal' => 'afterMarshal',
            'Collection.buildValidator' => 'buildValidator',
            'Collection.beforeFind' => 'beforeFind',
            'Collection.beforeSave' => 'beforeSave',
            'Collection.afterSave' => 'afterSave',
            'Collection.afterSaveCommit' => 'afterSaveCommit',
            'Collection.beforeDelete' => 'beforeDelete',
            'Collection.afterDelete' => 'afterDelete',
            'Collection.afterDeleteCommit' => 'afterDeleteCommit',
            'Collection.beforeRules' => 'beforeRules',
            'Collection.afterRules' => 'afterRules',
        ];
        $this->assertEquals($expected, $result, 'Events do not match.');
    }
}

// phpcs:disable
class ImplementedEventsCollection extends BaseCollection
{
    public function buildValidator(): void {}
    public function beforeMarshal(): void {}
    public function beforeFind(): void {}
    public function beforeSave(): void {}
    public function afterSave(): void {}
    public function beforeDelete(): void {}
    public function afterDelete(): void {}
    public function afterRules(): void {}
}

class ImplementedAllEventsCollection extends BaseCollection
{
    use CollectionEventsTrait;
}
// phpcs:enable
