<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration\Migration;

use Cake\Console\ConsoleIo;
use Cake\Core\Plugin;
use Cake\Datasource\ConnectionManager;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Migration\Db\Adapter\AdapterInterface;
use Crustum\Mongo\Migration\Db\Adapter\CakeMongoAdapter;
use Crustum\Mongo\Migration\Config\Config;
use Crustum\Mongo\Migration\Config\ConfigInterface;
use RuntimeException;

/**
 * Factory for Config and Manager.
 *
 * Used by console commands.
 *
 * @internal
 */
class ManagerFactory
{
    /**
     * Constructor.
     *
     * ## Options
     *
     * - source - The directory in config that migrations and seeds should be read from.
     * - plugin - The plugin name that migrations are being run on.
     * - connection - The connection name.
     * - dry-run - Whether dry-run mode should be enabled.
     *
     * @param array<string, mixed> $options The command line options for creating config/manager
     */
    public function __construct(protected array $options)
    {
    }

    /**
     * Read configuration options used for this factory.
     *
     * @param string $name The option name to read
     * @return mixed Option value or null
     */
    public function getOption(string $name): mixed
    {
        return $this->options[$name] ?? null;
    }

    /**
     * Create a ConfigInterface instance based on the factory options.
     *
     * @return \Crustum\Mongo\Migration\Config\ConfigInterface
     */
    public function createConfig(): ConfigInterface
    {
        $folder = (string)$this->getOption('source');

        $dir = ROOT . DS . 'config' . DS . $folder;
        if (defined('CONFIG')) {
            $dir = CONFIG . $folder;
        }

        $plugin = (string)$this->getOption('plugin') ?: null;
        if ($plugin) {
            $dir = Plugin::path($plugin) . 'config' . DS . $folder;
        }

        $connectionName = (string)$this->getOption('connection') ?: 'mongo';

        $configData = [
            'paths' => [
                'migrations' => $dir,
                'seeds' => $dir,
            ],
            'environment' => [
                'adapter' => 'mongo',
                'connection' => $connectionName,
                'migration_table' => CakeMongoAdapter::MIGRATION_TABLE,
                'plugin' => $plugin,
                'dryrun' => $this->getOption('dry-run'),
            ],
            'plugin' => $plugin,
            'source' => $folder,
        ];

        return new Config($configData);
    }

    /**
     * Get the migration manager for the current CLI options and application configuration.
     *
     * @param \Cake\Console\ConsoleIo $io The command io
     * @param \Crustum\Mongo\Migration\Config\ConfigInterface|null $config A config instance. Providing null will create a new Config
     * @return \Crustum\Mongo\Migration\Migration\Manager
     */
    public function createManager(ConsoleIo $io, ?ConfigInterface $config = null): Manager
    {
        $config ??= $this->createConfig();

        return new Manager($config, $io);
    }

    /**
     * Builds the migration adapter from the environment options.
     *
     * @param array<string, mixed> $options Environment options
     * @return \Crustum\Mongo\Migration\Db\Adapter\AdapterInterface
     */
    public function createAdapter(array $options): AdapterInterface
    {
        $connectionName = $options['connection'] ?? null;
        if (!is_string($connectionName) || $connectionName === '') {
            throw new RuntimeException('No connection defined');
        }

        $connection = ConnectionManager::get($connectionName);
        if (!$connection instanceof Connection) {
            throw new RuntimeException(sprintf(
                'Connection `%s` is not a %s instance.',
                $connectionName,
                Connection::class,
            ));
        }

        return new CakeMongoAdapter($connection, $options['plugin'] ?? null);
    }
}
