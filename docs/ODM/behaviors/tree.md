---
title: "Tree"
description: "Store hierarchical data in the Crustum Mongo ODM: use TreeBehavior to manage ancestor arrays, move nodes, and query tree structures efficiently."
---

# Tree

`class` Crustum\Mongo\ODM\Behavior\**TreeBehavior**

> ### PENDING
>
> The examples below are ported from the Cake ORM cookbook and rewritten for the
> ODM's Ancestry Array storage model (verified against
> `src/ODM/Behavior/TreeBehavior.php`). They have not been executed against a
> live `test_mongo_db` yet — run them before removing this banner.

It's fairly common to want to store hierarchical data in a database
collection. Examples of such data might be categories with unlimited
subcategories, data related to a multilevel menu system or a literal
representation of hierarchy such as departments in a company.

The TreeBehavior helps you maintain a hierarchical data structure that can be
queried without much overhead and helps reconstruct the tree data for finding
and displaying processes.

Unlike the SQL ORM (which uses the MPTT `lft`/`rght` nested-set technique), the
ODM behavior uses the **Ancestry Array** pattern: every document stores a direct
`parent_id` reference next to an `ancestors` array holding the ids of all its
ancestors. Subtree lookups become single indexed queries
(`{ancestors: <node id>}`) and breadcrumb paths are read straight from the
field, with no range bookkeeping on writes.

## Requirements

This behavior requires the following fields in your collection schema:

- `parent_id` (nullable) The field holding the id of the parent document. This
  field should be indexed.
- `ancestors` (array) The array of ancestor ids used to maintain the tree
  structure. This field should be indexed.

You can configure the name of those fields should you need to customize them.

> [!WARNING]
> Structural writes that affect a subtree (re-parenting, `removeFromTree()`,
> `recover()`) update the affected documents one by one with `updateAll()`;
> they are not wrapped in a single transaction. Concurrent structural writes
> from simultaneous requests can interleave. Serialize writes that move or
> delete subtrees (e.g. with a lock) if you need strict consistency.

> [!NOTE]
> The behavior resolves the collection's primary key from its first column
> (defaulting to `_id`). Tree operations require a single-value key.

## A Quick Tour

You enable the Tree behavior by adding it to the Collection you want to store
hierarchical data in:

```php
class CategoriesCollection extends Collection
{
    public function initialize(array $config): void
    {
        $this->addBehavior('Tree');
    }
}
```

Once added, you can let the behavior build the internal structure if the
collection is already holding some documents:

```php
// In a controller
$categories = FactoryLocator::get('Collection')->get('Categories');
$categories->getBehavior('Tree')->recover();
```

You can verify it works by getting any document from the collection and asking
for the count of descendants it has:

```php
$node = $categories->get('64c7c2e3a1b2c3d4e5f6a7b8');
echo $categories->getBehavior('Tree')->childCount($node);
```

### Getting descendants

Getting a flat list of the descendants for a node can be done with:

```php
$descendants = $categories->find('children', for: '64c7c2e3a1b2c3d4e5f6a7b8');

foreach ($descendants as $category) {
    echo $category->name . "\n";
}
```

If you need to pass conditions you do so as per normal:

```php
$descendants = $categories
    ->find('children', for: '64c7c2e3a1b2c3d4e5f6a7b8')
    ->where(['name LIKE' => '%Foo%'])
    ->all();

foreach ($descendants as $category) {
    echo $category->name . "\n";
}
```

By default `children` returns the whole subtree below the node. If you only
want the direct children, pass `direct: true`:

```php
$directChildren = $categories->find('children', for: '64c7c2e3a1b2c3d4e5f6a7b8', direct: true);
```

If you instead need a threaded list, where children for each node are nested
in a hierarchy, you can stack the 'threaded' finder:

```php
$children = $categories
    ->find('children', for: '64c7c2e3a1b2c3d4e5f6a7b8')
    ->find('threaded')
    ->toArray();

foreach ($children as $child) {
    echo "{$child->name} has " . count($child->children) . " direct children";
}
```

If you're using a custom `parent` field you need to pass it in the 'threaded'
finder option (i.e. `parentField`).

