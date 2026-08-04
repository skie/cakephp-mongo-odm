# Writing Queries (Database Layer)

The Database layer exposes `Crustum\Mongo\Database\Query\SelectQuery` — a Mongo find +
aggregation query. Use it directly for raw access, or through the ODM layer
(`Crustum\Mongo\ODM\Query\SelectQuery`) which inherits the same surface.

## Getting a query

```php
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Query\SelectQuery;

$connection = ConnectionManager::get('default'); // any Connection
$query = new SelectQuery($connection, 'articles');

// raw client access, if you need it
$collection = $connection->getCollection('articles');
```

## Find-style queries

`where()` compiles to a Mongo filter. Everything that does not require aggregation
compiles to a `find()` (`compile()['type'] === 'find'`).

```php
$query
    ->where(['author_id' => 1, 'status' => 'published'])
    ->andWhere(fn (QueryExpression $exp) => $exp->gte('published_at', $since))
    ->select(['title', 'body'])
    ->orderBy(['created' => 'desc'])
    ->limit(20)
    ->skip(40); // page 3 at 20/page

$query->page(3, 20); // convenience: skip+limit

$first = $query->first();       // ?array
$all   = $query->all();         // ResultSetInterface
$total = $query->count();       // int
$tags  = $query->distinct('tags');
```

### Conditions

Pass arrays (Mongo query operators work as-is) or closures:

```php
// raw operators pass through
->where(['price' => ['$gte' => 100, '$lt' => 500]])
->where(['tags' => ['$in' => ['php', 'mongo']]])

// closure form: receive (QueryExpression, query) and return conditions to merge
->where(fn (QueryExpression $exp, SelectQuery $query) => $exp->or([...]))
```

Supported `QueryExpression` helpers: `eq`, `notEq`, `gt`, `gte`, `lt`, `lte`, `isNull`,
`isNotNull`, `like`, `in`, `notIn`, `between`, `exists`, `notExists`, plus `and`/`or`/`not`
combinators.

### Casting

Values are cast through the type map before hitting Mongo — `ObjectId`, `UTCDateTime`,
`Decimal128`, etc.:

```php
use MongoDB\BSON\ObjectId;

$query->where(['_id' => $id]);                // string -> ObjectId when typed
$query->where(['created' => '2024-01-02'], ['created' => 'datetime']);
```

## Aggregation

Add any aggregation construct and the query compiles to an `aggregate()` pipeline
(`compile()['type'] === 'aggregate'`).

### Clause methods that compile to stages

| Method | Compiles to |
|---|---|
| `where()` / `andWhere()` | `$match` (prepended at the head) |
| `groupBy($fields)` | `$group` |
| `having()` / `andHaving()` | `$match` after `$group` |
| `window($windows)` | `$setWindowFields` |
| `select()` / `selectAlso()` | `$project` |
| `orderBy()` | `$sort` |
| `skip()` / `limit()` | `$skip` / `$limit` |

```php
$query
    ->where(['status' => 'active'])
    ->groupBy(['category'])
    ->having(fn (QueryExpression $exp) => $exp->gte('total', 10))
    ->window([
        'partitionBy' => '$category',
        'sortBy' => ['created' => -1],
        'output' => ['rank' => ['$rank' => []]],
    ])
    ->select(['title', 'total']);
```

### Sugar methods

Common stages, `$this`-returning, options-array style:

```php
$query
    ->lookup('comments', ['localField' => '_id', 'foreignField' => 'article_id', 'as' => 'comments'])
    ->unwind('$comments')
    ->addFields(['score' => ['$multiply' => ['$x', 2]]])
    ->setFields(['score' => ['$multiply' => ['$x', 2]]])
    ->sample(10)
    ->facet(['byCategory' => [['$group' => ['_id' => '$category']]]])
    ->sortByCount('$category')
    ->replaceRoot('$author')
    ->replaceWith('$author')
    ->search(['index' => 'default', 'text' => ['path' => 'title', 'query' => 'mongo']])
    ->vectorSearch(['index' => 'vector', 'queryVector' => [1.0, 2.0], 'path' => 'embedding'])
    ->graphLookup('employees', ['startWith' => '$reportsTo', 'connectFromField' => 'name', 'connectToField' => 'reportsTo', 'as' => 'tree'])
    ->unionWith('archives')
    ->unsetStage(['legacy']);
```

