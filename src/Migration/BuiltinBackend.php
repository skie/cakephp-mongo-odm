<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleInput;
use Cake\Console\TestSuite\StubConsoleOutput;
use Crustum\Mongo\Migration\Config\ConfigInterface;
use DateTime;
use InvalidArgumentException;

/**
 * The Migrations class is responsible for handling migration operations
 * within a non-shell application.
 *
 * @internal
 */
class BuiltinBackend implements BackendInterface
{
    /**
     * Manager instance.
     *
     * @var \Crustum\Mongo\Migration\Manager|null
     */
    protected ?Manager $manager = null;

    /**
     * Default options to use.
     *
     * @var array<string, mixed>
     */
    protected array $default = [];

    /**
     * Constructor.
     *
     * @param array<string, mixed> $default Default options to use when calling a method
     */
    public function __construct(array $default = [])
    {
        if ($default !== []) {
            $this->default = $default;
        }
    }

    /**
     * @inheritDoc
     */
    public function status(array $options = []): array
    {
        $manager = $this->getManager($options);

        return $manager->printStatus($options['format'] ?? null);
    }

    /**
     * @inheritDoc
     */
    public function migrate(array $options = []): bool
    {
        $manager = $this->getManager($options);

        if (!empty($options['date'])) {
            $date = new DateTime($options['date']);
            $manager->migrateToDateTime($date);

            return true;
        }

        $manager->migrate($options['target'] ?? null);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function rollback(array $options = []): bool
    {
        $manager = $this->getManager($options);

        if (!empty($options['date'])) {
            $date = new DateTime($options['date']);
            $manager->rollbackToDateTime($date);

            return true;
        }

        $manager->rollback($options['target'] ?? null);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function markMigrated(int|string|null $version = null, array $options = []): bool
    {
        if (
            isset($options['target']) &&
            isset($options['exclude']) &&
            isset($options['only'])
        ) {
            throw new InvalidArgumentException('You should use `exclude` OR `only` (not both) along with a `target` argument');
        }

        $args = new Arguments([(string)$version], $options, ['version']);

        $manager = $this->getManager($options);
        $config = $manager->getConfig();
        $path = $config->getMigrationPath();

        $versions = $manager->getVersionsToMark($args);
        $manager->markVersionsAsMigrated($path, $versions);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function seed(array $options = []): bool
    {
        $options['source'] ??= ConfigInterface::DEFAULT_SEED_FOLDER;
        $seed = $options['seed'] ?? null;
        $force = $options['force'] ?? false;

        $manager = $this->getManager($options);
        $manager->seed(is_string($seed) ? $seed : null, (bool)$force);

        return true;
    }

    /**
     * Returns an instance of Manager.
     *
     * @param array<string, mixed> $options The options for manager creation
     * @return \Crustum\Mongo\Migration\Manager
     */
    public function getManager(array $options): Manager
    {
        $options += $this->default;

        $factory = new ManagerFactory([
            'plugin' => $options['plugin'] ?? null,
            'source' => $options['source'] ?? ConfigInterface::DEFAULT_MIGRATION_FOLDER,
            'connection' => $options['connection'] ?? 'mongo',
        ]);
        $io = new ConsoleIo(
            new StubConsoleOutput(),
            new StubConsoleOutput(),
            new StubConsoleInput([]),
        );

        return $factory->createManager($io);
    }
}