> [!NOTE]
> For more information on 'threaded' finder options see [Finding Threaded Data logic](../retrieving-data-and-resultsets#finding-threaded-data)

### Getting formatted tree lists

Traversing threaded results usually requires recursive functions, but if you
only require a result set containing a single field from each level so you can
display a list, in an HTML select for example, it is better to use the
`treeList` finder:

```php
$list = $categories->find('treeList')->toArray();

// In a CakePHP template file:
echo $this->Form->control('categories', ['options' => $list]);

// Or you can output it in plain text, for example in a CLI script
foreach ($list as $categoryName) {
    echo $categoryName . "\n";
}
```

The output will be similar to:

    My Categories
    _Fun
    __Sport
    ___Surfing
    ___Skating
    _Trips
    __National
    __International

The `treeList` finder takes a number of options:

- `keyPath`: The field to use for the array key, or a closure to return the key
  out of the provided row.
- `valuePath`: The field to use for the array value, or a closure to return the
  value out of the provided row.
- `spacer`: A string to be used as prefix for denoting the depth in the tree
  for each item.

An example of all options in use is:

```php
$query = $categories->find('treeList',
    keyPath: 'url',
    valuePath: 'id',
    spacer: ' ',
);
```

An example using closures:

```php
$query = $categories->find('treeList',
    valuePath: function ($document) {
        return $document->url . ' ' . $document->id;
    },
);
```

> [!NOTE]
> Unlike the SQL behavior, `keyPath`/`valuePath` are direct field names —
> dot-separated paths are not expanded. Use a closure when you need nested or
> computed values. Depth is computed in memory from the `parent_id` chain.

### Finding a path or branch in the tree

One very common task is to find the tree path from a particular node to the root
of the tree. This is useful, for example, for adding the breadcrumbs list for
a menu structure:

```php
$nodeId = '64c7c2e3a1b2c3d4e5f6a7b8';
$crumbs = $categories->find('path', for: $nodeId)->all();

foreach ($crumbs as $crumb) {
    echo $crumb->name . ' > ';
}
```

The `path` finder reads the node's stored `ancestors` chain and orders the
results root-first, so no database traversal is needed.

### Reordering nodes among siblings

In the ODM, sibling ordering is not part of the tree structure itself — it is
tracked by an optional `sort` field. To use `moveUp()`/`moveDown()` you must
configure the `sort` option:

```php
class CategoriesCollection extends Collection
{
    public function initialize(array $config): void
    {
        $this->addBehavior('Tree', [
            'sort' => 'position',
        ]);
    }
}
```

```php
$node = $categories->get('64c7c2e3a1b2c3d4e5f6a7b8');

// Move the node so it shows up one position up when listing children.
$categories->getBehavior('Tree')->moveUp($node);

// Move the node to the top of the list inside the same level.
$categories->getBehavior('Tree')->moveUp($node, true);

// Move the node to the bottom.
$categories->getBehavior('Tree')->moveDown($node, true);
```

New nodes get the next `sort` value among their siblings automatically. Without
a `sort` config, `moveUp()`/`moveDown()` are no-ops.

## Configuration

If the default field names that are used by this behavior don't match your own
schema, you can provide aliases for them:

```php
public function initialize(array $config): void
{
    $this->addBehavior('Tree', [
        'parent' => 'ancestor_id',   // Use this instead of parent_id
        'ancestors' => 'tree_path',  // Use this instead of ancestors
    ]);
}
```

### Node Level (Depth)

Knowing the depth of tree nodes can be useful when you want to retrieve nodes
only up to a certain level, for example, when generating menus. You can use the
`level` option to specify the field that will save the level of each node:

```php
$this->addBehavior('Tree', [
    'level' => 'level', // Defaults to null, i.e. no level saving
]);
```

The level is the number of ancestors of the node. It is maintained on every
write (new nodes, re-parenting, `recover()`).

If you don't want to cache the level using a database field you can use
`TreeBehavior::getLevel()` method to get the level of a node:

```php
$level = $categories->getBehavior('Tree')->getLevel($node);
```

### Scoping and Multi Trees

Sometimes you want to persist more than one tree structure inside the same
collection, you can achieve that by using the 'scope' configuration. For
example, in a locations collection you may want to create one tree per country:

```php
class LocationsCollection extends Collection
{
    public function initialize(array $config): void
    {
        $this->addBehavior('Tree', [
            'scope' => ['country_name' => 'Brazil'],
        ]);
    }
}
```

In the previous example, all tree operations will be scoped to only the
documents having the field `country_name` set to 'Brazil'. You can change the
scoping on the fly by using the `setConfig()` function:

```php
$this->getBehavior('Tree')->setConfig('scope', ['country_name' => 'France']);
```

Optionally, you can have a finer grain control of the scope by passing a closure
as the scope. Unlike the SQL behavior, the closure receives a
`QueryExpression` (and the query) and must return the conditions to add:

```php
$this->getBehavior('Tree')->setConfig('scope', function ($exp, $query) {
    $country = $this->getConfiguredCountry(); // A made-up function

    return ['country_name' => $country];
});
```

### Deletion Behavior

By enabling the `cascadeCallbacks` option, `TreeBehavior` will load all of
the documents that are going to be deleted. Once loaded, these documents will
be deleted individually using `Collection::delete()`. This enables collection
callbacks to be fired when tree nodes are deleted:

```php
$this->addBehavior('Tree', [
    'cascadeCallbacks' => true,
]);
```

Without `cascadeCallbacks`, descendants are removed with a single bulk delete
query that matches the `ancestors` field, and no callbacks are fired for them.

## Recovering the Tree

`recover()` rebuilds the `ancestors` (and optional `level`) fields from the
`parent_id` references, walking the tree iteratively from its roots:

```php
$categories->getBehavior('Tree')->recover();
```

Because the Ancestry Array stores only ancestor membership, the traversal order
has no effect on the recovered values — every document's chain is derived
solely from its parent reference. There is no ordering option (the SQL
behavior's `recoverOrder` does not apply: there is no `lft`/`rght` to order).
Sibling order, if you need it, lives in the optional `sort` field.

## Saving Hierarchical Data

When using the Tree behavior, you usually don't need to worry about the
internal representation of the hierarchical structure. The positions where
nodes are placed in the tree are deduced from the `parent_id` field in each of
your documents:

```php
$aCategory = $categories->get('64c7c2e3a1b2c3d4e5f6a7b9');
$aCategory->parent_id = '64c7c2e3a1b2c3d4e5f6a7b8';
$categories->save($aCategory);
```

When a new document is saved with a `parent_id`, its `ancestors` array is built
from the parent's chain. When an existing document's `parent_id` changes, the
whole subtree is re-parented: every descendant's `ancestors` array is rewritten.

Providing inexistent parent ids when saving or attempting to create a loop in
the tree (making a node child of itself, or of one of its own descendants) will
throw an exception.

You can make a node into a root in the tree by setting the `parent_id` field to
null:

```php
$aCategory = $categories->get('64c7c2e3a1b2c3d4e5f6a7b9');
$aCategory->parent_id = null;
$categories->save($aCategory);
```

Children for the new root node will be preserved.

## Deleting Nodes

Deleting a node and all its sub-tree (any children it may have at any depth in
the tree) is trivial:

```php
$aCategory = $categories->get('64c7c2e3a1b2c3d4e5f6a7b9');
$categories->delete($aCategory);
```

The TreeBehavior will take care of all internal deleting operations for you
(descendants are matched through the `ancestors` field). It is also possible to
only delete one node and re-assign all children to the immediately superior
parent node in the tree:

```php
$aCategory = $categories->get('64c7c2e3a1b2c3d4e5f6a7b9');
$categories->getBehavior('Tree')->removeFromTree($aCategory);
$categories->delete($aCategory);
```

All children nodes will be kept and a new parent will be assigned to them.

The deletion of a node is based on the `parent_id` and `ancestors` fields of
the documents. This is important to note when looping through the various
children of a node for conditional deletes:

```php
$descendants = $teams->find('children', for: '64c7c2e3a1b2c3d4e5f6a7b8')->all();

foreach ($descendants as $descendant) {
    $team = $teams->get($descendant->id); // search for the up-to-date document object
    if ($team->expired) {
        $teams->delete($team); // deletion removes the document and its descendants
    }
}
```

TreeBehavior will update the `parent_id`/`ancestors` fields of the remaining
records in the collection when a node is deleted.

In our example above, the `ancestors` values of the documents inside
`$descendants` will be inaccurate. You will need to reload existing document
objects if you need an accurate shape of the tree.