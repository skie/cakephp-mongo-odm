# ODM ⇄ Database Layer Boundary: Analysis, Decisions & Proposals

A structural comparison of how cake60 routes ORM work (conditions, sort, group,
associations, eager loading, result shaping) into the SQL compiler, versus how
the Crustum ODM currently flattens the same concerns into Mongo. It states what
decisions cost us and records the direction to close the gaps. Sections marked
**DECIDED** are binding; the rest are proposals awaiting approval.

**Status: P1–P7 implemented 2026-08-07** (see §5). Cross-checked against the dev
reference docs (docs/reference/00-index.md rule 4: read the Cake class, then
re-implement). Where a proposal contradicts a **frozen contract** or a
**recorded decision**, the doc is the authority and the proposal is revised
accordingly.

Baseline for this document: full suite `1466 tests / 1262 passed / 62 failed /
39 errors / 103 skipped`.

---

## 1. How cake core does it

### 1.1 Query parts are expression *trees*, compiled late

`Cake\Database\Query` stores every clause as a part tree, not a flattened array:

- `where()` / `orderBy()` / `having()` funnel through
  `Query::_conjugate()` (vendor `Database/Query.php:1732`), which wraps inputs
  in a `QueryExpression` and appends child expressions.
- `orderBy()` builds an `OrderByExpression` (a `QueryExpression` subclass)
  whose children are `OrderClauseExpression(field, direction)` objects
  (vendor `Database/Expression/OrderByExpression.php`, `OrderClauseExpression.php`).
- The compiler walks parts lazily: `QueryCompiler::compile()` →
  `traverseParts()` (vendor `QueryCompiler.php:99`) → for the `order` part it
  simply calls `OrderByExpression::sql($binder)` (vendor `QueryCompiler.php:138`,
  `OrderByExpression.php:47`).

Key property: **field names like `Articles.id` are never rewritten by the ORM.**
SQL supports table aliases natively, so the alias stays until SQL generation,
where the dialect quotes it. There is no field-normalization pass anywhere in
the ORM→DB boundary for where/order/group/having.

`traverse()` (vendor `Database/Query.php:306`, `Query.php:1674`) exists to walk
expression trees when a *transformation* is needed — the only real user is
`Driver::_removeAliasesFromConditions()` (vendor `Driver.php:674`) which strips
table aliases from WHERE for `UPDATE`/`DELETE`. It is not used for sort.

### 1.2 Result shaping lives in the factory, not the ResultSet

- `Cake\ORM\ResultSet` is an **empty `Collection` wrapper** (vendor
  `ORM/ResultSet.php:36`) — it contains zero logic.
- All row shaping is `ORM/ResultSetFactory.php`:
  - `collectData($query)` reads `$query->clause('select')` and splits each key on
    the `Alias__field` double-underscore convention to know which columns belong
    to which association (vendor `ResultSetFactory.php:77-124`).
  - `groupResult($row, $data)` nests columns under the primary alias, hydrates
    matching/contained associations, and builds the entity (vendor
    `ResultSetFactory.php:135+`).
- The select clause is the single source of truth for what ends up in a row.

### 1.3 Eager loading: keys are collected before loading

`EagerLoader::loadExternal()` (vendor `ORM/EagerLoader.php:614`):

1. `_collectKeys($external, $query, $results)` gathers the source foreign-key /
   binding-key values from already-fetched rows.
2. It then calls `$instance->eagerLoader($config + ['keys' => $keys, ...])`.
3. `ORM\Association\Loader\SelectLoader` receives those keys and runs **one**
   batched query (`foreignKey IN keys`), ordering by the association sort.

The loader is a pure function of `(config, keys)` — it never re-inspects the
source result set.

---

## 2. What the ODM decided instead

### 2.1 The compiler holds flattened arrays

`src/Database/Query/QueryCompiler.php` stores primitive arrays
(`filter`, `projection`, `sort`, `group`) and `compile()` emits either a
`find()` (`filter` + `options.sort`) or an `aggregate()` pipeline. `where` /
`andWhere` / `having` translate `MongoExpressionInterface` via
`->getConditions()` and flatten nested `QueryExpression` through
`QueryBuilder::parse()` (QueryCompiler.php:142-155).

