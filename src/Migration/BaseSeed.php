<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration;

use Cake\Console\ConsoleIo;
use Crustum\Mongo\Migration\Db\Adapter\AdapterInterface;
use Crustum\Mongo\Migration\Config\ConfigInterface;
use Crustum\Mongo\Migration\Migration\Manager;
use Crustum\Mongo\Migration\Migration\ManagerFactory;
use MongoDB\Collection;
use RuntimeException;

/**
 * Base seed implementation.
 *
 * Provides base functionality for seeds to extend. Data operations go through
 * the Mongo collection API instead of SQL statements.
 */
class BaseSeed implements SeedInterface
{
    /**
     * The adapter instance.
     *
     * @var \Crustum\Mongo\Migration\Db\Adapter\AdapterInterface|null
     */
    protected ?AdapterInterface $adapter = null;

    /**
     * The ConsoleIo instance.
     *
     * @var \Cake\Console\ConsoleIo|null
     */
    protected ?ConsoleIo $io = null;

    /**
     * The config instance.
     *
     * @var \Crustum\Mongo\Migration\Config\ConfigInterface|null
     */
    protected ?ConfigInterface $config = null;

    /**
     * @inheritDoc
     */
    public function run(): void
    {
    }

    /**
     * @inheritDoc
     */
    public function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function setAdapter(AdapterInterface $adapter): static
    {
        $this->adapter = $adapter;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getAdapter(): AdapterInterface
    {
        if (!$this->adapter instanceof AdapterInterface) {
            throw new RuntimeException('Adapter not set.');
        }

        return $this->adapter;
    }

    /**
     * @inheritDoc
     */
    public function setIo(ConsoleIo $io): static
    {
        $this->io = $io;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getIo(): ?ConsoleIo
    {
        return $this->io;
    }

    /**
     * @inheritDoc
     */
    public function getConfig(): ?ConfigInterface
    {
        return $this->config;
    }

    /**
     * @inheritDoc
     */
    public function setConfig(ConfigInterface $config): static
    {
        $this->config = $config;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        $name = static::class;
        if (str_starts_with($name, 'Crustum\Mongo\BaseSeed@anonymous') && preg_match('#[/\\\\](\w+)\.php:#', $name, $matches)) {
            return $matches[1];
        }

        return $name;
    }

    /**
     * @inheritDoc
     */
    public function collection(string $collectionName): Collection
    {
        return $this->getAdapter()->getCollection($collectionName);
    }

    /**
     * @inheritDoc
     */
    public function insert(string $collectionName, array $data): void
    {
        $this->collection($collectionName)->insertOne($data);
    }

    /**
     * @inheritDoc
     */
    public function insertOrSkip(string $collectionName, array $data, array $filter = []): void
    {
        if ($filter !== [] && $this->collection($collectionName)->findOne($filter) !== null) {
            return;
        }

        $this->insert($collectionName, $data);
    }

    /**
     * @inheritDoc
     */
    public function insertOrUpdate(string $collectionName, array $data, array $filter = []): void
    {
        $this->collection($collectionName)->updateOne(
            $filter,
            ['$set' => $data],
            ['upsert' => true],
        );
    }

    /**
     * @inheritDoc
     */
    public function hasCollection(string $collectionName): bool
    {
        return $this->getAdapter()->hasCollection($collectionName);
    }

    /**
     * @inheritDoc
     */
    public function shouldExecute(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function isIdempotent(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function call(string $seeder, array $options = []): void
    {
        $io = $this->getIo();
        if (!$io instanceof ConsoleIo) {
            throw new RuntimeException('ConsoleIo is required for calling other seeders.');
        }

        $io->out('');
        $io->out(
            ' ====' .
            ' <info>' . $seeder . ':</info>' .
            ' <comment>seeding</comment>',
        );

        $start = microtime(true);
        $this->runCall($seeder, $options);
        $end = microtime(true);

        $io->out(
            ' ====' .
            ' <info>' . $seeder . ':</info>' .
            ' <comment>seeded' .
            ' ' . sprintf('%.4fs', $end - $start) . '</comment>',
        );
        $io->out('');
    }

    /**
     * Calls another seeder from this seeder.
     *
     * @param string $seeder Name of the seeder to call from the current seed
     * @param array<string, mixed> $options The CLI options passed to the manager factory
     * @return void
     */
    protected function runCall(string $seeder, array $options = []): void
    {
        $factory = new ManagerFactory([
            'connection' => $options['connection'] ?? $this->adapterConnection(),
            'plugin' => $options['plugin'] ?? null,
            'source' => $options['source'] ?? null,
        ]);
        $io = $this->getIo();
        if (!$io instanceof ConsoleIo) {
            throw new RuntimeException('ConsoleIo is required for calling other seeders.');
        }

        $manager = $factory->createManager($io);
        $manager->seed($seeder);
    }

    /**
     * Returns the connection name of the current adapter.
     *
     * @return string
     */
    protected function adapterConnection(): string
    {
        return $this->getAdapter()->getConnection()->configName();
    }
}
