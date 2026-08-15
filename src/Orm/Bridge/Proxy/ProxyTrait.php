<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge\Proxy;

use Cake\Datasource\EntityInterface;
use Closure;
use Crustum\Mongo\Orm\Bridge\Association;

/**
 * Shared logic for the ORM-proxy associations (Direction 1).
 *
 * A proxy registers the cross-boundary bridge association on
 * `Table->associations()` so `contain()`, property access, and `__call`
 * forwarding behave like a native Cake association, while the heavy lifting
 * stays in the underlying `Crustum\Mongo\Orm\Bridge\Association`.
 *
 * - `saveAssociated()` is a no-op: Mongo writes are driven by
 *   `saveWithBridge()`, never by the ORM save chain.
 * - `eagerLoader()` returns a closure that loads the target in one batched
 *   Mongo query and injects results into the source rows (cake external-load
 *   contract: array rows with `{SourceAlias}__{field}` keys, value stored
 *   under `nestKey`).
 * - `__call()` forwards unknown calls to the target Mongo collection.
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §4, §6, §7
 */
trait ProxyTrait
{
    /**
     * The wrapped bridge association.
     *
     * @var \Crustum\Mongo\Orm\Bridge\Association
     */
    protected Association $bridge;

    /**
     * Gets the wrapped bridge association.
     *
     * @return \Crustum\Mongo\Orm\Bridge\Association
     */
    public function getBridge(): Association
    {
        return $this->bridge;
    }

    /**
     * No-op: Mongo writes are managed by `saveWithBridge()`, not the ORM save chain.
     *
     * @param \Cake\Datasource\EntityInterface $entity The source entity.
     * @param array<string, mixed> $options Save options.
     * @return \Cake\Datasource\EntityInterface
     */
    public function saveAssociated(EntityInterface $entity, array $options = []): EntityInterface
    {
        return $entity;
    }

    /**
     * Always load externally: the bridge target is a Mongo collection, never a
     * SQL join.
     *
     * @param array<string, mixed> $options Options.
     * @return bool
     */
    public function canBeJoined(array $options = []): bool
    {
        return false;
    }

    /**
     * The bridge always needs the source keys for its batched whereIn.
     *
     * @param array<string, mixed> $options Options.
     * @return bool
     */
    public function requiresKeys(array $options = []): bool
    {
        return true;
    }

    /**
     * Returns a closure that eager-loads the association in one batched query.
     *
     * The closure is applied per result row (cake EagerLoader contract): it
     * extracts the source key from the aliased row, looks it up in the bridge's
     * batched map, and stores the result under `nestKey` (ResultSetFactory
     * later moves it to the association property via `transformRow`).
     *
     * @param array<string, mixed> $options Eager-load options.
     * @return \Closure
     */
    public function eagerLoader(array $options): Closure
    {
        $bridge = $this->bridge;
        $keys = $options['keys'] ?? [];
        $map = $keys !== [] ? $bridge->loadByKeys(array_values($keys)) : [];
        $sourceAlias = $this->getSource()->getAlias();
        $sourceKey = $bridge->getSourceKeyField();
        $empty = $bridge->getEmptyValue();
        $nestKey = (string)($options['nestKey'] ?? $this->getName());

        return function (array $row) use ($map, $sourceAlias, $sourceKey, $empty, $nestKey): array {
            $value = $row[$sourceAlias . '__' . $sourceKey] ?? null;
            $row[$nestKey] = $value !== null ? ($map[(string)$value] ?? $empty) : $empty;

            return $row;
        };
    }

    /**
     * Proxies method calls to the target Mongo collection.
     *
     * @param string $method Method name.
     * @param array<int, mixed> $arguments Method arguments.
     * @return mixed
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->bridge->getTarget()->{$method}(...$arguments);
    }

    /**
     * Injects the bridge association instance into the proxy.
     *
     * @param \Crustum\Mongo\Orm\Bridge\Association $bridge Bridge association.
     * @return void
     */
    protected function setBridge(Association $bridge): void
    {
        $this->bridge = $bridge;
    }
}