**This is a recorded, deliberate decision.** `20-database-layer-gap-analysis.md`
Finding 4 (decision 2026-08-06) states query-level `traverseParts` /
`traverseExpressions` / `expressionsVisitor` are **NOT needed**: conditions are
stored as plain arrays on the builder, compiled by direct array reads, values
cast via `TypeFactory` in `castConditions`/`castRow`. Cake's three consumers of
query-level traversal (`QueryCompiler`, `IdentifierQuoter`, per-driver type
translators) have no ODM counterpart. Nested expression traversal exists where it
matters — every `Database\Expression\*` node implements `traverse(Closure)`.

### 2.2 Alias resolution is ad hoc and incomplete

Because Mongo has no table aliases, `Articles.id` must become `_id` (or `id`)
*before* it reaches Mongo. The ODM does this in three inconsistent places:

| Concern | Location | Handles `Alias.field` | Handles `id`→`_id` |
|---|---|---|---|
| `where` (Select) | `ODM\Query\SelectQuery::normalizeIdConditions()` (`SelectQuery.php:275`) | only `Alias.id` | yes |
| `where` (Update/Delete/Exists) | none — bare `Database\Query\Query::where()` | no | no |
| `orderBy` | `ODM\Query\SelectQuery::normalizeSortFields()` (`SelectQuery.php:249`) | yes (own alias only) | yes |
| `select` | none | no | no |
| `groupBy` | none | no | no |
| `having` | none | no | no |

`sort` got the treatment only because `HasMany::eagerLoader()` was missing
`'sort' => $this->getSort()` and the raw `Articles.id` silently failed in Mongo.
`group`/`having`/`select` with an aliased field today hit the same trap, and
**so does `updateAll`/`deleteAll`/`exists`** — their conditions never pass
through the ODM `where()` normalization at all.

### 2.3 `OrderClauseExpression` is handled by parsing its SQL

`ODM\Query\SelectQuery::orderBy()` (`SelectQuery.php:216-238`) flattens an
`OrderClauseExpression` by calling `->sql(new ValueBinder())` and splitting the
resulting `"field DIRECTION"` string on spaces, because the class exposes no
direction getter. The compiler cannot accept the expression directly: its
`orderBy()` calls `assertMongoExpression()` on non-Mongo expressions
(`QueryCompiler.php:255-288`).

Cake core never does this — it stores the expression and the compiler reads its
parts. We are round-tripping through a rendered string.

### 2.4 ResultSet owns shaping; the factory is thin

Mirror image of cake — **but this is the frozen ODM design**, not an oversight:

- Phase-0 `WP-RS` (15-odm-phase-0-foundation.md): ResultSet "cooperates with
  WP-M (hydration) and WP-E (eager-load application + external query results)".
- Phase-1 `WP-E` (15-odm-phase-1-layers.md): "external-query results merged by
  `ResultSet` (coordinate with WP-RS)".
- Frozen contract C9 (`15-implementation-plan-odm.md` §3.3) only requires
  `ResultSet`: `getIterator`, `toArray`, `count`, `first`, `serialize` —
  nothing about where shaping lives.

So the ODM intentionally keeps external-association merging in `ResultSet`
(439 lines: `_calculateAssociationMap`, `groupResult`, `hydrateRow`, `mapId`).
The **real defect** is narrower: `groupResult()` (`ResultSet.php:308`) only knows
about keys physically present in the row — it never reads the select clause, so
`select(['Authors.name'])` produces a root document that still carries `author`
(root bloat), and the fields actually selected are not used to trim the entity.

### 2.5 Eager loading splits into two disjoint strategies

