<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Behavior;

use Cake\Collection\CollectionInterface;
use Cake\Database\Exception\DatabaseException;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Event\EventInterface;
use Closure;
use Crustum\Mongo\ODM\Behavior;
use Crustum\Mongo\ODM\Query\DeleteQuery;
use Crustum\Mongo\ODM\Query\SelectQuery;
use Crustum\Mongo\ODM\Query\UpdateQuery;

/**
 * Hierarchical tree behavior backed by the **Ancestry Array** pattern.
 *
 * Each document stores the ids of all its ancestors (`ancestors`) next to a
 * direct `parent_id` reference, which makes subtree lookups (`ancestors`
 * contains the node) and breadcrumb paths single-index queries instead of the
 * MPTT `lft`/`rght` bookkeeping CakeSQL uses.
 *
 * The public surface mirrors `Cake\ORM\Behavior\TreeBehavior`: the `path`,
 * `children` and `treeList` finders, plus `childCount()`, `getLevel()`,
 * `moveUp()`, `moveDown()`, `removeFromTree()` and `recover()`. Persistence
 * events (`Collection.beforeSave`, `Collection.beforeDelete`) keep the
 * ancestry fields in sync transparently.
 *
 * ### Config
 *
 * - `parent`: the direct-parent field (default `parent_id`)
 * - `ancestors`: the ancestry array field (default `ancestors`)
 * - `level`: optional numeric depth field maintained on every write
 * - `sort`: optional numeric sibling-order field maintained by `moveUp()` /
 *   `moveDown()`
 * - `scope`: optional conditions (array or closure) restricting the tree
 * - `recoverOrder`: ordering used by `recover()` for sibling traversal
 * - `cascadeCallbacks`: whether `beforeDelete` deletes descendants one-by-one
 *   (firing callbacks) instead of a bulk delete
 *
 * @see cake60/src/ORM/Behavior/TreeBehavior.php
 */
class TreeBehavior extends Behavior
{
    /**
     * Cached copy of the first column in the collection's primary key.
     *
     * @var string
     */
    protected string $primaryKey = '';

    /**
     * Default configuration.
     *
     * @var array<string, mixed>
     */
    protected array $defaultConfig = [
        'implementedFinders' => [
            'path' => 'findPath',
            'children' => 'findChildren',
            'treeList' => 'findTreeList',
        ],
        'parent' => 'parent_id',
        'ancestors' => 'ancestors',
        'level' => null,
        'sort' => null,
        'scope' => null,
        'recoverOrder' => null,
        'cascadeCallbacks' => false,
    ];

    /**
     * Before-save listener.
     *
     * Sets the `ancestors` (and optional `level`/`sort`) values for new nodes
     * and re-parents a node's whole subtree when the parent field changes.
     *
     * @param \Cake\Event\EventInterface<\Crustum\Mongo\ODM\BaseCollection> $event The beforeSave event.
     * @param \Cake\Datasource\EntityInterface $document The document being saved.
     * @return void
     * @throws \Cake\Database\Exception\DatabaseException When the parent would create a cycle.
     */
    public function beforeSave(EventInterface $event, EntityInterface $document): void
    {
        $config = $this->getConfig();
        $parent = $document->get($config['parent']);
        $primaryKey = $this->getPrimaryKey();

        if ($parent && $document->get($primaryKey) === $parent) {
            throw new DatabaseException("Cannot set a node's parent as itself.");
        }

        if ($document->isNew()) {
            if ($parent) {
                $parentNode = $this->getNode($parent);
                $ancestors = array_merge($parentNode->get($config['ancestors']), [$parent]);
                $document->set($config['ancestors'], $ancestors);
                $this->setLevelAndSort($document, $ancestors, $parent);

                return;
            }

            $document->set($config['ancestors'], []);
            $this->setLevelAndSort($document, [], null);

            return;
        }

        if ($document->isDirty($config['parent'])) {
            $this->setParent($document, $parent);
        }
    }

