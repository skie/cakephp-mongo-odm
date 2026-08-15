<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration\Config;

use Cake\TestSuite\TestCase;

/**
 * Class AbstractConfigTest
 */
abstract class AbstractConfigTestCase extends TestCase
{
    /**
     * @var string
     */
    protected $migrationPath;

    /**
     * @var string
     */
    protected $seedPath;

    /**
     * Returns a sample configuration array for use with the unit tests.
     *
     * @return array<string, mixed>
     */
    public function getConfigArray(): array
    {
        return [
            'paths' => [
                'migrations' => $this->getMigrationPath(),
                'seeds' => $this->getSeedPath(),
            ],
            'environment' => [
                'migration_table' => 'cake_migrations',
                'adapter' => 'mongo',
                'connection' => 'test_mongo',
            ],
        ];
    }

    public function getMigrationsConfigArray(): array
    {
        return [
            'paths' => [
                'migrations' => $this->getMigrationPath(),
                'seeds' => $this->getSeedPath(),
            ],
            'environment' => [
                'migration_table' => 'cake_migrations',
                'adapter' => 'mongo',
                'connection' => 'test_mongo',
            ],
        ];
    }

    /**
     * Generate dummy migration paths
     *
     * @return string
     */
    protected function getMigrationPath(): string
    {
        if ($this->migrationPath === null) {
            $this->migrationPath = uniqid('phinx', true);
        }

        return $this->migrationPath;
    }

    /**
     * Generate dummy seed paths
     *
     * @return string
     */
    protected function getSeedPath(): string
    {
        if ($this->seedPath === null) {
            $this->seedPath = uniqid('phinx', true);
        }

        return $this->seedPath;
    }
}