- `select`/`reference` → `external[]` list; `EagerLoader::loadExternal()`
  (`ODM\EagerLoader.php:151`) calls `$instance->eagerLoader($config + ...)` and
  the `SelectLoader` re-derives keys itself by reading `$entity->get($sourceKey)`
  (`ODM/Association/Loader/SelectLoader.php:56-63`) — unlike cake, which
  collects keys once and passes them to the loader.
- everything else (embedded / lookup) → `association->buildPipeline()` and
  `$query->pipeline($stages)` (`ODM\EagerLoader.php:393-396`).

**`buildPipeline()` is a frozen contract** (C5: `buildPipeline(array $options=[]):
array` on `Association`), and `08-associations-design-plan.md` §6.4 sanctions
`LookupLoader::eagerLoad()` → "`Association::buildPipeline()` appended to the
source query pipeline". So the strategy split itself is correct.

The ODM `EagerLoader` also rebuilds an **independent** association map in
`ResultSet::_buildAssociationMap()` from `getContain()`, duplicating the
normalization logic that `EagerLoader::normalized()` already computed (C4
mandates `EagerLoader::associationsMap()`). Two maps, two sources of truth.

### 2.6 One doc contradicts the frozen contracts

`docs/Database-for-ODM.md` (the agent contract) says: "No `buildPipeline(array)`
on associations", "No `$query->pipeline($stages)` with hand-built arrays", "Eager
loading uses `$lookup` with a sub-`Pipeline`". This **contradicts**:
- frozen contract C5 (`buildPipeline` on `Association`);
- `08-associations-design-plan.md` §6.4 (LookupLoader uses `buildPipeline`);
- the working code (`Association::buildPipeline()` + `applyPipelineOptions()`,
  `EagerLoader::dispatch()`), which is green and tested.

The master plan is the authority; `docs/Database-for-ODM.md` is the stale doc.

---

## 3. What the decisions cost us

1. **Three-plus normalization sites that drift — and reads miss it entirely.**
   `sort` was fixed because a test failed; `group`/`having`/`select` still accept
   `Alias.field` and emit it verbatim into Mongo — same latent bug, different
   clauses. Worse, `updateAll`/`deleteAll`/`exists` conditions skip ODM
   normalization altogether (bare `Database\Query\Query::where()`), so
   `['id' => ...]` hits a literal `id` field in Mongo.
2. **String round-trip for direction.** `OrderClauseExpression` handling depends
   on the rendered SQL shape (`sql()` output), which changes the moment the
   class or a nested field expression changes.
3. **Result rows can't reflect the select clause.** The `select(['Authors.name'])`
   root-bloat symptom is structural: row shaping never consults the projection,
   so excluded fields still land on the root document.
4. **Two association maps.** `EagerLoader::normalized()`/`associationsMap()` vs
   `ResultSet::_containMap` can disagree (e.g. `matching` flags, config merging).
5. **Loader re-reads source rows instead of receiving keys.** Cake's contract is
   "collect keys once, hand them to the loader"; ours lets each loader walk the
   source set, so `requiresKeys`/FK-presence decisions are not shared.
 6. **Eager loader ignores the association sort unless each `eagerLoader()`
    remembers to pass it.** Only `HasMany` and `BelongsToMany` do today; the
    absence was only caught by a failing test, not by the architecture.
 7. **`docs/Database-for-ODM.md` misleads agents** — it bans the sanctioned
    `buildPipeline` strategy, so any agent following it would fight the frozen
    contracts and the green code.
 8. **Dual `_id`/`id` keys everywhere** — `mapId` (both keys), `exposeId`
    (rename), `mapIdField` (rename), magic `__get('id')`, Marshaller input
    mapping, `normalizeIdConditions` — five inconsistent materialisation sites,
    which forced the `ResultSetTest` fixture hacks. **Resolved by P4: `_id`
    only.**

---

## 4. Proposals

Prioritised by impact / risk. Each is independent; do them in order. Every
proposal respects the frozen contracts (C4/C5/C9) and the recorded decisions in
`20-database-layer-gap-analysis.md`.

### P1 — Single field-resolution pass on the shared ODM query layer

Replace `normalizeIdConditions` + `normalizeSortFields` with one method that all
clause mutators delegate to:

