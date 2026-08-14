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
use Crustum\Mongo\Migration\Db\Collection;
use ReflectionClass;
use RuntimeException;

/**
 * Base migration implementation.
 *
 * Provides base functionality for migrations to extend. The SQL query builder
 * helpers of cakephp/migrations are replaced by Mongo-native operations:
 * collections, indexes, and validators, executed through the adapter.
 */
class BaseMigration implements MigrationInterface
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
     * Is migrating up property.
     *
     * @var bool
     */
    protected bool $isMigratingUp = true;

    /**
     * The version number.
     *
     * @var int
     */
    protected int $version = 0;

    /**
     * Constructor.
     *
     * @param int|null $version The version this migration is (null for anonymous migrations)
     */
    public function __construct(?int $version = null)
    {
        if ($version !== null) {
            $this->validateVersion($version);
            $this->version = $version;
        }
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
        return static::class;
    }

    /**
     * @inheritDoc
     */
    public function setVersion(int $version): static
    {
        $this->validateVersion($version);
        $this->version = $version;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * @inheritDoc
     */
    public function setMigratingUp(bool $isMigratingUp): static
    {
        $this->isMigratingUp = $isMigratingUp;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function isMigratingUp(): bool
    {
        return $this->isMigratingUp;
    }

    /**
     * @inheritDoc
     */
    public function useTransactions(): bool
    {
        return $this->getAdapter()->hasTransactions();
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
    public function collection(string $collectionName, array $options = []): Collection
    {
        return new Collection($collectionName, $options, $this->getAdapter());
    }

    /**
     * @inheritDoc
     */
    public function listCollections(): array
    {
        return $this->getAdapter()->listCollections();
    }

    /**
     * @inheritDoc
     */
    public function createCollection(string $collectionName, array $options = []): void
    {
        $this->getAdapter()->createCollection($collectionName, $options);
    }

    /**
     * @inheritDoc
     */
    public function dropCollection(string $collectionName): void
    {
        $this->getAdapter()->dropCollection($collectionName);
    }

    /**
     * @inheritDoc
     */
    public function renameCollection(string $from, string $to, bool $dropTarget = false): void
    {
        $this->getAdapter()->renameCollection($from, $to, $dropTarget);
    }

    /**
     * @inheritDoc
     */
    public function index(string $collectionName, array|string $key, array $options = []): string
    {
        return $this->getAdapter()->createIndex($collectionName, $key, $options);
    }

    /**
     * @inheritDoc
     */
    public function uniqueIndex(string $collectionName, array|string $key, array $options = []): string
    {
        return $this->getAdapter()->createIndex($collectionName, $key, ['unique' => true] + $options);
    }

    /**
     * @inheritDoc
     */
    public function dropIndex(string $collectionName, string $indexName): void
    {
        $this->getAdapter()->dropIndex($collectionName, $indexName);
    }

    /**
     * @inheritDoc
     */
    public function setValidator(
        string $collectionName,
        ?array $validator,
        ?string $validationLevel = null,
        ?string $validationAction = null,
    ): void {
        $this->getAdapter()->setValidator($collectionName, $validator, $validationLevel, $validationAction);
    }

    /**
     * @inheritDoc
     */
    public function preFlightCheck(): void
    {
        $reflection = new ReflectionClass($this);
        if (
            $reflection->hasMethod(self::CHANGE)
            && ($reflection->hasMethod(self::UP) || $reflection->hasMethod(self::DOWN))
        ) {
            $io = $this->getIo();
            if ($io instanceof ConsoleIo) {
                $io->out(
                    '<comment>warning</comment> Migration contains both change() and up()/down() methods.' .
                    ' <warning>Ignoring up() and down()</warning>.',
                );
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function postFlightCheck(): void
    {
    }

    /**
     * @inheritDoc
     */
    public function shouldExecute(): bool
    {
        return true;
    }

    /**
     * Makes sure the version int is within range for a valid datetime.
     * This is required to have a meaningful order in the overview.
     *
     * @param int $version Version
     * @return void
     */
    protected function validateVersion(int $version): void
    {
        $length = strlen((string)$version);
        if ($length === 14) {
            return;
        }

        throw new RuntimeException('Invalid version `' . $version . '`, should be in format `YYYYMMDDHHMMSS` (length of 14).');
    }
}
