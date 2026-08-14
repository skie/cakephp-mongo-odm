# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0]

Initial release of `crustum/mongo` (`Crustum\Mongo`).

## Unreleased

### Added
- `ODM\Association::attachTo()` — in-pipeline (lookup) association attach, the
  ODM analog of cake60 `Association::attachTo()`; registers the association on
  the query eager loader so `$lookup` stages build and results nest (doc 32).

### Tests
- Rewrote `BelongsToTest::testAttachTo*` to the ODM shape (assert the `$lookup`
  pipeline, not SQL `clause('join')`/`clause('select')`); added
  `testAttachToEndToEnd`. SQL-only cases (multi-column primary keys, target
  `beforeFind`) marked skip (F25).
- Aligned `BehaviorRegistryTest` event-listener counts with cake6 (the
  collection no longer subscribes to its own conventional hooks) and ported
  `CollectionImplementedEventsTest` (cake6 `TableImplementedEventsTest`).
- `tools/port-test.php`: now rewrites `TableEventsTrait` → `CollectionEventsTrait`,
  all `Model.*` event names, and drops same-namespace base-test imports.

### Fixed
- **`SelectQuery::count()`** honors `group`/`having`/`distinct`/in-pipeline loads
  by routing through the `$count` aggregation pipeline; simple queries still use
  `countDocuments(filter)` (total, mirroring cake6 `performCount`).
- **`ResultSet::count()`** counts buffered rows (cake `BufferedIterator`
  semantics) instead of delegating to the query's total count — so
  `count($query->all())` matches the limit, while pagination totals stay on
  `SelectQuery::count()`. F21 (`testFindEmptyConditions`) unskipped.
- `ResultSetFactoryTest::testQueryLoggingForSelectsWithZeroRows` aligned to
  cake6 (asserts the query ran as `find`, not the pre-fix `aggregate`).
- **F20 resolved** — `Association::updateAll`/`deleteAll` already route through
  the association `find()` (conditions + finder applied); `AssociationProxyTest`
  now 10/10 (was 5/10). The `updateAllFromAssociationFinder` test was SQL-only
  (`'?'` placeholder + `'1=1'`) — rewritten to Mongo values.

### Added
- **Connection read/write roles (doc 30)** — dual-driver `Connection`
  (`getReadDriver()`/`getWriteDriver()`/`getDriver($role)`, `createDrivers()`
  split for `['read' => …, 'write' => …]`), `MongoDriver::getRole()` +
  `readPreference: secondaryPreferred` for the read role (via
  `MongoDriver::getOptions()`), query `connectionRole`
  (`getConnectionRole()`/`setConnectionRole()`/`useReadRole()`/`useWriteRole()`),
  `Connection::run()` routes by role, and `SelectLoader` inherits the parent
  query role. Tests: `ConnectionTest` role suite +
  `BelongsToManyTest`/`HasManyTest::testEagerLoaderConnectionRole` rewritten
  for Mongo. `read`/`write` sub-configs follow cake60 precedence (role options
  replace shared options on the top level).
