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
- **EagerLoader matching separated from containments (cake6 structure)** —
  `setMatching()` stores matching in its own loader; `normalized()` returns only
  containments; `attachAssociations()`/`associationsMap()`/`attachableAssociations()`
  include matching; `clearContain()` keeps matching joins. Matching property
  paths are `_matchingData.<alias>` (cake6 parity). Ported
  `EagerLoaderTest` (cake60) to `tests/TestCase/ODM/EagerLoaderTest.php`;
  SQL-only cases (join/select-alias/auto-fields) marked skip (F25).

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

### Added
- **`CollectionLocator` expanded to cake60 `TableLocator` parity** — `locations`
  + `addLocation()` (custom `Model/Collection` paths), `setConfig()`/`getConfig()`
  with "already constructed" guard, `setFallbackClassName()`/`allowFallbackClass()`,
  `genericInstances()`, shared `QueryFactory`, and `createInstance()` wiring
  (config merge, connection/connectionName, fallback collection-name
  derivation, `AssociationCollection`/`queryFactory` injection).
- **`MongoTestTrait::getMockForCollection()`** — Mockery partial mock of a
  collection registered in the locator (ODM analog of Cake `mockModel`).
- **`CounterCacheBehavior` ported to cake60 level** — `beforeSave` ignoreDirty
  tracking, `afterSave`/`afterDelete` recalc, `processAssociation` with
  original-value conditions, closure `($event, $entity, $collection, $isOriginal)`
  support, `updateCounterCache()` batch recalc, `getCount()` with finder +
  conditions. `updateAll`/`deleteAll` from an association now apply its
  configured conditions/finder (F20).
- **`TimestampBehavior` returns `Cake\I18n\DateTime`** (was `UTCDateTime`) and
  `DateType`/`DateImmutableType` hydration returns `Cake\I18n\DateTime` /
  `DateTimeImmutable` (was native `DateTime`) — cake60 parity for datetime
  fields; `tests/bootstrap.php` sets `date_default_timezone_set('UTC')`.
- **`BaseCollection::getAssociation()` throws on missing** (cake60
  `InvalidArgumentException`) instead of returning null; added `findAssociation()`
  helper; `Marshaller`/`CounterCacheBehavior` use `hasAssociation()` guards.

### Tests
- Ported `Rule\ExistsInNullableTest` + `Rule\LinkConstraintTest` (cake60) via
  `tools/port-test.php`; composite-FK (`ExistsIn` `array_combine`) and SQL-only
  subquery tests marked skip (F32/F33/F34).
- Ported `Behavior\CounterCacheBehaviorTest` (15 pass / 4 skip F35) and
  `Behavior\TimestampBehaviorTest` (19/19).
- Ported `Locator` suite (46/46): `CollectionLocatorTest` (expanded),
  `LocatorAwareTraitTest`, `CollectionContainerTest`.
- `DateTypeTest` aligned to `Cake\I18n\DateTime` hydration.
