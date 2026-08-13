<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use Cake\Core\Exception\CakeException;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Behavior;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use TestApp\Model\Behavior\Test2Behavior;
use TestApp\Model\Behavior\Test3Behavior;
use TestApp\Model\Behavior\TestBehavior;

/**
 * Port of `Cake\Test\TestCase\ORM\BehaviorTest` for the ODM layer.
 */
#[CoversClass(Behavior::class)]
class BehaviorTest extends TestCase
{
    public function testConstructor(): void
    {
        $collection = Mockery::mock(BaseCollection::class);
        $config = ['key' => 'value'];
        $behavior = new TestBehavior($collection, $config);
        $this->assertEquals($config, $behavior->getConfig());
    }

    public function testGetCollection(): void
    {
        $collection = Mockery::mock(BaseCollection::class);

        $behavior = new TestBehavior($collection);
        $this->assertSame($collection, $behavior->collection());
    }

    public function testReflectionCache(): void
    {
        $collection = Mockery::mock(BaseCollection::class);
        $behavior = new Test3Behavior($collection);
        $expected = [
            'foo' => 'findFoo',
        ];
        $this->assertEquals($expected, $behavior->testReflectionCache());
    }

    public function testImplementedEvents(): void
    {
        $collection = Mockery::mock(BaseCollection::class);
        $behavior = new TestBehavior($collection);
        $expected = [
            'Collection.beforeFind' => 'beforeFind',
            'Collection.afterSaveCommit' => 'afterSaveCommit',
            'Collection.buildRules' => 'buildRules',
            'Collection.beforeRules' => 'beforeRules',
            'Collection.afterRules' => 'afterRules',
            'Collection.afterDeleteCommit' => 'afterDeleteCommit',
        ];
        $this->assertEquals($expected, $behavior->implementedEvents());
    }

    public function testImplementedEventsWithPriority(): void
    {
        $collection = Mockery::mock(BaseCollection::class);
        $behavior = new TestBehavior($collection, ['priority' => 10]);
        $expected = [
            'Collection.beforeFind' => [
                'priority' => 10,
                'callable' => 'beforeFind',
            ],
            'Collection.afterSaveCommit' => [
                'priority' => 10,
                'callable' => 'afterSaveCommit',
            ],
            'Collection.beforeRules' => [
                'priority' => 10,
                'callable' => 'beforeRules',
            ],
            'Collection.afterRules' => [
                'priority' => 10,
                'callable' => 'afterRules',
            ],
            'Collection.buildRules' => [
                'priority' => 10,
                'callable' => 'buildRules',
            ],
            'Collection.afterDeleteCommit' => [
                'priority' => 10,
                'callable' => 'afterDeleteCommit',
            ],
        ];
        $this->assertEquals($expected, $behavior->implementedEvents());
    }

    public function testImplementedFinders(): void
    {
        $collection = Mockery::mock(BaseCollection::class);
        $behavior = new Test2Behavior($collection);
        $expected = [
            'foo' => 'findFoo',
        ];
        $this->assertEquals($expected, $behavior->implementedFinders());
    }

    public function testImplementedFindersAliased(): void
    {
        $collection = Mockery::mock(BaseCollection::class);
        $behavior = new Test2Behavior($collection, [
            'implementedFinders' => [
                'aliased' => 'findFoo',
            ],
        ]);
        $expected = [
            'aliased' => 'findFoo',
        ];
        $this->assertEquals($expected, $behavior->implementedFinders());
    }

    public function testImplementedFindersDisabled(): void
    {
        $collection = Mockery::mock(BaseCollection::class);
        $behavior = new Test2Behavior($collection, [
            'implementedFinders' => [],
        ]);
        $this->assertEquals([], $behavior->implementedFinders());
    }

    public function testVerifyConfig(): void
    {
        $collection = Mockery::mock(BaseCollection::class);
        $behavior = new Test2Behavior($collection);
        $behavior->verifyConfig();
        $this->assertTrue(true, 'No exception thrown');
    }

    public function testVerifyConfigImplementedFindersOverridden(): void
    {
        $collection = Mockery::mock(BaseCollection::class);
        $behavior = new Test2Behavior($collection, [
            'implementedFinders' => [
                'aliased' => 'findFoo',
            ],
        ]);
        $behavior->verifyConfig();
        $this->assertTrue(true, 'No exception thrown');
    }

    public function testVerifyImplementedFindersInvalid(): void
    {
        $this->expectException(CakeException::class);
        $this->expectExceptionMessage('The finder method `findNotDefined` is not callable on `' . Test2Behavior::class . '`');
        $collection = Mockery::mock(BaseCollection::class);
        $behavior = new Test2Behavior($collection, [
            'implementedFinders' => [
                'aliased' => 'findNotDefined',
            ],
        ]);
        $behavior->verifyConfig();
    }

    public function testVerifyConfigImplementedMethodsOverridden(): void
    {
        $collection = Mockery::mock(BaseCollection::class);
        $behavior = new Test2Behavior($collection);
        $behavior = new Test2Behavior($collection, [
            'implementedMethods' => [
                'aliased' => 'doSomething',
            ],
        ]);
        $behavior->verifyConfig();
        $this->assertTrue(true, 'No exception thrown');
    }
}
