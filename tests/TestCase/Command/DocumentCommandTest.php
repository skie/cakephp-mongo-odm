<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Command;

use Cake\Console\CommandInterface;
use Crustum\Mongo\Command\Bake\DocumentCommand;
use ReflectionClass;

/**
 * DocumentCommandTest class
 */
class DocumentCommandTest extends TestCase
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
     * Test baking a document class from the live schema.
     *
     * @return void
     */
    public function testBakeDocument(): void
    {
        $this->generatedFile = APP . 'Model/Document/BakeArticle.php';
        $this->exec('bake document BakeArticle --connection mongo --collection articles');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFileExists($this->generatedFile);
        $result = file_get_contents($this->generatedFile);

        $this->assertStringContainsString('namespace TestApp\Model\Document;', $result);
        $this->assertStringContainsString('class BakeArticle extends Document', $result);
        $this->assertStringContainsString("#[DocumentAttribute(collection: 'articles')]", $result);
        $this->assertStringContainsString("#[Field(name: 'author_id', type: CollectionSchemaInterface::TYPE_OBJECTID, nullable: true)]", $result);
        $this->assertStringContainsString("#[Field(name: 'title', type: CollectionSchemaInterface::TYPE_STRING, nullable: true)]", $result);
        $this->assertStringContainsString('protected array $_accessible = [', $result);
    }

    /**
     * Test baking a document with nullable fields.
     *
     * @return void
     */
    public function testBakeDocumentNullable(): void
    {
        $this->generatedFile = APP . 'Model/Document/BakePost.php';
        $this->exec('bake document BakePost --connection mongo --collection posts');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFileExists($this->generatedFile);
        $result = file_get_contents($this->generatedFile);

        // author_id is objectId and nullable per schema_mongo (multi-type bsonType)
        $this->assertStringContainsString("#[Field(name: 'author_id', type: CollectionSchemaInterface::TYPE_OBJECTID, nullable: true)]", $result);
    }

    /**
     * Test that baking with a missing collection still writes the class.
     *
     * @return void
     */
    public function testBakeDocumentNoSchema(): void
    {
        $this->generatedFile = APP . 'Model/Document/BakeEmpty.php';
        $this->exec('bake document BakeEmpty --connection mongo --collection does_not_exist');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFileExists($this->generatedFile);
        $result = file_get_contents($this->generatedFile);

        $this->assertStringContainsString('class BakeEmpty extends Document', $result);
    }

    /**
     * Test baking a document from a schema dump lock file.
     *
     * @return void
     */
    public function testBakeDocumentFromLockFile(): void
    {
        $this->generatedFile = APP . 'Model/Document/BakeLock.php';
        $this->exec('bake document BakeLock --connection mongo --collection articles --schema-file ' . ROOT . DS . 'tests' . DS . 'comparisons' . DS . 'Command' . DS . 'schema-dump.lock');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFileExists($this->generatedFile);
        $result = file_get_contents($this->generatedFile);

        $this->assertStringContainsString('class BakeLock extends Document', $result);
        $this->assertStringContainsString("#[Field(name: 'title', type: CollectionSchemaInterface::TYPE_STRING)]", $result);
    }

    /**
     * Test that the property schema maps each field to column metadata.
     *
     * @return void
     */
    public function testPropertySchema(): void
    {
        $command = new DocumentCommand();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('propertySchema');

        $fields = [
            ['name' => '_id', 'type' => 'objectid', 'nullable' => false],
            ['name' => 'title', 'type' => 'string', 'nullable' => true],
        ];
        $result = $method->invoke($command, $fields);
        $this->assertSame('column', $result['_id']['kind']);
        $this->assertSame('objectid', $result['_id']['type']);
        $this->assertFalse($result['_id']['null']);
        $this->assertTrue($result['title']['null']);
    }

    /**
     * Test the default hidden fields.
     *
     * @return void
     */
    public function testHiddenFields(): void
    {
        $command = new DocumentCommand();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('hiddenFields');

        $fields = [['name' => 'title', 'type' => 'string', 'nullable' => false]];
        $result = $method->invoke($command, $fields);
        $this->assertSame(['password', 'token'], $result);
    }

    /**
     * Test that the command aborts without a name.
     *
     * @return void
     */
    public function testExecuteNoName(): void
    {
        $this->exec('bake document');
        $this->assertExitCode(CommandInterface::CODE_ERROR);
    }
}
