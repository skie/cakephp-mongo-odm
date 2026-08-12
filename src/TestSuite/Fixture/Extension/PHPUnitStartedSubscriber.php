<?php
declare(strict_types=1);

namespace Crustum\Mongo\TestSuite\Fixture\Extension;

use PHPUnit\Event\TestSuite\Started;
use PHPUnit\Event\TestSuite\StartedSubscriber as PHPUnitStarted;

/**
 * Suite-start hook for Crustum Mongo ODM tests.
 *
 * Connection aliases (`test_mongo` → `mongo`) are provided by Cake's
 * `Cake\TestSuite\Fixture\Extension\PHPUnitExtension`; the Mongo test schema
 * is (re)built in `tests/bootstrap.php` via `SchemaGenerator`. This subscriber
 * is intentionally a no-op extension point so the Mongo extension can be
 * registered alongside Cake's without duplicating work.
 */
class PHPUnitStartedSubscriber implements PHPUnitStarted
{
    /**
     * Initializes before any tests are run.
     *
     * @param \PHPUnit\Event\TestSuite\Started $event The event.
     * @return void
     */
    public function notify(Started $event): void
    {
        // Intentionally empty — see class docblock.
    }
}