    /**
     * Before-delete listener.
     *
     * Removes every descendant (documents whose `ancestors` contain the node
     * id) together with the node being deleted.
     *
     * @param \Cake\Event\EventInterface<\Crustum\Mongo\ODM\BaseCollection> $event The beforeDelete event.
     * @param \Cake\Datasource\EntityInterface $document The document being deleted.
     * @return void
     */
    public function beforeDelete(EventInterface $event, EntityInterface $document): void
    {
        $config = $this->getConfig();
        $primaryKey = $this->getPrimaryKey();
        $nodeId = $document->get($primaryKey);

        if ($this->getConfig('cascadeCallbacks')) {
            $documents = $this->scope($this->collection->find())
                ->where([$config['ancestors'] => $nodeId])
                ->toArray();
            foreach ($documents as $documentToDelete) {
                $this->collection->delete($documentToDelete, ['atomic' => false]);
            }

            return;
        }

        $this->scope($this->collection->deleteQuery())
            ->where([$config['ancestors'] => $nodeId])
            ->execute();
    }

    /**
     * Custom finder returning the nodes from the root to the given node,
     * ordered root-first.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery<\Crustum\Mongo\ODM\Document|array> $query The query to modify.
     * @param string|int $for The id of the node to find the path for.
     * @return \Crustum\Mongo\ODM\Query\SelectQuery<\Crustum\Mongo\ODM\Document|array>
     */
    public function findPath(SelectQuery $query, string|int $for): SelectQuery
    {
        $config = $this->getConfig();
        $primaryKey = $this->getPrimaryKey();
        $node = $this->getNode($for);
        $ids = $node->get($config['ancestors']);
        $ids[] = $node->get($primaryKey);

        $query = $this->scope($query)->where([$primaryKey . ' IN' => $ids]);

        return $query->formatResults(
            fn(CollectionInterface $results): array => $this->orderPathRows($results, $ids, $primaryKey),
        );
    }

    /**
     * Gets the children nodes of the current collection.
     *
     * With `direct` set to true only the direct children (matching the parent
     * field) are returned; otherwise the whole subtree below the node is
     * returned.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery<\Crustum\Mongo\ODM\Document|array> $query The query to modify.
     * @param string|int $for The id of the node to read children for.
     * @param bool $direct Whether to return only the direct children.
     * @return \Crustum\Mongo\ODM\Query\SelectQuery<\Crustum\Mongo\ODM\Document|array>
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When the node does not exist.
     */
    public function findChildren(SelectQuery $query, string|int $for, bool $direct = false): SelectQuery
    {
        $config = $this->getConfig();

        if ($direct) {
            return $this->scope($query)->where([$config['parent'] => $for]);
        }

        $this->getNode($for);

        return $this->scope($query)->where([$config['ancestors'] => $for]);
    }

    /**
     * Gets a representation of the tree as a flat list where the keys are the
     * primary key (or `keyPath`) and the values are the display field (or
     * `valuePath`), prefixed with `spacer` repeated per depth level.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery<\Crustum\Mongo\ODM\Document|array> $query The query to modify.
     * @param \Closure|string|null $keyPath The field to use as the array key, or a closure.
     * @param \Closure|string|null $valuePath The field to use as the array value, or a closure.
     * @param string|null $spacer The depth prefix string.
     * @return \Crustum\Mongo\ODM\Query\SelectQuery<\Crustum\Mongo\ODM\Document|array>
     */
    public function findTreeList(
        SelectQuery $query,
        Closure|string|null $keyPath = null,
        Closure|string|null $valuePath = null,
        ?string $spacer = null,
    ): SelectQuery {
        return $this->formatTreeList($this->scope($query), $keyPath, $valuePath, $spacer);
    }

