# Crustum Mongo ODM — Changes & Future Plans

Commit: `a9ef460` (branch `feature/select-query-test`) — BaseCollectionTest adaptation, 154/274 passing.
Previous: `92ac4de` — document terminology; `287ea3b` — formatters/matching/autoFields/distinct/exceptions.

## BaseCollectionTest Port Progress (commits 6287d50 → a9ef460)

Status: **154 / 274 tests passing** (started at 75; errors dropped 94 → 20).

### BaseCollection / query API fixes made while adapting
- `getDisplayField()`: cake60 string-column fallback (non-null string cols excluding pass/token/secret).
- Constructor accepts array `schema` via `setSchemaFromArray()`.
- `updateAll`/`deleteAll`/`exists` accept `ExpressionInterface`; delegate to mockable `updateQuery()`/`deleteQuery()` (cake-compatible).
- `DeleteQuery`/`UpdateQuery`/`InsertQuery` constructors accept `BaseCollection` first (cake style) or `(connection, collection, repository)`.
- `subquery()`: bare select query.
- `Association::setJoinType()`/`getJoinType()` (cake compat; no real Mongo joins).
- `Connection` constructor accepts a `DriverInterface` instance.
- `dynamicFinder`: maps `id` field → `_id` in generated conditions.
- `PersistenceFailedException`/`RolledbackTransactionException`: fixed `messageTemplate` → `$_messageTemplate` (CakeException uses underscore property; messages were empty).

### Test adaptation strategies
- `tools/fix-test-ids.php`: int PK → hex `_id`; `'id' => '<hex>'` → `'_id' => '<hex>'` for ODM result/input arrays.
- `tools/port-test.php`: `EntityClassAssertion` → `DocumentClassAssertion`; exception imports; method renames.
- `->id` property access → `->getId()` (decision: NO id→_id automapping in ODM).
- Magic finder tests → ODM filter-array `where` (`$or`, `_id`, empty order).
- subquery tests → Mongo semantics (explicit IN / count by FK) instead of SQL join/alias asserts.
- displayField expectations → `_id` where schema has no display column.
- `skipIfSqlServer()` no-op (no SQL driver checks in Mongo).

### Remaining work (~120 tests)
- `->id` int assertions (auto-increment, `nextUserId`) — SQL-specific, need manual rewrite.
- `loadInto` returns null (LazyEagerLoader injection with multi-assoc contain).
- belongsToMany `_joinData` not populated on loaded tags.
- Atomic save mock tests (Cake Connection → Mongo Connection transactions).
- Cache tests (`default` cache config missing), `getMaxAliasLength`, `SectionsMembers` registry conflicts.
- Delete/unlink primary-key extraction edge cases.

## Document Terminology for Marshalling API (commit 92ac4de)

### BaseCollection method renames (canonical = Document)
- `newEntity` → `newDocument`, `newEntities` → `newDocuments`, `newEmptyEntity` → `newEmptyDocument`, `patchEntity` → `patchDocument`, `patchEntities` → `patchDocuments`.
- The old Entity-named methods remain as **thin wrappers** because `Cake\Datasource\RepositoryInterface` requires exactly those names. End users working through the interface still call `newEntity()`/`patchEntity()`; ODM users use `newDocument()` etc.
- Wrapper test added: `testInterfaceWrappersDelegateToDocumentMethods` verifies each wrapper delegates to its Document counterpart (assertInstanceOf Document, data round-trip).

### Bug fixes discovered while porting
- `BaseCollection::processFindOrCreate()` returned the undefined `$entity` (PHP fatal / 8 failures) → now returns `$document`.
- `RulesAwareTrait::buildRules()` typehinted `Cake\Datasource\RulesChecker`; now typehints `Crustum\Mongo\ODM\RulesChecker` (mirrors cake60 `Cake\ORM\RulesChecker`). Fixed fatal in anonymous-class overrides; `RulesCheckerIntegrationTest` now 69/69.

### Interfaces kept
- `RepositoryInterface`, `EventListenerInterface`, `EventDispatcherInterface`, `ValidatorAwareInterface` all implemented (verified via Reflection). `RepositoryInterface` forces the Entity-named marshalling methods — hence the wrappers.

### port-test.php permanent mapping
- Added: exception imports (`Cake\ORM\Exception\Missing*` → ODM), `MissingEntityException`→`MissingDocumentException`, method renames `test*Entity*`→`test*Document*`, `->newEntity(`→`->newDocument(` etc., `$entity`/`$entities` vars → `$document`/`$documents`.

### Ported test
- `cake60/tests/TestCase/ORM/TableTest.php` (232 KB) → `tests/TestCase/ODM/BaseCollectionTest.php` (273 tests). Initial pass: 75 passed / 104 failed / 94 errors — most failures are `_id` migration and ODM-specific adaptation (not yet addressed).


