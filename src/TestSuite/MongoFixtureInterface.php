<?php
declare(strict_types=1);

namespace Crustum\Mongo\TestSuite;

/**
 * Marker interface for fixtures backed by a Mongo collection.
 *
 * Used by `MongoTestTrait` to detect Mongo fixtures in a test's fixture list,
 * so the fixture strategy can fall back to `TruncateStrategy` (Mongo has no
 * SQL savepoints, so `TransactionStrategy` is not applicable).
 *
 * Any fixture class may implement this interface — it does not require
 * extending `Crustum\Mongo\TestSuite\TestFixture`.
 */
interface MongoFixtureInterface
{
}