    /**
     * Formats the given query as a flat tree list, prefixing each value with
     * `spacer` repeated per depth level.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery<\Crustum\Mongo\ODM\Document|array> $query The query to format.
     * @param \Closure|string|null $keyPath The field to use as the array key, or a closure.
     * @param \Closure|string|null $valuePath The field to use as the array value, or a closure.
     * @param string|null $spacer The depth prefix string.
     * @return \Crustum\Mongo\ODM\Query\SelectQuery<\Crustum\Mongo\ODM\Document|array>
     */
    public function formatTreeList(
        SelectQuery $query,
        Closure|string|null $keyPath = null,
        Closure|string|null $valuePath = null,
        ?string $spacer = null,
    ): SelectQuery {
        $config = $this->getConfig();
        $primaryKey = $this->getPrimaryKey();
        $keyPath ??= $primaryKey;
        $valuePath ??= $this->collection->getDisplayField();
        $spacer ??= '_';

        return $query->formatResults(
            function (CollectionInterface $results) use ($config, $primaryKey, $keyPath, $valuePath, $spacer): array {
                $rows = [];
                foreach ($results as $row) {
                    $parent = $row[$config['parent']] ?? null;
                    $rows[(string)$row[$primaryKey]] = [
                        'row' => $row,
                        'parent' => $parent === null ? null : (string)$parent,
                    ];
                }

                $levels = [];
                $levelOf = function (string $id) use (&$levelOf, &$levels, $rows): int {
                    if (isset($levels[$id])) {
                        return $levels[$id];
                    }

                    if (!isset($rows[$id])) {
                        return 0;
                    }

                    $parent = $rows[$id]['parent'];
                    if ($parent === null) {
                        return $levels[$id] = 0;
                    }

                    return $levels[$id] = $levelOf($parent) + 1;
                };

                $result = [];
                foreach ($rows as $id => $info) {
                    $row = $info['row'];
                    $value = $valuePath instanceof Closure ? $valuePath($row) : $row[$valuePath];
                    $key = $keyPath instanceof Closure ? $keyPath($row) : $row[$keyPath];
                    $result[$key] = str_repeat($spacer, $levelOf($id)) . $value;
                }

                return $result;
            },
        );
    }

    /**
     * Gets the number of children of a node.
     *
     * @param \Cake\Datasource\EntityInterface $node The node to count children for.
     * @param bool $direct Whether to count only the direct children or the whole subtree.
     * @return int Number of children.
     */
    public function childCount(EntityInterface $node, bool $direct = false): int
    {
        $config = $this->getConfig();
        $primaryKey = $this->getPrimaryKey();

        if ($direct) {
            return $this->scope($this->collection->find())
                ->where([$config['parent'] => $node->get($primaryKey)])
                ->count();
        }

        return $this->scope($this->collection->find())
            ->where([$config['ancestors'] => $node->get($primaryKey)])
            ->count();
    }

    /**
     * Removes the current node from the tree, positioning it as a new root and
     * re-parenting all children up one level.
     *
     * @param \Cake\Datasource\EntityInterface $node The node to remove from the tree.
     * @return \Cake\Datasource\EntityInterface|false The node after removal, or false on error.
     */
    public function removeFromTree(EntityInterface $node): EntityInterface|false
    {
        $config = $this->getConfig();
        $primaryKey = $this->getPrimaryKey();
        $nodeId = $node->get($primaryKey);

        $this->ensureFields($node);
        $oldAncestors = $node->get($config['ancestors']);
        $parentId = $node->get($config['parent']);
        $prefixCount = count($oldAncestors) + 1;

        $chain = [];
        if ($parentId) {
            $parentNode = $this->getNode($parentId);
            $chain = array_merge($parentNode->get($config['ancestors']), [$parentId]);
        }

        // Re-parent direct children to the grandparent.
        $this->collection->updateAll(
            [$config['parent'] => $parentId],
            [$config['parent'] => $nodeId],
        );

        // Every descendant drops the node from its ancestry chain.
        foreach ($this->scope($this->collection->find())->where([$config['ancestors'] => $nodeId])->all() as $descendant) {
            $descAncestors = $descendant->get($config['ancestors']);
            $new = array_merge($chain, array_slice($descAncestors, $prefixCount));
            $fields = [$config['ancestors'] => $new];
            if ($config['level']) {
                $fields[$config['level']] = count($new);
            }

            $this->collection->updateAll($fields, [$primaryKey => $descendant->get($primaryKey)]);
        }

        $node->set($config['parent']);
        $node->set($config['ancestors'], []);
        $this->setLevelAndSort($node, [], null);

        return $this->collection->save($node);
    }

