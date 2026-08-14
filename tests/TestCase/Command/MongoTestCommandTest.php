<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Command;

use Cake\Console\CommandInterface;
use Crustum\Mongo\Test\TestCase\Command\TestCase;

/**
 * MongoTestCommandTest class
 */
class MongoTestCommandTest extends TestCase
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
     * Test baking a test case skeleton for a document class.
     *
     * @return void
     */
    public function testBakeDocumentTest(): void
    {
        $this->generatedFile = TESTS . 'TestCase' . DS . 'Model' . DS . 'Document' . DS . 'BakeArticleTest.php';
        $this->exec('bake mongotest Document BakeArticle');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFileExists($this->generatedFile);

        $result = file_get_contents($this->generatedFile);
        $this->assertStringContainsString('class BakeArticleTest extends TestCase', $result);
    }

    /**
     * Test that getRealClassName resolves a collection class name.
     *
     * @return void
     */
    public function testGetRealClassName(): void
    {
        $command = new \Crustum\Mongo\Command\Bake\MongoTestCommand();
        $result = $command->getRealClassName('Collection', 'Articles');
        $this->assertSame('TestApp\Model\Collection\ArticlesCollection', $result);
    }

    /**
     * Test that mapType throws for an invalid type.
     *
     * @return void
     */
    public function testMapTypeInvalid(): void
    {
        $command = new \Crustum\Mongo\Command\Bake\MongoTestCommand();

        $this->expectException(\Cake\Core\Exception\CakeException::class);
        $command->mapType('NotAType');
    }
}
