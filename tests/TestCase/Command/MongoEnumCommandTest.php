<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Command;

use Cake\Console\CommandInterface;
use Crustum\Mongo\Test\TestCase\Command\TestCase;

/**
 * MongoEnumCommandTest class
 */
class MongoEnumCommandTest extends TestCase
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
     * test baking an enum
     *
     * @return void
     */
    public function testBakeEnum(): void
    {
        $this->generatedFile = APP . 'Model/Enum/FooBar.php';
        $this->exec('bake mongo_enum FooBar');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFileExists($this->generatedFile);
        $result = file_get_contents($this->generatedFile);
        $this->assertStringContainsString('enum FooBar: string implements EnumLabelInterface', $result);
        $this->assertStringContainsString('use Crustum\\Mongo\\ODM\\Enum\\EnumLabelTrait;', $result);
    }

    /**
     * test baking an enum with int return type
     *
     * @return void
     */
    public function testBakeEnumBackedInt(): void
    {
        $this->generatedFile = APP . 'Model/Enum/FooBar.php';
        $this->exec('bake mongo_enum FooBar -i');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFileExists($this->generatedFile);
        $result = file_get_contents($this->generatedFile);
        $this->assertStringContainsString('enum FooBar: int implements EnumLabelInterface', $result);
    }

    /**
     * test baking an enum with string return type and cases
     *
     * @return void
     */
    public function testBakeEnumBackedWithCases(): void
    {
        $this->generatedFile = APP . 'Model/Enum/FooBar.php';
        $this->exec('bake mongo_enum FooBar foo,bar:b,bar_baz');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFileExists($this->generatedFile);
        $result = file_get_contents($this->generatedFile);
        $this->assertStringContainsString("case Foo = 'foo';", $result);
        $this->assertStringContainsString("case Bar = 'b';", $result);
        $this->assertStringContainsString("case BarBaz = 'bar_baz';", $result);
    }

    /**
     * test baking an enum with int return type and cases
     *
     * @return void
     */
    public function testBakeEnumBackedIntWithCases(): void
    {
        $this->generatedFile = APP . 'Model/Enum/FooBar.php';
        $this->exec('bake mongo_enum FooBar foo,bar,bar_baz:9 -i');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFileExists($this->generatedFile);
        $result = file_get_contents($this->generatedFile);
        $this->assertStringContainsString('case Foo = 0;', $result);
        $this->assertStringContainsString('case Bar = 1;', $result);
        $this->assertStringContainsString('case BarBaz = 9;', $result);
    }
}
