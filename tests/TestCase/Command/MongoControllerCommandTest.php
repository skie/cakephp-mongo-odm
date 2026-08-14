<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Command;

use Cake\Console\CommandInterface;
use Crustum\Mongo\Test\TestCase\Command\TestCase;

/**
 * MongoControllerCommandTest class
 */
class MongoControllerCommandTest extends TestCase
{
    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->_compareBasePath = ROOT . DS . 'tests' . DS . 'comparisons' . DS . 'Command' . DS;
    }

    /**
     * Test baking a CRUD controller with all default actions.
     *
     * @return void
     */
    public function testBakeController(): void
    {
        $this->generatedFiles = [
            APP . 'Controller/BakeProductsController.php',
        ];
        $this->exec('bake mongocontroller BakeProducts --connection mongo --no-test');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFilesExist($this->generatedFiles);

        $controller = file_get_contents($this->generatedFiles[0]);
        $this->assertStringContainsString('class BakeProductsController extends AppController', $controller);
        foreach (['index', 'view', 'add', 'edit', 'delete'] as $action) {
            $this->assertStringContainsString("public function {$action}(", $controller);
        }
    }

    /**
     * Test baking a controller with a restricted action list.
     *
     * @return void
     */
    public function testBakeControllerWithActionsOption(): void
    {
        $this->generatedFiles = [
            APP . 'Controller/BakeProductsController.php',
        ];
        $this->exec('bake mongocontroller BakeProducts --connection mongo --no-test --actions index,view');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFilesExist($this->generatedFiles);

        $controller = file_get_contents($this->generatedFiles[0]);
        $this->assertStringContainsString('public function index(', $controller);
        $this->assertStringContainsString('public function view(', $controller);
        $this->assertStringNotContainsString('public function add(', $controller);
    }

    /**
     * Test that components are resolved from the option.
     *
     * @return void
     */
    public function testGetComponents(): void
    {
        $command = new \Crustum\Mongo\Command\Bake\MongoControllerCommand();
        $args = new \Cake\Console\Arguments([], ['components' => 'Flash, FormProtection'], []);

        $this->assertSame(['Flash', 'FormProtection'], $command->getComponents($args));
    }

    /**
     * Test that helpers are resolved from the option.
     *
     * @return void
     */
    public function testGetHelpers(): void
    {
        $command = new \Crustum\Mongo\Command\Bake\MongoControllerCommand();
        $args = new \Cake\Console\Arguments([], ['helpers' => 'Html, Time'], []);

        $this->assertSame(['Html', 'Time'], $command->getHelpers($args));
    }
}
