<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use Crustum\Mongo\Database\Query\SelectQuery;
use InvalidArgumentException;

/**
 * Stores and normalizes associations to be eagerly loaded.
 *
 * MongoDB associations use embedded, external select, and aggregation lookup
 * strategies.
 *
 * @see cake60/src/ORM/EagerLoader.php
 * @see src/ODM/EagerLoader.php
 */
final class EagerLoader
{
    /**
     * User-provided containment configuration.
     *
     * @var array<int|string, mixed>
     */
    private array $containments = [];

    /**
     * Cached normalized containment tree.
     *
     * @var array<string, \Crustum\Mongo\ODM\EagerLoadable>|null
     */
    private ?array $normalized = null;

    /**
     * Associations that require external queries.
     *
     * @var list<\Crustum\Mongo\ODM\EagerLoadable>
     */
    private array $external = [];

    /**
     * Options accepted by association containment configuration.
     *
     * @var array<string, true>
     */
    private array $containOptions = [
        'strategy' => true,
        'fields' => true,
        'conditions' => true,
        'sort' => true,
        'matching' => true,
        'queryBuilder' => true,
        'foreignKey' => true,
        'limit' => true,
        'skip' => true,
    ];

    /**
     * Sets the associations to eagerly load.
     *
     * @param array<int|string, mixed>|string $associations Association aliases and options.
     * @param callable|null $queryBuilder Optional target query callback.
     * @return array<int|string, mixed>
     */
    public function contain(array|string $associations, ?callable $queryBuilder = null): array
    {
        if ($queryBuilder !== null) {
            if (!is_string($associations)) {
                throw new InvalidArgumentException('queryBuilder requires a string association.');
            }

            $associations = [$associations => ['queryBuilder' => $queryBuilder]];
        }

        $this->containments = $this->reformat((array)$associations, $this->containments);
        $this->normalized = null;
        $this->external = [];

        return $this->containments;
    }

    /**
     * Gets the configured containments.
     *
     * @return array<int|string, mixed>
     */
    public function getContain(): array
    {
        return $this->containments;
    }

    /**
     * Gets the normalized containment tree for a repository.
     *
     * @param \Crustum\Mongo\ODM\Collection $repository The source collection.
     * @return array<string, \Crustum\Mongo\ODM\EagerLoadable>
     */
    public function normalized(Collection $repository): array
    {
        if ($this->normalized !== null) {
            return $this->normalized;
        }

        $this->normalized = [];
        foreach ($this->containments as $alias => $options) {
            $alias = (string)$alias;
            $this->normalized[$alias] = $this->normalize($repository, $alias, $options, $alias, strtolower($alias));
        }

        return $this->normalized;
    }

    /**
     * Attaches in-pipeline strategies and records external strategies.
     *
     * @param \Crustum\Mongo\Database\Query\SelectQuery $query The source query.
     * @param \Crustum\Mongo\ODM\Collection $repository The source collection.
     * @return void
     */
    public function attachAssociations(SelectQuery $query, Collection $repository): void
    {
        $this->external = [];
        foreach ($this->normalized($repository) as $loadable) {
            $this->dispatch($loadable, $query);
        }
    }

    /**
     * Gets associations that require external queries.
     *
     * @return list<\Crustum\Mongo\ODM\EagerLoadable>
     */
    public function getExternalAssociations(): array
    {
        return $this->external;
    }

    /**
     * Loads external (referenced) association results and merges them into the
     * result documents.
     *
     * Each external association runs one batched query through its loader and
     * injects the fetched rows into the matching source documents under the
     * association property. Embedded and lookup associations are handled
     * during pipeline construction and produce no external load.
     *
     * @param \Crustum\Mongo\Database\Query\SelectQuery $query   The executed source query.
     * @param iterable<array-key, mixed>                $results The hydrated result documents.
     * @return iterable<array-key, mixed>
     */
    public function loadExternal(SelectQuery $query, iterable $results): iterable
    {
        if (!$results) {
            return $results;
        }

        $external = $this->getExternalAssociations();
        if ($external === []) {
            return $results;
        }

        if (!is_array($results)) {
            $results = iterator_to_array($results);
        }

        foreach ($external as $loadable) {
            $instance = $loadable->instance();
            if ($instance === null) {
                continue;
            }

            $callback = $instance->eagerLoader($loadable->getConfig() + [
                'query' => $query,
                'contain' => $loadable->associations(),
            ]);
            $results = $callback($results);
        }

        return $results;
    }

    /**
     * Gets a flattened association map for result nesting.
     *
     * @param \Crustum\Mongo\ODM\Collection $repository The source collection.
     * @return array<int, array<string, mixed>>
     */
    public function associationsMap(Collection $repository): array
    {
        $map = [];
        $this->map($this->normalized($repository), $map);

        return $map;
    }

    /**
     * Clears all configured containment state.
     *
     * @return void
     */
    public function clearContain(): void
    {
        $this->containments = [];
        $this->normalized = null;
        $this->external = [];
    }

    /**
     * Resets derived caches when the loader is cloned.
     *
     * The containment configuration is immutable config and is safe to copy by
     * value; the normalized tree and external list are re-derived lazily so a
     * cloned query never shares mutable normalization state.
     *
     * @return void
     */
    public function __clone()
    {
        $this->normalized = null;
        $this->external = [];
    }

