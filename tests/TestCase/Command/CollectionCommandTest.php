<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Command;

use Cake\Console\CommandInterface;
use Crustum\Mongo\Test\TestCase\Command\TestCase;

/**
 * CollectionCommandTest class
 */
class CollectionCommandTest extends TestCase
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
     * Test baking a collection class from an existing test-app collection.
     *
     * The test-app ships `TestApp\Model\Collection\CommentsCollection`, so the
     * locator resolves a concrete class and the context reflects its schema.
     *
     * @return void
     */
    public function testBakeCollection(): void
    {
        $this->generatedFile = APP . 'Model/Collection/BakeArticlesCollection.php';
        $this->exec('bake collection BakeArticles --connection mongo');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFileExists($this->generatedFile);

        $result = file_get_contents($this->generatedFile);
        $this->assertStringContainsString('class BakeArticlesCollection extends BaseCollection', $result);
        $this->assertStringContainsString('public function initialize(array $config): void', $result);
    }
}