## Completed Changes

### 1. ODM-layer exceptions (remove Cake\ORM dependency)
- Ported `Cake\ORM\Exception\PersistenceFailedException` → `Crustum\Mongo\ODM\Exception\PersistenceFailedException` (terms changed to "Document", `@see cake60`).
- Ported `Cake\ORM\Exception\RolledbackTransactionException` → `Crustum\Mongo\ODM\Exception\RolledbackTransactionException`.
- `src/ODM/BaseCollection.php` now imports/throws the ODM exceptions; PHPDoc `@throws` updated.
- Files: `src/ODM/Exception/PersistenceFailedException.php`, `src/ODM/Exception/RolledbackTransactionException.php`, `src/ODM/BaseCollection.php`.

### 2. QueryBuilder formatters on deep/select-loaded associations
- Root cause: `EagerLoader::applyQueryBuilder()` compiled the contain-callback and only extracted `filter`/`fields`/`sort`, then `unset()` the builder — `formatResults()` formatters were lost.
- Fix: keep `queryBuilder` in the loadable config; apply it in `SelectLoader::buildEagerLoader()` right before `$query->all()` (mirrors cake60 `SelectLoader::_buildQuery`).
- Result: `testFormatDeepDistantAssociationRecords` now passes; `mapReduce` still passes (5 tests).
- Files: `src/ODM/EagerLoader.php`, `src/ODM/Association/Loader/SelectLoader.php`.

### 3. autoFields API on ODM SelectQuery
- Added `enableAutoFields(bool $value = true)`, `disableAutoFields()`, `isAutoFieldsEnabled(): ?bool`.
- Added `protected ?bool $autoFields` and `addDefaultFields()` (invoked in `execute()`), which re-expands a limited projection with repository schema columns so computed `select()` fields don't hide document fields.
- Tests now passing: `testAutoFields`, `testAutoFieldsCount`, `testContainAutoFields`.
- File: `src/ODM/Query/SelectQuery.php`.

### 4. matching / join API
- Added `EagerLoader::setMatching(string $path, ?callable $builder, array $options)` and `getMatching()`, plus `containOptions` entries `negateMatch` and `joinType`.
- Rewired `SelectQuery::matching()` onto `setMatching()`; added `notMatching()`, `innerJoinWith()`, `leftJoinWith()`.
- `EagerLoader::dispatch()` now forces matching associations into pipeline (not external select) so they actually filter the root query.
- `HasMany::buildPipeline()` gained matching (`$unwind` preserve=false) and `negateMatch` (preserve=true + `$match property = null`) handling; `prefixMatchConditions()` promoted into `Association`.
- Result: `testNotMatching` filters correctly (authors without articles).
- Files: `src/ODM/EagerLoader.php`, `src/ODM/Query/SelectQuery.php`, `src/ODM/Association.php`, `src/ODM/Association/HasMany.php`.

### 5. distinct() query modifier
- `Database\Query\QueryCompiler`: new `$distinct` state, `distinct()`, `getDistinct()`, `buildDistinctStage()` (`$group` + `$replaceRoot`), wired into `compile()`/`compileAggregate()`/`reset()`; distinct stage placed AFTER the pipeline stages so it deduplicates filtered results.
- `Database\Query\SelectQuery::distinct(array|string|bool $on, bool $overwrite): static` — query modifier (SQL-DISTINCT semantics). The old Mongo `distinct(string): array` command moved to `distinctValues()`.
- Database tests updated to `distinctValues()`; all 3 Database distinct tests pass.
- Verified: 3 docs → 2 unique on `author_id`.
- Files: `src/Database/Query/QueryCompiler.php`, `src/Database/Query/SelectQuery.php`, `tests/TestCase/Database/Query/SelectQueryTest.php`.

### 6. BelongsToMany matching guard
- `applyFieldsProjection()` now bails on `$fields === false` (previously `array_fill_keys([false], 1)` produced `['' => 1]` → Mongo "FieldPath field names may not be empty strings").
- File: `src/ODM/Association/BelongsToMany.php`.

### 7. structarmed (architecture) config
- Restored explicit `ODM` ruleset row; enabled `Database`/`Datasource` rows.
- `skipClassViolation(ResultSetFactory, Cake\ORM\DtoMapper)` — narrow exception for the still-allowed DTO dependency instead of opening the whole `CakeORM` layer (leaks stay visible).
- File: `structarmed.php`. Run: `vendor\bin\structarmed analyze src --clear-cache` → 0 violations.

### Test migration
- `testAutoFields`: `assertArrayHasKey('id')` → `assertArrayHasKey('_id')` (matches `_id` semantics already used by `testFirstDirtyQuery`).
- File: `tests/TestCase/ODM/Query/SelectQueryTest.php`.

