<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Command;

use Cake\Console\Arguments;
use Cake\Console\CommandInterface;
use Crustum\Mongo\Command\Bake\MongoControllerCommand;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MongoControllerCommandTest class
 *
 * Port of `Bake\Test\TestCase\Command\ControllerCommandTest` for the Mongo ODM.
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
     * test main listing available collections.
     *
     * @return void
     */
    public function testMainListAvailable(): void
    {
        $this->exec('bake mongocontroller --connection mongo');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertOutputContains('Possible controllers based on your current database:');
        $this->assertOutputContains('- Products');
    }

    /**
     * test component generation
     *
     * @return void
     */
    public function testGetComponents(): void
    {
        $command = new MongoControllerCommand();
        $args = new Arguments([], [], []);
        $result = $command->getComponents($args);
        $this->assertSame([], $result);

        $args = new Arguments([], ['components' => '  , Auth, ,  RequestHandler'], []);
        $result = $command->getComponents($args);
        $this->assertSame(['Auth', 'RequestHandler'], $result);
    }

    /**
     * test helper generation
     *
     * @return void
     */
    public function testGetHelpers(): void
    {
        $command = new MongoControllerCommand();
        $args = new Arguments([], [], []);
        $result = $command->getHelpers($args);
        $this->assertSame([], $result);

        $args = new Arguments([], ['helpers' => '  , Session , ,  Number'], []);
        $result = $command->getHelpers($args);
        $this->assertSame(['Session', 'Number'], $result);
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
    public function testBakeActionsOption(): void
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
     * Test baking a controller with no actions.
     *
     * @return void
     */
    public function testBakeNoActions(): void
    {
        $this->generatedFiles = [
            APP . 'Controller/BakeProductsController.php',
        ];
        $this->exec('bake mongocontroller BakeProducts --connection mongo --no-test --no-actions');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFilesExist($this->generatedFiles);

        $controller = file_get_contents($this->generatedFiles[0]);
        $this->assertStringNotContainsString('public function index(', $controller);
    }

    /**
     * Test baking a controller with components + helpers.
     *
     * @return void
     */
    public function testBakeWithComponentsAndHelpers(): void
    {
        $this->generatedFiles = [
            APP . 'Controller/BakeProductsController.php',
        ];
        $this->exec(
            'bake mongocontroller BakeProducts --connection mongo --no-test ' .
            '--helpers Html,Time --components Flash,FormProtection',
        );

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFilesExist($this->generatedFiles);

        $controller = file_get_contents($this->generatedFiles[0]);
        $this->assertStringContainsString('@property \Cake\Controller\Component\FlashComponent $Flash', $controller);
        $this->assertStringContainsString('$this->loadComponent(\'Flash\');', $controller);
        $this->assertStringContainsString('$this->loadComponent(\'FormProtection\');', $controller);
        $this->assertStringContainsString("setHelpers(['Html', 'Time'])", $controller);
    }

    /**
     * test bake actions prefixed.
     *
     * @return void
     */
    public function testBakePrefixed(): void
    {
        $this->generatedFiles = [
            APP . 'Controller/Admin/BakeProductsController.php',
        ];
        $this->exec('bake mongocontroller BakeProducts --connection mongo --no-test --prefix admin');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFilesExist($this->generatedFiles);

        $controller = file_get_contents($this->generatedFiles[0]);
        $this->assertStringContainsString('namespace TestApp\Controller\Admin;', $controller);
        $this->assertStringContainsString('class BakeProductsController extends', $controller);
    }

    /**
     * test bake actions with nested prefixes.
     *
     * @return void
     */
    public function testBakePrefixNested(): void
    {
        $this->generatedFiles = [
            APP . 'Controller/Admin/Management/BakeProductsController.php',
        ];
        $this->exec('bake mongocontroller BakeProducts --connection mongo --no-test --prefix admin/management');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFilesExist($this->generatedFiles);

        $controller = file_get_contents($this->generatedFiles[0]);
        $this->assertStringContainsString('namespace TestApp\Controller\Admin\Management;', $controller);
    }

    /**
     * test baking a test (integration test disabled — writes into the plugin's
     * real `tests/TestCase/` tree). Verified via `--no-test` instead.
     *
     * @return void
     */
    public function testBakeTestDisabled(): void
    {
        $this->generatedFile = APP . 'Controller/BakeProductsController.php';
        $this->exec('bake mongocontroller BakeProducts --connection mongo --no-test');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFileExists($this->generatedFile);
        $this->assertFileDoesNotExist(ROOT . 'tests' . DS . 'TestCase' . DS . 'Controller' . DS . 'BakeProductsControllerTest.php');
    }

    /**
     * data provider for name variations
     *
     * @return array
     */
    public static function nameVariations(): array
    {
        return [
            ['BakeProducts'], ['bake_products'],
        ];
    }

    /**
     * test that both plural and singular forms work for controller baking.
     *
     * @return void
     */
    #[DataProvider('nameVariations')]
    public function testMainWithControllerNameVariations(string $name): void
    {
        $this->generatedFile = APP . 'Controller/BakeProductsController.php';
        $this->exec("bake mongocontroller {$name} --connection mongo --no-test");
        $this->assertExitCode(CommandInterface::CODE_SUCCESS);

        $this->assertFileExists($this->generatedFile);
        $this->assertFileContains('BakeProductsController extends AppController', $this->generatedFile);
    }
}
