<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Command;

use Cake\Console\CommandInterface;
use Crustum\Mongo\Test\TestCase\Command\TestCase;

/**
 * MongoTemplateCommandTest class
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
    }

    /**
     * Test generating template content without writing a file.
     *
     * @return void
     */
    public function testGetContent(): void
    {
        $command = new \Crustum\Mongo\Command\Bake\MongoTemplateCommand();
        $io = new \Cake\Console\ConsoleIo(
            new \Cake\Console\TestSuite\StubConsoleOutput(),
            new \Cake\Console\TestSuite\StubConsoleOutput(),
            new \Cake\Console\TestSuite\StubConsoleInput([]),
        );
        $args = new \Cake\Console\Arguments([], ['connection' => 'mongo'], ['name', 'template']);

        $command->controller($args, 'Products');
        $command->model('Products');
        $content = $command->getContent($args, $io, 'index');

        $this->assertIsString($content);
        $this->assertStringContainsString('index', $content);
    }
}