    /**
     * Reorders the node up among its siblings, using the configured `sort`
     * field. `$number` can be `true` to move to the first position.
     *
     * @param \Cake\Datasource\EntityInterface $node The node to move.
     * @param int|true $number How many places to move up, or true for the first position.
     * @return \Cake\Datasource\EntityInterface|false The node after being moved, or false when `$number < 1`.
     */
    public function moveUp(EntityInterface $node, int|true $number = 1): EntityInterface|false
    {
        if ($number < 1) {
            return false;
        }

        return $this->move($node, $number === true ? PHP_INT_MAX : $number, true);
    }

    /**
     * Reorders the node down among its siblings, using the configured `sort`
     * field. `$number` can be `true` to move to the last position.
     *
     * @param \Cake\Datasource\EntityInterface $node The node to move.
     * @param int|true $number How many places to move down, or true for the last position.
     * @return \Cake\Datasource\EntityInterface|false The node after being moved, or false when `$number < 1`.
     */
    public function moveDown(EntityInterface $node, int|true $number = 1): EntityInterface|false
    {
        if ($number < 1) {
            return false;
        }

        return $this->move($node, $number === true ? PHP_INT_MAX : $number, false);
    }

    /**
     * Rebuilds the `ancestors` (and optional `level`) fields from the parent
     * references, walking the tree iteratively from its roots.
     *
     * @return void
     */
    public function recover(): void
    {
        $config = $this->getConfig();
        $primaryKey = $this->getPrimaryKey();
        $parent = $config['parent'];

        $query = $this->scope($this->collection->unhydratedSelectQuery())
            ->select([$primaryKey, $parent]);

        $nodes = [];
        foreach ($query->all() as $node) {
            $id = (string)$node[$primaryKey];
            $parentId = $node[$parent] ?? null;
            $nodes[$id] = $parentId === null ? null : (string)$parentId;
        }

        $children = [];
        foreach ($nodes as $id => $parentId) {
            if ($parentId !== null && array_key_exists($parentId, $nodes)) {
                $children[$parentId][] = $id;
            }
        }

        $stack = [];
        foreach ($nodes as $id => $parentId) {
            if ($parentId === null || !array_key_exists($parentId, $nodes)) {
                $stack[] = [$id, []];
            }
        }

        while ($stack !== []) {
            [$id, $ancestors] = array_shift($stack);
            $fields = [$config['ancestors'] => $ancestors];
            if ($config['level']) {
                $fields[$config['level']] = count($ancestors);
            }

            $this->collection->updateAll($fields, [$primaryKey => $id]);

            foreach ($children[$id] ?? [] as $childId) {
                $stack[] = [$childId, array_merge($ancestors, [$id])];
            }
        }
    }

    /**
     * Returns the depth level of a node in the tree.
     *
     * @param \Cake\Datasource\EntityInterface|string|int $entity The entity or primary key to get the level of.
     * @return int|false Integer level, or false when the node does not exist.
     */
    public function getLevel(EntityInterface|string|int $entity): int|false
    {
        $config = $this->getConfig();
        $primaryKey = $this->getPrimaryKey();
        $id = $entity instanceof EntityInterface ? $entity->get($primaryKey) : $entity;

        $document = $this->scope($this->collection->find())
            ->where([$primaryKey => $id])
            ->first();
        if ($document === null) {
            return false;
        }

        return count($document->get($config['ancestors']));
    }

