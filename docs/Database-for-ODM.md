# Building the ODM Layer on the Database Layer

This is the **agent contract** for writing `Crustum\Mongo\ODM\*` code that runs on top of
`Crustum\Mongo\Database`. It states what ODM code does so it reuses the Database layer
instead of re-implementing pipeline, filter, and casting concerns.

Read this together with `docs/Queries.md` (the full query surface).

## What the Database layer already owns

| Concern | Owner | ODM must NOT re-implement |
|---|---|---|
| `$match` from `where()` | `QueryCompiler::compileAggregate()` prepends it at the head | inject raw `['$match' => ...]` stages |
| First-stage-only ordering | compiler skips the `$match` prepend before `$search`/`$geoNear`/`$indexStats` | reorder stages manually |
| Stage storage + compile | `Pipeline` (single `compile()` loop) | hold parallel stage arrays |
| Stage compile output | each `Stage::getExpression()` | hand-assemble `[['$op' => ...]]` |
| Condition → filter casting | `where()` + type map | manual BSON casts |
| Results casting | `setSelectTypeMap` / `decorateResults` | per-row casts in ODM |

## Hard rules

1. **Associations build lookup pipelines via `buildPipeline(array)`.** Contract C5
   sanctions `Association::buildPipeline($options)` returning stage arrays; the ODM
   `EagerLoader` appends them with `$query->pipeline($stages)` (see
   `docs/reference/21-orm-database-boundary-proposals.md` P7). Where a sub-pipeline fits
   better (conditions scoped inside `$lookup`), use the `lookup(..., ['pipeline' =>
   fn (SelectQuery $q) => ...])` form instead of sequential appended stages.

2. **`$query->pipeline($stages)` is the sanctioned composition point for
   association stages.** Hand-built escape-hatch stages are allowed at the query
   surface; prefer the builder / sugar methods for the long tail.

3. **Filters and repository conditions go through `where()`.** Behavior filters, soft-delete,
   and any collection-level discriminator inject conditions into `where()` — the compiler
   produces the `$match`.

4. **Eager loading uses `$lookup` with a sub-`Pipeline`.** One `$lookup` per association, its
   conditions inside the sub-pipeline (nested builder or `Pipeline`), not sequential
   `$lookup`→`$match`→`$addFields` appended stages. `buildPipeline()` (rule 1) is the
   opt-in join-style variant for simple associations.

5. **Association stages land before `$skip`/`$limit`.** A `limit()` in the query must apply
   after joins. Compose lookup stages ahead of pagination, never after it.

6. **Closure receiver rule is non-negotiable.** Closures receive `SelectQuery`,
   `QueryExpression` (+ query), or `AggregationBuilder` — never a `Stage\*` object or raw
   array. Query-side sub-pipelines receive a `SelectQuery`
   (`lookup('x', ['pipeline' => fn (SelectQuery $q) => ...])`); builder-side sub-pipelines
   receive an `AggregationBuilder`.

7. **Prefer query sugar; use the builder only for the long tail.** Common stages
   (`groupBy`, `window`, `lookup`, `unwind`, `addFields`, `sample`, `facet`, ...) have
   `$this`-returning sugar on the query. The builder is the escape hatch.

## Field conventions (ODM layer)

- **`_id` is the only primary-key name.** There is no `id` alias. `Document::getId()` /
  `setId()` are thin sugar over `_id`; `toArray()` keeps the `_id` key.
- **`Alias.field` keys are resolved to bare fields at the ODM query layer.**
  `where(['Authors._id' => X])`, `orderBy(['Articles._id' => 'DESC'])`,
  `groupBy(['Authors._id'])` — `CommonQueryTrait` strips the repository alias prefix so
  Mongo never sees a dotted alias path. This is shared by Select, Update, and Delete
  queries (`updateAll` / `deleteAll` / `exists` conditions resolve the same way reads do).
- **`select()` is respected by result shaping.** `ResultSet::groupResult()` trims the root
  document to the selected projection, so `select(['Authors.name'])` excludes the rest of
  the root row. The Database compiler stays alias-agnostic and array-based — no query-level
  expression tree (see `docs/reference/20-database-layer-gap-analysis.md` Finding 4).
- **Identifier types come from the schema, not field names.** A field whose schema type is
  `objectid` (`*_id`, `foreign_key`, any name) has its 24-hex string values converted to
  `ObjectId` when compiling conditions (scalar or inside `IN` arrays) and when loading
  fixtures. `parent_id`-style fields declared as `string`/`int` stay strings. See
  `docs/reference/23-join-facade-design.md` §8.

## Patterns

### Filters / behaviors

```php
// in a Behavior
$query->where(['deleted' => false]);                 // -> $match, prepended by the compiler
$query->where(fn (QueryExpression $exp) => $exp->isNull('deleted_at'));
```

### Ad-hoc join (SQL-style over `$lookup`)

