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

    /** @return array<string, \Crustum\Mongo\ODM\EagerLoadable> */
    public function normalized(object $repository): array
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
     * @param object $repository The source collection.
     * @return void
     */
    public function attachAssociations(SelectQuery $query, object $repository): void
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
     * Gets a flattened association map for result nesting.
     *
     * @param object $repository The source collection.
     * @return array<int, array<string, mixed>>
     */
    public function associationsMap(object $repository): array
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
     * @param array<int|string, mixed> $contain
     * @param array<int|string, mixed> $original
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
     * @param array<int|string, mixed> $options
     * @param array<int|string, mixed> $existing
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

    /** @param array<string, mixed> $options */
    private function normalize(object $repository, string $alias, array $options, string $aliasPath, string $propertyPath): EagerLoadable
    {
        $association = $this->association($repository, $alias);
        $target = $this->target($association);
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

    /** Resolve an association from the C1 repository surface. */
    private function association(object $repository, string $alias): object
    {
        $association = null;
        if (method_exists($repository, 'getAssociation')) {
            $association = $repository->getAssociation($alias);
        } elseif (method_exists($repository, 'associations')) {
            $associations = $repository->associations();
            if (is_object($associations) && method_exists($associations, 'get')) {
                $association = $associations->get($alias);
            }
        }

        if (!is_object($association)) {
            throw new InvalidArgumentException(sprintf('Association `%s` not found.', $alias));
        }

        return $association;
    }

    /** Resolve the target repository from the C5 association surface. */
    private function target(object $association): object
    {
        $target = method_exists($association, 'getTarget') ? $association->getTarget() : null;
        if (!is_object($target)) {
            throw new InvalidArgumentException('Association target is not configured.');
        }

        return $target;
    }

    /**
     * Gets the default strategy for an association.
     *
     * @param object $association The association.
     * @return string
     */
    private function defaultStrategy(object $association): string
    {
        if (method_exists($association, 'getStrategy')) {
            return $association->getStrategy() === 'embed' ? 'embed' : 'select';
        }

        return method_exists($association, 'type') && $association->type() === 'embed' ? 'embed' : 'select';
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function applyQueryBuilder(array $config, object $target): array
    {
        if (!isset($config['queryBuilder']) || !is_callable($config['queryBuilder']) || !method_exists($target, 'query')) {
            return $config;
        }

        $query = $target->query();
        ($config['queryBuilder'])($query);
        unset($config['queryBuilder']);
        if (is_object($query) && method_exists($query, 'compile')) {
            $compiled = $query->compile();
            $config['conditions'] ??= $compiled['filter'] ?? [];
            $config['fields'] ??= array_keys($compiled['options']['projection'] ?? []);
            $config['sort'] ??= $compiled['options']['sort'] ?? [];
        }

        return $config;
    }

    /** Dispatch one normalized node and all of its descendants. */
    private function dispatch(EagerLoadable $loadable, SelectQuery $query): void
    {
        $association = $loadable->instance();
        if ($association === null) {
            return;
        }

        $strategy = $loadable->getConfig()['strategy'];
        if ($strategy === 'select' || $strategy === 'reference') {
            $this->external[] = $loadable;
        } elseif (method_exists($association, 'buildPipeline')) {
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
     * @param array<string, \Crustum\Mongo\ODM\EagerLoadable> $loadables
     * @param array<int, array<string, mixed>> $map
     */
    private function map(array $loadables, array &$map): void
    {
        foreach ($loadables as $loadable) {
            $map[] = ['alias' => $loadable->name(), 'aliasPath' => $loadable->aliasPath(), 'propertyPath' => $loadable->propertyPath(), 'strategy' => $loadable->getConfig()['strategy']];
            $this->map($loadable->associations(), $map);
        }
    }
}