- `resolveField(string $field, ?string $sourceAlias): string`
  — strips `{Alias}.` prefix (own alias, or the alias of any known association),
  maps `id` → `_id`.
- Route `where`, `orderBy`, `orderByAsc/Desc`, `groupBy`, `having`, `select`,
  `selectAlso`, `selectAllExcept` through it.

**Home is the shared ODM query layer, not `SelectQuery`.** All three ODM query
families (`SelectQuery`, `UpdateQuery`, `DeleteQuery`) share
`ODM\Query\CommonQueryTrait`; `UnhydratedSelectQuery` extends `SelectQuery`.
Today the `where()` normalization lives only on `SelectQuery` (lines 194-206),
so `updateAll(['x' => 1], ['id' => ...])`, `deleteAll(['id' => ...])` and
`exists(['id' => ...])` route through the bare
`Database\Query\Query::where()` (Database Query.php:178) and send a literal
`id` / `Alias.id` field into Mongo. That is a real defect, not cosmetics.

Therefore:

- Move the `where()` override (id→`_id` + `Alias.field`→`field`) into
  **`ODM\Query\CommonQueryTrait`** so `Update`/`Delete`/`UnhydratedSelect`
  inherit it automatically.
- Remove the `SelectQuery::where()` override (a class method would shadow the
  trait method — conflict).
- Keep select-specific mutators (`sort`, `select`, `group`, `having` — which
  update/delete never emit in Mongo) on `SelectQuery`.

This mirrors `20-database-layer-gap-analysis.md` Finding 3: shared clause
builders live on the base, not on `SelectQuery`.

Cake-parity note: cake never rewrites fields because SQL aliases exist; our
equivalent "dialect concern" is the alias strip, and it must live in exactly
one place — the ODM query layer — mirroring how `IdentifierQuoter` is the one
place that owns SQL quoting. Do **not** put alias-stripping inside
`Database\Query\QueryCompiler`: that layer must stay alias-agnostic (and the
`20` Finding 4 decision keeps it array-based — no query-level traversal).

Result: `groupBy(['Authors.id'])`, `having(['Authors.total >' => 5])`,
`select(['Authors.name'])`, plus `updateAll`/`deleteAll`/`exists` with
`['id' => ...]` all behave like the `orderBy` fix without per-clause
copy-paste.

### P2 — Fix the direction read without building a compiler expression tree

The `sql()`-string parsing in `ODM\Query\SelectQuery::orderBy()`
(`SelectQuery.php:222-226`) is the hack. The fix is **not** to add query-level
expression handling to the compiler (contradicts `20` Finding 4 decision):

- Add a tiny helper that reads `OrderClauseExpression` once:
  `(string)$expr->getField()` (via `FieldTrait`) for the field, and a
  `direction()` helper that inspects the class — e.g. construct the known shapes
  or store the direction at set time in the ODM `Association::setSort()`.
- The compiler keeps rejecting non-Mongo expressions; the ODM layer is the only
  place that translates cake order syntax into Mongo sort arrays.

Alternative (preferred if acceptable): extend the vendor
`OrderClauseExpression` surface in `cake60` is out of scope; instead keep a
private `@internal` helper in `ODM\Association` / `ODM\Query\SelectQuery` that
maps `OrderClauseExpression` → `[field => 'ASC'|'DESC']` from its constructor
state, with a unit test pinning `sql()`-independence.

### P3 — group/having/select expression handling (ODM layer only)

For symmetry with P2, ensure `groupBy`/`having`/`select` translate the cake
order/condition classes through the same ODM-layer helpers (P1 + P2), so the
ODM query is the single translation point for `Alias.field` → Mongo field, and
the DB compiler stays array-based per `20` Finding 4. No query-level traversal.

### P4 — Drop the `id` concept; `_id` is the only key (DECIDED)

**Decision (2026-08-07):** remove the `_id` ↔ `id` mapping entirely. The ODM is
not SQL and not bound to cake conventions — Mongo's native `_id` is the single
primary-key convention. This kills the dual-key friction permanently.

