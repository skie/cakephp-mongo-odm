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

1. **No `buildPipeline(array)` on associations.** Associations do not return stage arrays;
   they configure the query or compose a sub-`Pipeline`.

2. **No `$query->pipeline($stages)` with hand-built arrays.** The query-level `pipeline()`
   exists for user escape hatches. ODM code composes through the builder / sugar methods.

3. **Filters and repository conditions go through `where()`.** Behavior filters, soft-delete,
   and any collection-level discriminator inject conditions into `where()` — the compiler
   produces the `$match`.

4. **Eager loading uses `$lookup` with a sub-`Pipeline`.** One `$lookup` per association, its
   conditions inside the sub-pipeline (nested builder or `Pipeline`), not sequential
   `$lookup`→`$match`→`$addFields` appended stages.

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

## Patterns

### Filters / behaviors

```php
// in a Behavior
$query->where(['deleted' => false]);                 // -> $match, prepended by the compiler
$query->where(fn (QueryExpression $exp) => $exp->isNull('deleted_at'));
```

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
