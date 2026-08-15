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
- **`#[Embedded]` attribute (doc 36)** — `ODM\Attribute\Embedded` marks a
  Document as embedded inside a parent (`many`/`key`/`localKey`), with
  `Embedded::read()` reflection. `Collection::embedOne()/embedMany()` now derive
  `localKey` from the attribute automatically.
- **Migrations layer alignment (doc 36, P1–P6)** — reference structure parity:
  unified-ledger journal (`plugin` column + `(version, plugin)` unique index,
  analog of `cake_migrations`), engine namespace realignment
  (`Migration\Migration\`, `Util\Util\`), adapter under `Db/Adapter`,
  `Db\Plan`+`Db\Action` engine with conflict resolution, seed execution log
  `_seeds` (analog of `cake_seeds`), `seed_status`/`seed_reset`/`upgrade`
  commands, `bake mongo_migration_simple`, and the adapter wrapper hierarchy
  (`WrapperInterface`/`AdapterWrapper`/`TimedOutputAdapter`,
  `RecordingAdapter extends AdapterWrapper`).
- **Embedded bake (doc 36)** — `SchemaFields` detects nested validator shapes
  (`array` + `items.properties` → embedMany, `object` + `properties` →
  embedOne); `bake document`/`bake mongo_model` emit `#[Embedded]` on the parent
  and generate the embedded Document class; `bake collection`/`bake mongo_model`
  emit `embedOne()`/`embedMany(['documentClass' => ...])`. Plain arrays/hashes
  stay `TYPE_COLLECTION`/`TYPE_HASH`.
- **Migration `addField()` nested options (doc 36 §5)** — `Db\Collection::addField()`
  carries `items`/`properties`/`enum` into the validator field definition via
  `Db\Plan\Plan::validatorFor()`, so migrations can declare embedded shapes
  without hand-writing `setValidator()`.

### Tests
- Ported the reference `migrations` tests into `tests/TestCase/Migration/`
  (**191 tests**, doc 33): engine core (Config/Util/ColumnParser/Manager/
  Environment/facade), Mongo adapter journal + seed log + plugin isolation,
  `Db\Plan` conflict resolution, `Db\Collection` round-trips, SchemaDiff/Dumper,
  seed status/reset/upgrade commands, and `MigratorTest` against a dedicated
  `test_migrator` database. All collections are `mig_*`-namespaced and cleaned
  symmetrically so the shared `test_mongo_db` is never polluted (doc 37).
- Ported the reference `bake` + `migrations` bake command tests into
  `tests/TestCase/Command/` (enum 4, document 7, mongo_model 28, association
  detection 6, fixture 4, controller 11, template 13, test 7, collection 4,
  bake mongo_migration 6, bake mongo_seed 5, bake snapshot 2, bake diff 3,
  embedded bake 9 = **111 tests**) with a dedicated `TestApp\BakeTestApplication`
  console harness.
  Bake output only ever lands in the throwaway test app
  (`tests/test_app/TestApp/`) with fresh unique names + tearDown cleanup; the
  fixture/test commands (whose paths resolve to the real plugin `tests/` dirs)
  are covered by pure method tests so the real suite is never written into
  (doc 35). The migration *engine* is not exercised. Run with
  `php vendor/bin/phpunit tests/TestCase/Command`.
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
- **Contain finders + association finder wiring (doc 40, G8)** —
  `HasMany::eagerLoader()` honors the containment `finder` option (custom
  finders like `published`/`slugged` apply to the target query); `SelectLoader`
  preserves non-integer row keys when injecting one-to-many results, so
  finder `indexBy` keys (slug) survive eager loading. `testContainFinderHasMany*`
  and `testCustomFinderInBelongsTo` green; `updateAll` test condition rewritten
  (`['1 = 1']` → `[]`).
- **QueryCompiler merge + contain closure queryBuilder (doc 40, G4)** —
  `where()`/`having()` merge conditions per-field instead of
  `array_merge_recursive` (which coerced `ObjectId` values to
  `['oid' => [...]]` and broke filter equality); `parse()` type documented.
  Contain queryBuilder closures now work (a closure's `where` isn't applied
  twice), so `testContainWithClosure`/`testContainClosureSignature` green and
  association `formatResults` closures still apply.
- **Aliased select fields cast + computed projection (doc 40, G4)** —
  `SelectQuery::select()` registers schema types for aliased fields
  (`select(['updated_time' => 'updated'])` → `updated_time` casts as `date`);
  `ResultSet::convertRowWith()` falls back to the query type map (and
  `bsonToArray` handles `UTCDateTime`); `QueryCompiler::compile()` switches to
  an aggregation `$project` when the projection contains computed (`$field`)
  values (find can't mix include + computed); `applySelectClause()` keeps
  computed aliases. `testSelectTypeInferSimpleAliases` green.
- **SelectQuery implements JsonSerializable (doc 40, G4)** —
  `SelectQuery` now implements `JsonSerializable` (cake60 ORM parity) and
  `jsonSerialize()` returns `all()`, so `json_encode($query)` yields the
  results instead of `{}`. `testJsonSerialize` green.
- **BelongsToMany matching `_matchingData` (doc 40, G3)** —
  `ResultSet::groupResult()` (hydrated) and `applyMatchingData()` (unhydrated)
  add `_matchingData.<JunctionAlias>` for BelongsToMany matching, selecting the
  junction row whose target FK matches the matched tag (and dropping `_id`);
  `_join_*` is removed. `testFilteringByBelongsToManyNoHydration` green.
- **BelongsToMany contain conditions (doc 40, G2/G3)** —
  `BelongsToMany::buildPipeline()` filters the joined target array element-wise
  (`$filter` on `$$item.<field>`) for non-matching containment `conditions`
  (e.g. `Tags._id => ...`), mirroring the junction filter; hex `_id` condition
  values cast to `ObjectId`. `testBelongsToManyEagerLoadingNoHydration` both
  strategies green.
- **Matching `_matchingData` + BSON date cast (doc 40, G3)** —
  `ResultSet::groupResult()` and the new `applyMatchingData()` (unhydrated) key
  `_matchingData` by the association **alias** (`Comments`) instead of the
  property (`comments`), so matching rows nest like cake. `bsonToArray()`
  converts `UTCDateTime` → `Cake\I18n\DateTime` (no BSON objects leak into
  unhydrated output). `tests/schema_mongo.php` now declares `created`/`updated`
  as `date` for `comments`, `tags`, `categories`, `test_plugin_comments`,
  `auth_users`, `attachments` (fixtures store them as strings; `TestFixture`
  casts them to BSON dates), so matching/contain rows hydrate datetimes.
  `testFilteringByHasMany*` rewritten to hex `user_id`/`_id`.
- **SelectQuery sql/selectAlso/convertRow (doc 40, G4/G1)** —
  `SelectQuery::sql()` fires `Collection.beforeFind` once (cake60 ORM parity);
  `selectAlso()` enables auto-fields so the remaining schema columns are
  selected; `ResultSet::convertRowWith()` stringifies untyped `ObjectId` values
  (e.g. `selectAlso(['extra' => '_id'])` returns hex). `testSelectAlso`/
  `testUpdate`/`testInsert`/`testDelete` rewritten to the ODM shapes (update/
  delete return affected counts, insert returns inserted `_id` list — no SQL
  `StatementInterface`).
- **SelectQuery applyOptions/count/setResult/hydrate (doc 40, G4/G1)** —
  `applyOptions()` now handles `group`/`groupBy`/`having` and skips `null`
  values (cake60 parity); `clause('having')` added; `setResult()` keeps a
  pre-built `ResultSet` (no double-wrap); `hydrate()`/`enableHydration()` mark
  the query dirty so switching modes re-executes; `count()` with `group`/`having`
  clears the projection before appending `$count` (the `$project` stage would
  otherwise drop the count document). `testCount` rewritten onto `NumberTrees`
  (`depth` numeric) instead of `id >` on ObjectId; `testCountWithGroup` drops
  `sum('id')` (ObjectId sum). Tree fixtures (`NumberTreesFixture`,
  `MenuLinkTreesFixture`) now store `lft`/`rght` as ints and `schema_mongo.php`
  declares them `int`; `TranslatesFixture.foreign_key` now references hex `_id`
  (schema `objectId`).
- **Order expressions (doc 40, G4)** — added `Database\Expression\OrderByExpression`
  and `OrderClauseExpression` (Mongo `$sort`-shaped `MongoExpressionInterface`
  analogs of the cake classes); `QueryCompiler::orderBy()` unwraps them via
  `getConditions()`, `Query::orderByAsc()/orderByDesc()` accept expression
  fields. `clause('order')` returns the `$sort` map.
- **No-hydration eager loading (doc 40, G2)** — unhydrated `contain()` now nests
  associations into plain arrays: `SelectQuery::decorate()` runs
  `EagerLoader::loadExternal()` for both hydrated and unhydrated results;
  `ResultSet::convertRow()` recursively converts BSON values
  (`BSONDocument`/`BSONArray`→array, `ObjectId`→hex) so no driver objects leak
  into unhydrated output; `ResultSet::deconstructBelongsToMany()` resolves
  `_joinData` from lookup `_join_*` fields. `SelectQuery::dirty()` resets the
  cached `$results` (cake60 parity) so re-`select()`/`contain()` re-executes;
  `EagerLoader::contain()`/`clearContain()` detach previously attached
  `$lookup` stages instead of accumulating duplicate pipelines. Nested
  associations are dispatched to the select-loader (post-load injection via
  `SelectLoader::collectSourcePaths()`/`setByPath()`), BelongsToMany always
  builds its lookup pipeline, and `HasMany` containment `conditions`/`sort`/
  `fields`/`limit`/`skip` are applied inside the `$lookup.pipeline`. Field
  resolution now maps `Alias.id` → `_id` and strips the repository alias for
  pipeline `$match`/`$sort`/`$project` (`Association::resolvePipelineField`).
  Green: `ContainResultFetchingOneLevel`, `HasManyEagerLoading*`
  (NoHydration/FieldsAndOrder/Deep/FromSecondaryTable), `BelongsToManyEagerLoadingNoHydration`
  nesting; test rewrites `posts.id`→`posts._id`.
- **ODM copy-artifact rename (entity/table → document/collection)** — `tools/rename-odm-copy-artifacts.php`
  renames ODM-local variables and event payload keys that were copied verbatim
  from cake60: `$entity`→`$document`, `$table`→`$collection`, `->entity`→`->document`,
  and event payload key `'entity'`→`'document'` (`Collection.beforeSave`/
  `afterSave`/`beforeDelete`/`afterDelete`/`afterMarshal`/`beforeRules`/
  `afterRules`/`afterSaveCommit`/`afterDeleteCommit`). Applied across
  `src/ODM` and ODM tests. Deliberately untouched: `View\Form\DocumentContext`
  (`'entity'` is the FormHelper/ContextFactory contract), `src/Orm` bridge
  (real SQL ORM `Table`/`Entity`), `EntityTrait` method prefixes
  (`entityHas`/`entityGet`/…), and class/interface names (`EntityInterface`,
  `Cake\ORM\Table`). `PersistenceFailedException` property/getter renamed
  `$entity`/`getEntity()` → `$document`/`getDocument()` (no releases, no BC).
- **`BaseCollection::_processSave()`** now routes parent/child association saves
  through `AssociationCollection::saveParents()/saveChildren()` (cake60 parity)
  instead of its own loops: nested `associated` options (`'authors.supervisors'`
  dot-notation, contain-style arrays) are normalized via `normalizeKeys()` and
  propagated to the nested `save()`. `Collection.afterSave` fires once per saved
  document from `onSaveSuccess()` (was inside the old `saveChildren()`). Removed
  the now-dead `saveParents()`/`saveChildren()`/`normalizeAssociated()`/
  `isAssociated()` collection-local helpers.
- **Array-typed fields** — `Database\Query\QueryCompiler::castValue()` and
  `ODM\Query\CommonQueryTrait::convertValueToDatabase()` no longer wrap
  scalar/list values when the schema type is `array`: a field declared
  `bsonType: array` now persists `[1,2]` as `[1,2]` (was `[[1],[2]]` — each
  element re-wrapped by `ArrayType::toDatabase()`) and `where(['field' => 1])`
  compiles to `{field: 1}` instead of `{field: [1]}`. This unblocked
  BelongsToMany array-pivot `$pull`/load against schematized collections.
- **`bake mongo_enum`**: int enum cases without an explicit value now
  auto-increment (`foo,bar,bar_baz:9 -i` → `Foo=0, Bar=1, BarBaz=9`), matching
  `Bake\Utility\Model\EnumParser`.
- **`bake mongo_model` / `bake collection` / `bake mongofixture`**: field types
  are now canonicalized (`int`→`integer`, `bool`→`boolean`, `objectId`→`objectid`,
  `array`→`collection`, …) before `#[Field]` constant mapping, validation rules,
  and fixture sample values — matching `bake document` (was emitting raw `'int'`
  and skipping the `integer` rule).
- **`bake document` / `bake collection`**: `--connection` now defaults to
  `mongo` (was `default` from the bake common options), matching the ODM default
  `BaseCollection::defaultConnectionName()`.
- **`bake mongo_model`**: `getCollectionObject()` passes `collection` (was
  `table`, which `BaseCollection` ignores) so `--collection` is honored.
- **`bake mongo_migration`**: `collectionName()` inference fixed for
  `Remove*From*` names (`RemoveFieldsFromUsers` → `users`, was `s_from_users`)
  by matching the plural `Fields`/`Columns` alternation first.

### Fixed
- **`Association`**: `className` now defaults to the full alias (cake60 parity) so
  plugin-prefixed associations (`hasMany('TestPlugin.Comments')`) resolve the
  plugin collection class; `HasMany::options()` handles `sort` and `saveStrategy`.
- **`SelectQuery::count()`** honors `group`/`having`/`distinct`/in-pipeline loads  by routing through the `$count` aggregation pipeline; simple queries still use
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
- **`BaseCollection` reordered to mirror cake60 `Table.php`** — same method
  order (with ODM-only methods placed next to their cake analog and marked
  "ODM extension"), full docblocks adapted for Collection/Document/Mongo, and a
  class-level `@template TDocument` generic used in find/query signatures.
  `$queryFactory` property moved next to `$registryAlias`; `find()` is now a
  one-liner via `selectQuery()`.
- **`Document` id → `_id` read fallback** — `__get('id')`/`get('id')`/`has('id')`
  map to `_id` so cake-style `$doc->id` works after hydration/save; a plain
  `id` field still wins when present.
- **`LinkConstraint::countLinks()`** routes through the association `find()`
  (applies conditions/finder) for HasOne/HasMany/HasOne, and through `matching()`
  for BelongsToMany — F34 link-count tests unskipped (25/35 in LinkConstraintTest).
- **`QueryExpression::eq()/notEq()` accept `IdentifierExpression`** (field-to-field
  `$expr`) in addition to plain field names; `not(equalFields(...))` composes
  field-to-field negation.

### Tests
- Ported `Rule\ExistsInNullableTest` + `Rule\LinkConstraintTest` (cake60) via
  `tools/port-test.php`; composite-FK (`ExistsIn` `array_combine`) and SQL-only
  subquery tests marked skip (F32/F33/F34).
- **F34 dive** — `LinkConstraintTest` 21 → 26 passing: save-orphan ids now use
  the persisted document id (`get($orphan->getId())`), field-to-field conditions
  rewritten as `not(equalFields(...))`, association conditions/finder respected
  by `countLinks()`. Remaining 9 skip = F32 composite-FK (2), F33 subquery (2),
  F34 link-count residual (5).
- **F35 dive** — `CounterCacheBehaviorTest` 15 → 18 passing: nullable
  `counter_cache_*` FK schema (`bsonType ['objectId','null']`) fixes the null-FK
  save, `testUpdate`/`testBindingKey` unskipped; 1 residual skip = subquery.
- Ported `Behavior\CounterCacheBehaviorTest` (18 pass / 1 skip F35) and
  `Behavior\TimestampBehaviorTest` (19/19).
- Ported `Locator` suite (46/46): `CollectionLocatorTest` (expanded),
  `LocatorAwareTraitTest`, `CollectionContainerTest`.
- `DateTypeTest` aligned to `Cake\I18n\DateTime` hydration.
