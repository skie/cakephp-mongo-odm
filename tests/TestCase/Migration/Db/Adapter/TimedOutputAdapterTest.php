<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration\Db\Adapter;

use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleInput;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Migration\Db\Adapter\TimedOutputAdapter;
use Crustum\Mongo\Test\TestCase\Migration\Stub\FakeAdapter;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the TimedOutputAdapter wrapper.
 */
#[CoversClass(TimedOutputAdapter::class)]
class TimedOutputAdapterTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Test\TestCase\Migration\Stub\FakeAdapter
     */
    protected FakeAdapter $fake;

    /**
     * @var \Cake\Console\TestSuite\StubConsoleOutput
     */
    protected StubConsoleOutput $out;

    /**
     * @var \Crustum\Mongo\Migration\Db\Adapter\TimedOutputAdapter
     */
    protected TimedOutputAdapter $adapter;

    /**
     * Set up before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeAdapter();
        $this->out = new StubConsoleOutput();
        $this->out->setOutputAs(StubConsoleOutput::PLAIN);
        $this->adapter = new TimedOutputAdapter($this->fake);
    }

    /**
     * Builds a verbose ConsoleIo.
     *
     * @return \Cake\Console\ConsoleIo
     */
    protected function buildVerboseIo(): ConsoleIo
    {
        $io = new ConsoleIo($this->out, $this->out, new StubConsoleInput([]));
        $io->level(ConsoleIo::VERBOSE);

        return $io;
    }

    /**
     * Test DDL commands are written to the verbose output and delegated.
     *
     * @return void
     */
    public function testCreateCollectionIsLoggedAndDelegated(): void
    {
        $this->adapter->setIo($this->buildVerboseIo());

        $this->adapter->createCollection('mig_articles');

        $output = implode("\n", $this->out->messages());
        $this->assertStringContainsString(' -- createCollection(\'mig_articles\')', $output);

        $this->assertArrayHasKey('mig_articles', $this->fake->collections);
    }

    /**
     * Test createIndex returns the index name from the wrapped adapter.
     *
     * @return void
     */
    public function testCreateIndexDelegatesAndReturnsName(): void
    {
        $this->adapter->setIo($this->buildVerboseIo());

        $name = $this->adapter->createIndex('mig_articles', ['author_id' => 1]);

        $this->assertSame('author_id_1', $name);
        $this->assertArrayHasKey('author_id_1', $this->fake->collections['mig_articles']['indexes']);
    }

    /**
     * Test no output is written below the verbose level.
     *
     * @return void
     */
    public function testNoOutputBelowVerbose(): void
    {
        $this->adapter->setIo(new ConsoleIo($this->out, $this->out, new StubConsoleInput([])));

        $this->adapter->createCollection('mig_articles');

        $this->assertSame([], $this->out->messages());
        $this->assertArrayHasKey('mig_articles', $this->fake->collections);
    }

    /**
     * Test journal and transaction calls pass straight through.
     *
     * @return void
     */
    public function testNonDdlMethodsPassThrough(): void
    {
        $this->fake->versions = [20260811000000];

        $this->assertSame([20260811000000], $this->adapter->getVersions());
        $this->assertFalse($this->adapter->hasTransactions());

        $this->adapter->beginTransaction();
        $this->adapter->commitTransaction();

        $methods = array_column($this->fake->calls, 0);
        $this->assertContains('beginTransaction', $methods);
    }
}