    /**
     * Merges containment configuration into the existing tree.
     *
     * @param array<int|string, mixed> $contain The new containments.
     * @param array<int|string, mixed> $original The existing containments.
     * @return array<int|string, mixed>
     */
    private function reformat(array $contain, array $original): array
    {
        $result = $original;
        foreach ($contain as $key => $value) {
            if (is_int($key)) {
                $key = (string)$value;
                $value = [];
            }

            $path = explode('.', $key);
            $leaf = array_pop($path);
            $pointer =& $result;
            foreach ($path as $part) {
                $pointer[$part] ??= [];
                $pointer =& $pointer[$part];
            }

            if (is_callable($value)) {
                $value = ['queryBuilder' => $value];
            } elseif (!is_array($value)) {
                $value = [];
            }

            $pointer[$leaf] = $this->reformatOptions($value, $pointer[$leaf] ?? []);
        }

        return $result;
    }

    /**
     * Normalizes a containment options array against the accepted options.
     *
     * @param array<int|string, mixed> $options The options to normalize.
     * @param array<int|string, mixed> $existing The existing normalized options.
     * @return array<string, mixed>
     */
    private function reformatOptions(array $options, array $existing): array
    {
        $result = [];
        foreach ($existing as $key => $value) {
            $result[(string)$key] = $value;
        }

        foreach ($options as $key => $value) {
            if (is_int($key)) {
                $result[(string)$value] = [];
            } elseif (isset($this->containOptions[$key])) {
                $result[$key] = $value;
            } elseif (is_array($value)) {
                $result[$key] = $this->reformatOptions($value, $result[$key] ?? []);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Normalizes one containment node and its nested associations.
     *
     * @param array<string, mixed> $options The containment options.
     * @param \Crustum\Mongo\ODM\Collection $repository The parent repository.
     * @param string $alias The association alias.
     * @param string $aliasPath The dotted alias path.
     * @param string $propertyPath The dotted property path.
     * @return \Crustum\Mongo\ODM\EagerLoadable
     */
    private function normalize(Collection $repository, string $alias, array $options, string $aliasPath, string $propertyPath): EagerLoadable
    {
        $association = $repository->getAssociation($alias);
        if ($association === null) {
            throw new InvalidArgumentException(sprintf('Association `%s` not found.', $alias));
        }

        $target = $association->getTarget();
        $config = array_intersect_key($options, $this->containOptions);
        $config += ['strategy' => $this->defaultStrategy($association)];
        $config = $this->applyQueryBuilder($config, $target);
        $loadable = new EagerLoadable($alias, $association, $config, $aliasPath, $propertyPath, false, $config['matching'] ?? null, $alias);

        foreach ($options as $nestedAlias => $nestedOptions) {
            if (!isset($this->containOptions[$nestedAlias])) {
                $nestedOptions = is_array($nestedOptions) ? $nestedOptions : [];
                $loadable->addAssociation(
                    $nestedAlias,
                    $this->normalize($target, $nestedAlias, $nestedOptions, $aliasPath . '.' . $nestedAlias, $propertyPath . '.' . strtolower($nestedAlias)),
                );
            }
        }

        return $loadable;
    }

    /**
     * Gets the default strategy for an association.
     *
     * @param \Crustum\Mongo\ODM\Association $association The association.
     * @return string
     */
    private function defaultStrategy(Association $association): string
    {
        return $association->getStrategy() === 'embed' ? 'embed' : 'select';
    }

    /**
     * Applies a containment query builder to the association config.
     *
     * @param array<string, mixed> $config The association config.
     * @param \Crustum\Mongo\ODM\Collection $target The target collection.
     * @return array<string, mixed>
     */
    private function applyQueryBuilder(array $config, Collection $target): array
    {
        if (!isset($config['queryBuilder']) || !is_callable($config['queryBuilder'])) {
            return $config;
        }

        $query = $target->query();
        ($config['queryBuilder'])($query);
        unset($config['queryBuilder']);
        if ($query instanceof SelectQuery) {
            $compiled = $query->compile();
            $config['conditions'] ??= $compiled['filter'] ?? [];
            $config['fields'] ??= array_keys($compiled['options']['projection'] ?? []);
            $config['sort'] ??= $compiled['options']['sort'] ?? [];
        }

        return $config;
    }

    /**
     * Dispatches one normalized node and all of its descendants.
     *
     * @param \Crustum\Mongo\ODM\EagerLoadable $loadable The node to dispatch.
     * @param \Crustum\Mongo\Database\Query\SelectQuery $query The source query.
     * @return void
     */
    private function dispatch(EagerLoadable $loadable, SelectQuery $query): void
    {
        $association = $loadable->instance();
        if ($association === null) {
            return;
        }

        $strategy = $loadable->getConfig()['strategy'];
        if ($strategy === 'select' || $strategy === 'reference') {
            $this->external[] = $loadable;
        } else {
            $stages = $association->buildPipeline($loadable->getConfig());
            if ($stages !== []) {
                $query->pipeline($stages);
            }
        }

        foreach ($loadable->associations() as $nested) {
            $this->dispatch($nested, $query);
        }
    }

    /**
     * Flattens the normalized tree into an association map.
     *
     * @param array<string, \Crustum\Mongo\ODM\EagerLoadable> $loadables The nodes to flatten.
     * @param array<int, array<string, mixed>> $map The output map, filled by reference.
     * @return void
     */
    private function map(array $loadables, array &$map): void
    {
        foreach ($loadables as $loadable) {
            $map[] = ['alias' => $loadable->name(), 'aliasPath' => $loadable->aliasPath(), 'propertyPath' => $loadable->propertyPath(), 'strategy' => $loadable->getConfig()['strategy']];
            $this->map($loadable->associations(), $map);
        }
    }
}