**What is removed** (all the places that materialise / rename `id`):

| File | Change |
|---|---|
| `ODM/Document.php` | drop magic `__get('id')`; `toArray()` / `jsonSerialize()` keep the `_id` key (only ObjectId→string conversion stays) |
| `ODM/ResultSet.php` | delete `mapId()` (both keys) and `exposeId()` (rename) |
| `ODM/ResultSetFactory.php` | delete `mapIdField()` |
| `ODM/Marshaller.php:315` | delete the `'id'` → `'_id'` input mapping |
| `ODM/Query/SelectQuery.php` | `normalizeIdConditions` / `normalizeSortFields` simplify to alias-stripping only (`Articles._id` → `_id`), no `id`→`_id` case |

**Kept:** `Document::getId()` / `setId()` as thin sugar over `_id` (string in /
string out) — still needed by associations, rules, and the serializer.
`where(['_id' => '24hex'])` keeps working: the Database layer already converts
24-hex strings to `ObjectId` (`QueryBuilder::where()`).

**Result:** one source of truth (`_id`); `ResultSetTest` hacks disappear
(no duplicated `id`+`_id` fixtures, no `unset($fixture['_id'])`, no
`getFixture()`/`filterFixtures()` helpers).

**Test migration:** mechanical — replace standalone `'id'` → `'_id'`
(351 refs / 14 files). The replacement is safe because it targets only the
standalone `'id'` key, never `author_id` / `tag_id` / `category_id` suffixes.
`array_column($rows, 'id')` → `array_column($rows, '_id')`; `$entity->id` →
`$entity->getId()`.

**Interaction with P1:** P1's field-resolution now only strips the `Alias.`
prefix — the `id`→`_id` case is gone, so `where`/`orderBy`/`groupBy`/`having`/
`select` all agree on `_id` without special-casing.

### P4b — `groupResult` consults the select clause (keep the frozen split)