### First-stage-only stages

`$search`, `$geoNear` and `$indexStats` must be the pipeline head. When the first stage is
one of these, the `$match` from `where()` is **not** prepended:

```php
// safe: $search is head; the where() filter is omitted rather than breaking the pipeline
$query->where(['status' => 'active'])->search(['index' => 'default', 'text' => [...]]);
```

## Pipeline escape hatches

### `pipeline(array $stages)`

Append raw wire-format stages:

```php
$query->pipeline([
    ['$group' => ['_id' => '$category', 'total' => ['$sum' => 1]]],
]);
```

### `pipeline(fn (AggregationBuilder $b) => ...)`

Compose the long tail through the stage-fluent builder. The closure receives an
`AggregationBuilder`; stages are appended on return:

```php
$query->pipeline(function (AggregationBuilder $builder) {
    $builder->match(['status' => 'active'])->unionWith('archives');
});
```

### `AggregationBuilder` (stage-fluent)

Every stage method returns the stage it creates, so setters chain on the stage. The builder
never mixes `stage|$this` return types.

```php
$builder->lookup('comments')->localField('_id')->foreignField('article_id')->alias('comments');
$builder->match(['status' => 'active']);
$builder->addStage('$limit', 10);          // raw custom stage -> RawStage

$pipeline = $builder->getPipeline();       // list<array>
```

Sub-pipelines accept `Pipeline|array|Closure` and are compiled by the same canonical path:

```php
// array
$builder->lookup('comments')->pipeline([['$match' => ['approved' => true]]]);
// closure (receives a fresh AggregationBuilder)
$builder->lookup('comments')->pipeline(fn (AggregationBuilder $sub) => $sub->match(['approved' => true]));
// direct Pipeline instance (self-reference to the top-level pipeline is rejected)
$builder->facet()->addFacet('byCategory', [['$group' => ['_id' => '$category']]]);
```

## Closure receiver rules

A closure passed to any query/builder method receives exactly one of:

| Receiver | Where | Shape |
|---|---|---|
| `SelectQuery` | sub-queries, sugar `pipeline`/`lookup` options | `fn (SelectQuery $q) => ...` |
| `QueryExpression` + query | `where`/`andWhere`/`having`/`andHaving` first arg | `fn (QueryExpression $exp, SelectQuery $query) => ...` |
| `AggregationBuilder` | `pipeline()` closure form, builder sub-pipelines | `fn (AggregationBuilder $b) => ...` |

Declare only the parameters you use — a closure `fn (QueryExpression $exp) => ...` is valid;
the query argument is passed but not required.

Closures are invoked (never stored), and their return values are normalized like literal
arguments. A closure never receives a `Stage\*` object or a raw array.

## Result casting & decorators

```php
$query->setSelectTypeMap(['_id' => 'objectid', 'created' => 'datetime']);
$query->enableResultsCasting();                       // default
$query->disableResultsCasting();                      // rows stay raw
$query->decorateResults(fn (array $row) => $row + ['slug' => ...]);
```

## Compile / execute

```php
$compiled = $query->compile();   // ['type' => 'find'|'aggregate', ...]
$query->execute();               // run via the connection
```

## Reading the source

- `src/Database/Query/SelectQuery.php` — clause + execution surface.
- `src/Database/Query/AggregationQueryTrait.php` — sugar methods (do not edit).
- `src/Database/Aggregation/AggregationBuilder.php` — stage-fluent escape hatch.
- `src/Database/Aggregation/Pipeline.php` — canonical stage list + compile.
- `src/Database/Query/QueryCompiler.php` — filter/projection/compile engine.
