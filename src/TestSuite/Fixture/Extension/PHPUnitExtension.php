<?php
declare(strict_types=1);

namespace Crustum\Mongo\TestSuite\Fixture\Extension;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * PHPUnit extension to integrate Crustum Mongo ODM fixtures.
 *
 * Mirrors `Cake\TestSuite\Fixture\Extension\PHPUnitExtension`: it registers a
 * suite-start subscriber that (re)builds the Mongo test schema from the file
 * pointed to by the `FIXTURE_SCHEMA_METADATA` env var (like Cake's Migrator /
 * SchemaLoader paths), so collections are always recreated before tests run.
 */
class PHPUnitExtension implements Extension
{
    /**
     * @param \PHPUnit\TextUI\Configuration\Configuration $configuration
     * @param \PHPUnit\Runner\Extension\Facade $facade
     * @param \PHPUnit\Runner\Extension\ParameterCollection $parameters
     * @return void
     */
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(
            new PHPUnitStartedSubscriber(),
        );
    }
}