Keep external-association merging in `ResultSet` (frozen WP-RS/WP-E design;
C9 doesn't mandate the factory). Fix the real defect:

- `ResultSet::groupResult()` must read `$query->clause('select')` (via the
  builder projection) and trim the root document to selected fields before
  `newEntity()`, so `select(['Authors.name'])` excludes `author` from the root.
- Do **not** move shaping into `ResultSetFactory` as a wholesale relocation —
  that contradicts the frozen ResultSet-cooperates-with-WP-E design. Only move
  if a future contract change makes the factory the sanctioned owner.

### P5 — Eager loader: collect keys once, pass them to loaders

Align `ODM\EagerLoader::loadExternal()` with cake `ORM\EagerLoader::loadExternal()`
(vendor `:632-670`):

- Collect FK/binding-key values from the hydrated results once
  (`EagerLoader`, mirroring cake `_collectKeys`).
- Pass `'keys' => $keys` through to `SelectLoader`/`LookupLoader` so the loader
  no longer re-reads `$entity->get($sourceKey)`.
- Use the collected keys for the `requiresKeys` / FK-presence guard instead of
  the current per-result scan in `ODM\EagerLoader.php:174-188`.

`08-associations-design-plan.md` §6.1 already describes this shape ("collect FK
values from parents → `whereIn`"); cake's loader is a pure function of
`(config, keys)`. This is a loader-contract improvement, not a strategy change.

### P6 — One association map, one source of truth

Delete `ResultSet::_buildAssociationMap()` / `_containMap` and make every
consumer use `EagerLoader::associationsMap()` (already implemented at
`ODM\EagerLoader.php:206`, mandated by C4). `ResultSet` (P4) and `loadExternal`
(P5) both read the same map, so `matching` flags and nested configs cannot
diverge.

### P7 — Fix the stale agent contract, not the code

Update `docs/Database-for-ODM.md` to match the frozen contracts and the green
code:

- Keep `Association::buildPipeline()` / `EagerLoader::dispatch()` / query-level
  `pipeline()` as the sanctioned lookup strategy (C5 + `08` §6.4); rewrite the
  "No buildPipeline" hard rules to describe the actual contract, and add the
  sub-`Pipeline` variant as the recommended escape hatch where it fits.
- State explicitly which layer owns alias-stripping (shared ODM query layer via
  `CommonQueryTrait`, P1), which owns
  cake-order→Mongo translation (ODM query layer, P2/P3), and which owns row shaping
  (ResultSet, P4).
- Document the `Alias.field` → field convention and the `id`→`_id` mapping as a
  first-class ODM rule, so future clauses get it by default.
- Add a cross-reference to this document.

---

## 5. Suggested order of execution

**Status: P1–P7 implemented 2026-08-07.** Baseline for the change: full suite
`1466 tests / 1262 passed / 62 failed / 39 errors / 103 skipped`. After the
work the Database suite is green (`913 passed / 0 failed / 4 skipped`), the ODM
core has no regressions, and the Association suite is strictly improved
(`testSorting`, `testSetSort`, `testEagerloaderNoForeignKeys` now green;
HasMany+BelongsTo went from `32/24/19` to `36/21/18`). Remaining Association
failures are pre-existing F#-catalogued gaps (cascade/save/link, SQL-only
white-box tests, DTO+contain eager loading).

1. **P4 (drop `id`; `_id` only)** + **P1** together — done. `_id` is the only
   key; `Document`, `ResultSet`, `ResultSetFactory`, `Marshaller`, DTO classes,
   and all ODM test files migrated (standalone `'id'` → `'_id'`; `->id` →
   `->getId()`; int `_id` values → 24-hex strings). `CommonQueryTrait::where()`
   resolves aliases for Select/Update/Delete. `MemoryLogger` restored (it had
   been lost in `3b2aa18`).
2. **P4b + P6** together — done. `ResultSet::applySelectClause()` trims the
   root row to the projection; `ResultSet` uses `EagerLoader::associationsMap()`
   (`_buildAssociationMap` / `_containOptions` deleted).
3. **P2 + P3** — done. `CommonQueryTrait::orderBy()` flattens
   `OrderClauseExpression` without a compiler expression tree; `setSort` /
   `getSort` accept `ExpressionInterface`. `testSorting` / `testSetSort` green.
4. **P5** — done (scoped). `EagerLoader::loadExternal()` now checks
   binding-key presence for all `select` strategies, not just BelongsTo;
   `testEagerloaderNoForeignKeys` green. The full `_collectKeys`-then-pass-keys
   contract was not adopted: the ODM loader closure already reads source
   entities in place, so pre-collecting keys would double-pass the result set.
5. **P7** — done. `docs/Database-for-ODM.md` hard rules updated to match
   contract C5 (`buildPipeline` / `$query->pipeline()` sanctioned) and the new
   field conventions (`_id` only; `Alias.field` → bare field at the ODM layer).

Each step must keep `php vendor/bin/phpunit tests/TestCase/ODM/Association` and
`tests/TestCase/Database` green before moving to the next.

---

## 6. Reference cross-check (what the dev docs already settled)

| Proposal | Confirmed / revised by |
|---|---|
| DB layer stays array-based; no query-level traversal | `20-database-layer-gap-analysis.md` Finding 4 (decision 2026-08-06) |
| `buildPipeline`/`pipeline` is the sanctioned lookup strategy | Contract C5; `08-associations-design-plan.md` §6.4 |
| ResultSet owns external-merge (not the factory) | Phase-0 WP-RS; Phase-1 WP-E; C9 |
| `EagerLoader::associationsMap()` is the single map | Contract C4; `EagerLoader.php:206` |
| Select strategy = batched `whereIn` + dictionary match | `08-associations-design-plan.md` §6.1, §6.6 |
| Tests ported, never patched to pass; gaps as F# | `19-orm-test-porting-workflow.md` §4; `18-orm-tests-port-plan.md` |
| **`_id` is the only primary-key convention (no `id` mapping)** | **P4 — decision 2026-08-07 (this doc)** |
