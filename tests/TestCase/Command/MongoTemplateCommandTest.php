<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Command;

use Cake\Console\Arguments;
use Cake\Console\CommandInterface;
use Cake\Console\ConsoleIo;
use Cake\Console\Exception\StopException;
use Cake\Console\TestSuite\StubConsoleInput;
use Cake\Console\TestSuite\StubConsoleOutput;
use Crustum\Mongo\Command\Bake\MongoTemplateCommand;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MongoTemplateCommandTest class
 *
 * Port of `Bake\Test\TestCase\Command\TemplateCommandTest` for the Mongo ODM.
 */
class MongoTemplateCommandTest extends TestCase
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
     * Test the controller() method.
     *
     * The reference test app ships controller classes, so `controllerClass`
     * resolves via `App::className`. Our test app has no controllers, so only
     * the name resolution (always deterministic) is asserted.
     *
     * @return void
     */
    public function testController(): void
    {
        $command = new MongoTemplateCommand();
        $args = new Arguments([], [], []);
        $command->controller($args, 'Comments');
        $this->assertSame('Comments', $command->controllerName);
    }

    /**
     * Test controller method with prefixes.
     *
     * @return void
     */
    public function testControllerPrefix(): void
    {
        $command = new MongoTemplateCommand();

        $args = new Arguments([], ['prefix' => 'Admin'], []);
        $command->controller($args, 'Posts');
        $this->assertSame('Posts', $command->controllerName);
    }

    /**
     * Test controller method with nested prefixes.
     *
     * @return void
     */
    public function testControllerPrefixNested(): void
    {
        $command = new MongoTemplateCommand();
        $args = new Arguments([], ['prefix' => 'Admin/Management'], []);

        $command->controller($args, 'Posts');
        $this->assertSame('Posts', $command->controllerName);
    }

    /**
     * Test controller with a non-conventional controller name.
     *
     * @return void
     */
    public function testControllerWithOverride(): void
    {
        $command = new MongoTemplateCommand();
        $args = new Arguments([], [], []);

        $command->controller($args, 'Comments', 'Posts');
        $this->assertSame('Posts', $command->controllerName);
    }

    /**
     * Test the model() method.
     *
     * @return void
     */
    public function testModel(): void
    {
        $command = new MongoTemplateCommand();
        $command->model('Articles');
        $this->assertSame('Articles', $command->modelName);

        $command->model('NotThere');
        $this->assertSame('NotThere', $command->modelName);
    }

    /**
     * Test getTemplatePath().
     *
     * @return void
     */
    public function testGetTemplatePath(): void
    {
        $command = new MongoTemplateCommand();
        $command->controllerName = 'Posts';

        $args = new Arguments([], [], []);

        $result = $command->getTemplatePath($args);
        $this->assertPathEquals(APP . 'templates' . DS . 'Posts' . DS, $result);

        $args = new Arguments([], ['prefix' => 'admin'], []);
        $result = $command->getTemplatePath($args);
        $this->assertPathEquals(APP . 'templates' . DS . 'Admin' . DS . 'Posts' . DS, $result);

        $args = new Arguments([], ['prefix' => 'admin/management'], []);
        $result = $command->getTemplatePath($args);
        $this->assertPathEquals(APP . 'templates' . DS . 'Admin' . DS . 'Management' . DS . 'Posts' . DS, $result);
    }

    /**
     * Test getContent with no primary key.
     *
     * @return void
     */
    public function testGetContentWithNoPrimaryKey(): void
    {
        $this->expectException(StopException::class);

        $command = new MongoTemplateCommand();
        $command->controllerName = 'Posts';

        $args = new Arguments([], [], []);
        $io = new ConsoleIo(new StubConsoleOutput(), new StubConsoleOutput(), new StubConsoleInput([]));
        $vars = [
            'modelClass' => 'TestModel',
            'primaryKey' => [],
            'fields' => ['id', 'name'],
            'hidden' => [],
            'associations' => [],
            'keyFields' => [],
            'namespace' => 'TestApp',
        ];
        $command->getContent($args, $io, 'view', $vars);
    }

    /**
     * Test baking a single view template for a collection.
     *
     * @return void
     */
    public function testBakeSingleTemplate(): void
    {
        $this->generatedFile = APP . 'templates' . DS . 'Products' . DS . 'index.php';
        $this->exec('bake mongotemplate Products index --connection mongo');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFileExists($this->generatedFile);

        $result = file_get_contents($this->generatedFile);
        $this->assertStringContainsString('Products', $result);
        $this->assertStringContainsString('$products', $result);
        $this->assertStringContainsString('paginator', strtolower($result));
        $this->assertStringContainsString('$product->getId()', $result);
        $this->assertStringNotContainsString('$product->_id', $result);
    }

    /**
     * Test generating template content without writing a file.
     *
     * @return void
     */
    public function testGetContent(): void
    {
        $command = new MongoTemplateCommand();
        $io = new ConsoleIo(
            new StubConsoleOutput(),
            new StubConsoleOutput(),
            new StubConsoleInput([]),
        );
        $args = new Arguments([], ['connection' => 'mongo'], ['name', 'template']);

        $command->controller($args, 'Products');
        $command->model('Products');

        $content = $command->getContent($args, $io, 'index');

        $this->assertIsString($content);
        $this->assertStringContainsString('index', $content);
    }

    /**
     * test baking an edit file.
     *
     * @return void
     */
    public function testBakeEdit(): void
    {
        $this->generatedFile = APP . 'templates' . DS . 'Products' . DS . 'edit.php';
        $this->exec('bake mongotemplate Products edit --connection mongo');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFileExists($this->generatedFile);
        $this->assertFileContains('form content', $this->generatedFile);
        $this->assertFileContains('$product->getId()', $this->generatedFile);
        $this->assertFileNotContains('$product->_id', $this->generatedFile);
    }

    /**
     * test baking an index with an index-columns limit.
     *
     * @return void
     */
    public function testBakeIndexWithIndexLimit(): void
    {
        $this->generatedFile = APP . 'templates' . DS . 'Products' . DS . 'index.php';
        $this->exec('bake mongotemplate Products --index-columns 3 index --connection mongo');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFileExists($this->generatedFile);
    }

    /**
     * Test execute no args.
     *
     * @return void
     */
    public function testMainNoArgs(): void
    {
        $this->exec('bake mongotemplate --connection mongo');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertOutputContains('Possible collections to bake view templates for based on your current database:');
        $this->assertOutputContains('- Products');
    }

    /**
     * static dataprovider for test cases
     *
     * @return void
     */
    public static function nameVariations(): array
    {
        return [['Products'], ['products']];
    }

    /**
     * test that the name can be inflected.
     *
     * @return void
     */
    #[DataProvider('nameVariations')]
    public function testMainWithNameVariations(string $name): void
    {
        $this->generatedFile = APP . 'templates' . DS . 'Products' . DS . 'view.php';
        $this->exec("bake mongotemplate {$name} view --connection mongo");

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFileExists($this->generatedFile);
        $this->assertFileContains('$product->getId()', $this->generatedFile);
        $this->assertFileNotContains('$product->_id', $this->generatedFile);
    }
}
