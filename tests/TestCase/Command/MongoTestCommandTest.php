<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Command;

use Cake\Core\Exception\CakeException;
use Crustum\Mongo\Command\Bake\MongoTestCommand;
use TestApp\Model\Collection\ArticlesCollection;
use TestApp\Model\Document\Article;

/**
 * MongoTestCommandTest class
 *
 * Port of `Bake\Test\TestCase\Command\TestCommandTest` for the Mongo ODM.
 *
 * Only pure method tests are used here: `MongoTestCommand::getBasePath()`
 * resolves to the plugin's real `tests/TestCase/` directory, so integration
 * baking would write into the plugin's own test tree (the reference avoids
 * this by pointing ROOT at a throwaway test app).
 */
class MongoTestCommandTest extends TestCase
{
    /**
     * Test that getRealClassName resolves each supported type.
     *
     * @return void
     */
    public function testGetRealClassName(): void
    {
        $command = new MongoTestCommand();
        $this->assertSame(
            ArticlesCollection::class,
            $command->getRealClassName('Collection', 'Articles'),
        );
        $this->assertSame(
            Article::class,
            $command->getRealClassName('Document', 'Article'),
        );
        $this->assertSame(
            'TestApp\Controller\ArticlesController',
            $command->getRealClassName('Controller', 'Articles'),
        );
        $this->assertSame(
            'TestApp\Model\Behavior\TimestampBehavior',
            $command->getRealClassName('Behavior', 'Timestamp'),
        );
    }

    /**
     * Test that mapType throws for an invalid type.
     *
     * @return void
     */
    public function testMapTypeInvalid(): void
    {
        $command = new MongoTestCommand();

        $this->expectException(CakeException::class);
        $this->expectExceptionMessage('Invalid object type: NotAType');
        $command->mapType('NotAType');
    }

    /**
     * Test mapType returns the correct package names.
     *
     * @return void
     */
    public function testMapType(): void
    {
        $command = new MongoTestCommand();
        $this->assertSame('Controller', $command->mapType('Controller'));
        $this->assertSame('Controller\Component', $command->mapType('Component'));
        $this->assertSame('Model\Collection', $command->mapType('Collection'));
        $this->assertSame('Model\Document', $command->mapType('Document'));
        $this->assertSame('Model\Behavior', $command->mapType('Behavior'));
        $this->assertSame('View\Helper', $command->mapType('Helper'));
        $this->assertSame('', $command->mapType('Class'));
    }

    /**
     * Test filename generation for each type.
     *
     * @return void
     */
    public function testTestCaseFileName(): void
    {
        $command = new MongoTestCommand();
        $result = $command->testCaseFileName('Collection', ArticlesCollection::class);
        $this->assertPathEquals(
            ROOT . DS . 'tests' . DS . 'TestCase' . DS . 'Model' . DS . 'Collection' . DS . 'ArticlesCollectionTest.php',
            $result,
        );

        $result = $command->testCaseFileName('Document', Article::class);
        $this->assertPathEquals(
            ROOT . DS . 'tests' . DS . 'TestCase' . DS . 'Model' . DS . 'Document' . DS . 'ArticleTest.php',
            $result,
        );

        $result = $command->testCaseFileName('Controller', 'TestApp\Controller\Admin\PostsController');
        $this->assertPathEquals(
            ROOT . DS . 'tests' . DS . 'TestCase' . DS . 'Controller' . DS . 'Admin' . DS . 'PostsControllerTest.php',
            $result,
        );
    }

    /**
     * Test that mock class generation works for the appropriate classes.
     *
     * @return void
     */
    public function testMockClassGeneration(): void
    {
        $command = new MongoTestCommand();
        $this->assertTrue($command->hasMockClass('Controller'));
        $this->assertFalse($command->hasMockClass('Collection'));
    }

    /**
     * Test that typeCanDetectFixtures only applies to controller/collection.
     *
     * @return void
     */
    public function testTypeCanDetectFixtures(): void
    {
        $command = new MongoTestCommand();
        $this->assertTrue($command->typeCanDetectFixtures('Controller'));
        $this->assertTrue($command->typeCanDetectFixtures('Collection'));
        $this->assertFalse($command->typeCanDetectFixtures('Document'));
    }

    /**
     * Test that getTestableMethods filters parent/blacklisted methods.
     *
     * @return void
     */
    public function testGetTestableMethods(): void
    {
        $command = new MongoTestCommand();
        $result = $command->getTestableMethods(ArticlesCollection::class);

        $this->assertIsArray($result);
        $this->assertNotContains('initialize', $result);
    }
}