    /**
     * Re-parents an existing node and its whole subtree.
     *
     * @param \Cake\Datasource\EntityInterface $document The document being saved.
     * @param mixed $parent The new parent id (or null to root the node).
     * @return void
     * @throws \Cake\Database\Exception\DatabaseException When the parent would create a cycle.
     */
    protected function setParent(EntityInterface $document, mixed $parent): void
    {
        $config = $this->getConfig();
        $primaryKey = $this->getPrimaryKey();
        $nodeId = $document->get($primaryKey);

        $this->ensureFields($document);
        $oldAncestors = $document->get($config['ancestors']);
        $prefixCount = count($oldAncestors) + 1;

        if ($parent) {
            $this->assertValidParent($nodeId, $parent);
            $parentNode = $this->getNode($parent);
            $newAncestors = array_merge($parentNode->get($config['ancestors']), [$parent]);
        } else {
            $newAncestors = [];
        }

        foreach ($this->scope($this->collection->find())->where([$config['ancestors'] => $nodeId])->all() as $descendant) {
            $descAncestors = $descendant->get($config['ancestors']);
            $new = array_merge($newAncestors, [$nodeId], array_slice($descAncestors, $prefixCount));
            $fields = [$config['ancestors'] => $new];
            if ($config['level']) {
                $fields[$config['level']] = count($new);
            }

            $this->collection->updateAll($fields, [$primaryKey => $descendant->get($primaryKey)]);
        }

        $document->set($config['ancestors'], $newAncestors);
        $this->setLevelAndSort($document, $newAncestors, $parent);
    }

    /**
     * Guards against creating a cycle by moving a node under one of its own
     * descendants.
     *
     * @param mixed $nodeId The id of the node being moved.
     * @param mixed $parent The proposed new parent id.
     * @return void
     * @throws \Cake\Database\Exception\DatabaseException When the parent is inside the node's subtree.
     */
    protected function assertValidParent(mixed $nodeId, mixed $parent): void
    {
        $config = $this->getConfig();
        $parentNode = $this->getNode($parent);
        if (in_array($nodeId, $parentNode->get($config['ancestors']), true)) {
            throw new DatabaseException(sprintf(
                'Cannot use node `%s` as parent for entity `%s`.',
                $parent,
                $nodeId,
            ));
        }
    }

    /**
     * Sets the optional `level` and `sort` fields for a node being created or
     * moved.
     *
     * @param \Cake\Datasource\EntityInterface $document The document being saved.
     * @param array<int, mixed> $ancestors The node's ancestor chain.
     * @param mixed $parent The parent id (null for roots).
     * @return void
     */
    protected function setLevelAndSort(EntityInterface $document, array $ancestors, mixed $parent): void
    {
        $config = $this->getConfig();
        if ($config['level']) {
            $document->set($config['level'], count($ancestors));
        }

        if ($config['sort']) {
            $document->set($config['sort'], $this->nextSort($parent));
        }
    }

    /**
     * Returns the next `sort` value for a new node among its siblings.
     *
     * @param mixed $parentId The parent id (null to compute among roots).
     * @return int
     */
    protected function nextSort(mixed $parentId): int
    {
        $config = $this->getConfig();
        $sort = $config['sort'];

        $query = $this->scope($this->collection->find())
            ->where([$config['parent'] => $parentId])
            ->orderByDesc($sort)
            ->limit(1)
            ->select([$sort]);

        $last = $query->first();
        if ($last === null) {
            return 0;
        }

        $value = $last->get($sort);

        return $value === null ? 0 : (int)$value + 1;
    }