---

## Verification status
- structarmed: 0 violations (189 files).
- `php -l` clean on all touched files.
- Passing: `testFormatDeepDistantAssociationRecords`, `mapReduce` (5), `testAutoFields`, `testAutoFieldsCount`, `testContainAutoFields`, Database distinct (3).
- `testNotMatching` now filters correctly (still asserts `id` instead of `_id`).

---

## Known Issues / Remaining Work

### A. Deep matching/notMatching nested lookup bug (pre-existing)
- `testNotMatchingDeep` (`notMatching('articles.tags')` + `distinct`) executes now, but nested lookup in the pipeline uses the root `_id` as `localField` instead of the parent association key (`articles._id`). Results come back empty.
- Affects any deep (2+ level) matching pipeline with BelongsToMany/HasMany combination.
- Fix direction: nested `buildPipeline` must receive/prefix the parent property path when constructing `$lookup.localField`.

### B. `_id` vs `id` test migration (matching group)
- `testNotMatching`, `testNotMatchingNested`, `testNotMatchingDeep`, `testMatching*`, `testInnerJoinWith*`, `testNotSoFarMatchingWithContain*`, `testAutoFieldsWithAssociations` assert SQL-style `id` / `_matchingData` shapes. ODM returns `_id` (no `id` alias, per `Document.php`). Need migration like `testFirstDirtyQuery`.
- `testMatchingConflictingAliases` expects `AssertionError` (cake60) — ODM semantics differ.

### C. contain + hydrate(false)
- `testAutoFieldsWithAssociations`, `testAutoFieldsWithContainQueryBuilder`, `testContainSelectedFields`, `testContainAssociationWithEmptyConditions` fail on `contain()` with `hydrate(false)` / SelectLoader closure arity ("Too few arguments … 1 passed … exactly 2 expected").
- Fix direction: `SelectLoader` `$options['conditions']` closure invoked with wrong arity; hydration of nested arrays when hydration is off.

### D. distinct on deep associations (follow-up)
- distinct() mechanism itself works (verified at Database layer). To make `testNotMatchingDeep`'s expected `[1,2,4]` pass, block A must be fixed first, then the test migrated to `_id`.

### E. Remaining cake60 EagerLoader API gaps (out of scope so far)
- `enableAutoFields` on EagerLoader itself (currently only on SelectQuery).
- `attachableAssociations()` / `externalAssociations()` split (crustum uses `getExternalAssociations()`).
- `cleanCopy()` on SelectQuery (referenced by `testCleanCopy`).

---

## Hygiene notes
- Repo contains untracked dev artifacts NOT committed: `phpcca.phar`, `phpcca.yaml`, `qdebug9.php`, `qdebug10.php`, `res/`, `structarmed.zip`. Consider adding to `.gitignore` or removing.
- Standalone debug scripts live in `C:\Users\yevge\AppData\Local\Temp\opencode\q_*.php` (outside repo).
- `tools/` is gitignored — `port-test.php` is a local dev tool, not tracked.
- `testInterfaceWrappersDelegateToDocumentMethods` is manually added to `BaseCollectionTest.php` (not in cake60 source); re-running `port-test.php` will overwrite it. Re-apply manually after a re-port.

---

## Mongo Connection Configuration

Register the datasource via `ConnectionManager` (e.g. `config/app.php` `Datasources`).

### Full example
```php
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Driver\MongoDriver;

// config/app.php -> 'Datasources' => [...]
'mongo' => [
    'className' => Connection::class,
    'driver' => MongoDriver::class,
    'host' => '127.0.0.1',
    'port' => 27017,
    'database' => 'my_app',
    'username' => 'app_user',   // optional auth
    'password' => 'secret',
    'options' => [               // MongoDB\Client constructor options (optional)
        'authSource' => 'admin',
        'replicaSet' => 'rs0',
        'ssl' => true,
    ],
    'log' => false,              // optional: true, false, or PSR-3 logger name
],
```

### Minimal
```php
'mongo' => [
    'className' => Connection::class,
    'driver' => MongoDriver::class,
    'database' => 'my_app',
],
```
`host`/`port` default to `localhost:27017`.

### Connection string alternative
Instead of `host`/`port`/`username`/`password`, set `'url'`:
```php
'mongo' => [
    'className' => Connection::class,
    'driver' => MongoDriver::class,
    'url' => 'mongodb://user:pass@127.0.0.1:27017/my_app?authSource=admin',
],
```

### Using it
```php
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\FactoryLocator;

$locator = FactoryLocator::get('Collection');   // Crustum locator
$articles = $locator->get('Articles');          // default connection from alias

// or explicit
ConnectionManager::alias('mongo', 'default');
$users = $locator->get('Users');
```

