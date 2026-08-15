<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Command;

use Cake\Console\Arguments;
use Cake\Console\CommandInterface;
use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleInput;
use Cake\Console\TestSuite\StubConsoleOutput;
use Crustum\Mongo\Command\Bake\MongoModelCommand;
use Crustum\Mongo\ODM\BaseCollection;

/**
 * MongoModelCommand association detection test.
 *
 * Port of `Bake\Test\TestCase\Command\ModelCommandAssociationDetectionTest`
 * for the Mongo ODM, exercised against the live `test_mongo` schema.
 */
class MongoModelCommandAssociationDetectionTest extends TestCase
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
     * Compare bake collection result with expected associations.
     *
     * @param string $name Model name.
     * @param array<string> $expectedContains Substrings that must be present.
     * @param array<string> $expectedNotContains Substrings that must be absent.
     * @param string|null $collection Collection name override (avoids test-app class collisions).
     * @return void
     */
    protected function _compareBakeCollectionResult(string $name, array $expectedContains, array $expectedNotContains = [], ?string $collection = null): void
    {
        $this->generatedFiles = [
            APP . "Model/Collection/{$name}Collection.php",
        ];
        $collectionArg = $collection !== null ? " --collection {$collection}" : '';
        $this->exec("bake mongo_model {$name} --no-document --no-test --no-fixture --connection mongo{$collectionArg}");

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFilesExist($this->generatedFiles);

        $contents = file_get_contents($this->generatedFiles[0]);
        foreach ($expectedContains as $needle) {
            $this->assertStringContainsString($needle, $contents);
        }

        foreach ($expectedNotContains as $needle) {
            $this->assertStringNotContainsString($needle, $contents);
        }
    }

    /**
     * test checking if associations where built correctly for comments.
     *
     * `comments` has `article_id`/`user_id` objectId fields pointing at the
     * `articles` and `users` collections; `attachments` points back via
     * `comment_id` (hasMany).
     *
     * @return void
     */
    public function testBakeAssociationDetectionCommentsTable(): void
    {
        $this->_compareBakeCollectionResult('Comments', [
            "belongsTo('Article', [",
            "'className' => 'Articles',",
            "'foreignKey' => 'article_id'",
            "belongsTo('User', [",
            "'className' => 'Users',",
            "'foreignKey' => 'user_id'",
            "hasMany('Attachments', [",
            "'foreignKey' => 'comment_id'",
        ]);
    }

    /**
     * test checking if associations where built correctly for articles.
     *
     * `articles` has `author_id` (belongsTo Authors) and HABTM join
     * collections (`articles_tags`, `number_trees_articles`). The model name
     * is aliased (`BakeArticles`) so the generated file never collides with
     * the existing test-app `ArticlesCollection`.
     *
     * @return void
     */
    public function testBakeAssociationDetectionArticlesTable(): void
    {
        $this->_compareBakeCollectionResult('BakeArticles', [
            "belongsTo('Author', [",
            "'className' => 'Authors',",
            "'foreignKey' => 'author_id'",
        ], [], 'articles');
    }

    /**
     * test checking if associations where built correctly for products.
     *
     * `products` has no objectId foreign keys pointing at other collections;
     * `orders.product_id` points back (hasMany).
     *
     * @return void
     */
    public function testBakeAssociationDetectionProductsTable(): void
    {
        $this->_compareBakeCollectionResult('Products', [
            "hasMany('Orders', [",
            "'foreignKey' => 'product_id'",
        ]);
    }

    /**
     * test checking if associations where built correctly for sections
     * (HABTM via `sections_members`).
     *
     * @return void
     */
    public function testBakeAssociationDetectionSectionsTable(): void
    {
        $this->_compareBakeCollectionResult('Sections', [
            "belongsToMany('Members', [",
            "'joinCollection' => 'sections_members'",
        ]);
    }

    /**
     * Test that the association detection methods agree on the direction of
     * a HABTM relation (`articles` <-> `articles_tags`).
     *
     * @return void
     */
    public function testIsPossibleBelongsToManyRelation(): void
    {
        $command = new MongoModelCommand();
        $command->connection = 'mongo';

        $this->assertTrue($command->isPossibleBelongsToManyRelation('articles', 'articles_tags'));
        // The check is directional: only the source collection prefix/suffix is probed.
        $this->assertFalse($command->isPossibleBelongsToManyRelation('articles_tags', 'articles'));
        $this->assertFalse($command->isPossibleBelongsToManyRelation('articles', 'comments'));
    }

    /**
     * Test that getAssociations honours --no-associations.
     *
     * @return void
     */
    public function testGetAssociationsNoFlag(): void
    {
        $command = new MongoModelCommand();
        $command->connection = 'mongo';

        $arguments = new Arguments([], ['no-associations' => true], []);
        $io = new ConsoleIo(new StubConsoleOutput(), new StubConsoleOutput(), new StubConsoleInput([]));
        $collection = new BaseCollection(['alias' => 'Comments', 'collection' => 'comments']);
        $this->assertEquals([], $command->getAssociations($collection, 'comments', $arguments, $io));
    }
}