`join()` / `leftJoin()` are fluent facades over Mongo `$lookup`, defined on the Database
base `Query` and inherited by ODM queries. Field-to-field comparisons use `equalFields()`
(left = source field, right = target field); value conditions use `where()` / expressions.
No `$` / `$$` / `$expr` / flat-array `$lookup` is written by the caller.

```php
// INNER join: rows with no match are dropped
$authors->find()
    ->join('author_audits', function (SelectQuery $q) {
        $q->where(fn(QueryExpression $exp) => $exp
            ->equalFields('Authors.user_id', 'author_audits.foreign_key')
            ->eq('author_audits.model', 'Author'));
    });

// LEFT join: source rows are kept even without a match
$authors->find()
    ->leftJoin('author_audits', function (SelectQuery $q) {
        $q->where(fn(QueryExpression $exp) => $exp
            ->equalFields('Authors.user_id', 'author_audits.foreign_key'));
    });

// Aliased join: `['audits' => 'author_audits']` becomes the `as` key / alias
$authors->find()
    ->join(['audits' => 'author_audits'], function (SelectQuery $q) {
        $q->where(fn(QueryExpression $exp) => $exp
            ->equalFields('Authors.user_id', 'audits.foreign_key'));
    }, ['asArray' => true]);   // keep the nested array, skip $unwind
```

Notes:
- The result is a **nested array** under the join alias (`$row->author_audits`), not flat
  SQL columns.
- Source fields carry the source alias (`Authors.`), target fields the join alias /
  collection (`author_audits.`); unqualified fields default to the target.
- `equalFields()` compiles to `$expr`; identifier conversion is schema-driven (see field
  conventions above).

### Join-scoped writes (recommendation)

`save()` / `delete()` work on **one document by `_id`** — they never need a join.
`updateAll()` / `deleteAll()` are raw bulk ops and Mongo cannot run a `$lookup`
inside them. To scope a write to rows that match a join, do it in two steps
(recommended) or via aggregation (for updates):

```php
// Delete: find the joined `_id`s, then deleteAll by them (raw, no events — like cake)
$ids = $authors->find()
    ->select(['Authors._id'])
    ->join('author_audits', function (SelectQuery $q) {
        $q->where(fn(QueryExpression $exp) => $exp
            ->equalFields('Authors.user_id', 'author_audits.foreign_key')
            ->eq('author_audits.model', 'Author'));
    })
    ->all()
    ->extract('_id')
    ->toList();

$affected = $authors->deleteAll(['_id IN' => $ids]);

// Update the same way: collect `_id`s, then updateAll
$affected = $authors->updateAll(['archived' => true], ['_id IN' => $ids]);
```

If you need per-row **events** (`Collection.beforeSave/afterSave`,
`beforeDelete/afterDelete`) on the matched rows, iterate the joined query and
call `save()`/`delete()` per document instead of `updateAll`/`deleteAll` — the
join is just a `find()`; the write still goes through the row-level path.

For an **update that writes values derived from the joined collection**, use the
aggregation form (`$lookup` → `$set` → `$merge`); note it returns a cursor, not
`updateMany`'s modified count, so it is not a drop-in for `updateAll()`:

### Referenced association (eager load)

```php
// ODM EagerLoader — build a sub-pipeline, not appended stages
$query->lookup($target->getCollection(), [
    'localField' => $foreignKey,
    'foreignField' => $bindingKey,
    'as' => $property,
    'pipeline' => fn (SelectQuery $q) => $q->where($conditions), // sub-query, ordered inside $lookup
]);
$query->unwind('$' . $property, ['preserveNullAndEmptyArrays' => true]);
```

### Embedded association

```php
// condition on the embedded array is a plain where() on the path
$query->where([$property . '.status' => 'active']);
```

### Advanced aggregation (ODM Collection finder)

```php
$query
    ->where(['tenant_id' => $tenant])       // behavior filter stays in $match
    ->groupBy(['category'])
    ->having(fn (QueryExpression $exp) => $exp->gte('total', 10));
```

## Checklist before writing ODM code

- [ ] Is this already a Database-layer method? Reuse it — do not wrap or re-implement.
- [ ] Am I building stage arrays by hand? If yes, use the builder/sugar instead.
- [ ] Does any closure receive something other than `SelectQuery` / `QueryExpression` /
      `AggregationBuilder`? If yes, fix it.
- [ ] Could a `$limit`/`$skip`/`$sort` be applied before my lookup? If yes, reorder.
- [ ] Am I compiling a pipeline myself? Use `Pipeline::compile()` / `getPipeline()`.

## Source map

- Query surface: `src/Database/Query/SelectQuery.php`, `AggregationQueryTrait.php`
- Compiler: `src/Database/Query/QueryCompiler.php`
- Builder/pipeline: `src/Database/Aggregation/*`