    /**
     * Reorders a node among its siblings by rewriting the `sort` field.
     *
     * @param \Cake\Datasource\EntityInterface $node The node to move.
     * @param int $number How many places to move (PHP_INT_MAX for the far edge).
     * @param bool $up Whether to move up.
     * @return \Cake\Datasource\EntityInterface
     */
    protected function move(EntityInterface $node, int $number, bool $up): EntityInterface
    {
        $config = $this->getConfig();
        $primaryKey = $this->getPrimaryKey();
        $sort = $config['sort'];
        if (!$sort) {
            return $node;
        }

        $nodeId = $node->get($primaryKey);
        $this->ensureFields($node);
        $parentId = $node->get($config['parent']);

        $siblings = [];
        foreach (
            $this->scope($this->collection->find())
                ->where([$config['parent'] => $parentId])
                ->orderBy([$sort => 'ASC'])
                ->all() as $sibling
        ) {
            $siblings[] = $sibling->get($primaryKey);
        }

        $index = array_search((string)$nodeId, array_map(strval(...), $siblings), true);
        if ($index === false) {
            return $node;
        }

        $target = $up
            ? max(0, $index - $number)
            : min(count($siblings) - 1, $index + $number);
        if ($target === $index) {
            return $node;
        }

        $moved = array_splice($siblings, $index, 1);
        array_splice($siblings, $target, 0, $moved);

        foreach ($siblings as $position => $id) {
            $this->collection->updateAll([$sort => $position], [$primaryKey => $id]);
        }

        $node->set($sort, $target);

        return $node;
    }

    /**
     * Returns a single node from the tree by its primary key.
     *
     * @param mixed $id Record id.
     * @return \Cake\Datasource\EntityInterface
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When the node was not found.
     */
    protected function getNode(mixed $id): EntityInterface
    {
        $primaryKey = $this->getPrimaryKey();
        $node = $this->scope($this->collection->find())
            ->where([$primaryKey => $id])
            ->first();

        if (!$node instanceof EntityInterface) {
            throw new RecordNotFoundException(sprintf('Node `%s` was not found in the tree.', $id));
        }

        return $node;
    }

    /**
     * Ensures the document carries the ancestry fields, reloading them from the
     * collection when they are missing.
     *
     * @param \Cake\Datasource\EntityInterface $document The document to ensure fields for.
     * @return void
     */
    protected function ensureFields(EntityInterface $document): void
    {
        $config = $this->getConfig();
        $fields = [$config['ancestors']];
        if (count(array_filter($document->extract($fields))) === count($fields)) {
            return;
        }

        $fresh = $this->collection->get($document->get($this->getPrimaryKey()));
        $document->patch($fresh->extract($fields), ['guard' => false]);

        foreach ($fields as $field) {
            $document->setDirty($field, false);
        }
    }

    /**
     * Returns the primary key of the attached collection.
     *
     * @return string
     */
    protected function getPrimaryKey(): string
    {
        if ($this->primaryKey === '') {
            $primaryKey = (array)$this->collection->getPrimaryKey();
            $this->primaryKey = (string)($primaryKey[0] ?? '_id');
        }

        return $this->primaryKey;
    }

    /**
     * Alters the passed query so it only returns scoped records.
     *
     * @template TQuery of \Crustum\Mongo\ODM\Query\SelectQuery|\Crustum\Mongo\ODM\Query\UpdateQuery|\Crustum\Mongo\ODM\Query\DeleteQuery
     * @param TQuery $query The query to modify.
     * @return TQuery
     */
    protected function scope(SelectQuery|UpdateQuery|DeleteQuery $query): SelectQuery|UpdateQuery|DeleteQuery
    {
        $scope = $this->getConfig('scope');
        if ($scope === null) {
            return $query;
        }

        return $query->where($scope);
    }

    /**
     * Reorders path rows so they follow the root-to-node id order.
     *
     * @param \Cake\Collection\CollectionInterface<int, mixed> $results The query results.
     * @param array<int, mixed> $ids The ancestor ids plus the node id, root-first.
     * @param string $primaryKey The primary key field.
     * @return array<int, mixed>
     */
    protected function orderPathRows(CollectionInterface $results, array $ids, string $primaryKey): array
    {
        $byId = [];
        foreach ($results as $row) {
            $byId[(string)$row[$primaryKey]] = $row;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[(string)$id])) {
                $ordered[] = $byId[(string)$id];
            }
        }

        return $ordered;
    }
}
