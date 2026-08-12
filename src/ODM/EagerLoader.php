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
class EagerLoader
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
     * Whether in-pipeline stages have been attached to the source query.
     *
     * Set by {@see attachAssociations()} so repeated attaches (e.g. a `count()`
     * on an already-executed query) never re-append `$lookup` / `$unwind`
     * stages.
     *
     * @var bool
     */
    private bool $pipelineAttached = false;

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
        'negateMatch' => true,
        'joinType' => true,
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
     * Adds a new association to the list that will be used to filter the results
     * of any given query based on the results of finding records for that
     * association. A dot separated path of associations can be passed, which
     * translates to setting all those associations with the `matching` option.
     *
     * ### Options
     *
     * - `negateMatch`: Whether to add conditions negating a match on the target association.
     * - `fields`: Fields to contain.
     *
     * @param string $associationPath Dot separated association path, e.g. `Name1.Name2.Name3`.
     * @param callable|null $builder Callback used to set extra options on the filtering query.
     * @param array<string, mixed> $options Extra options for the association matching.
     * @return $this
     */
    public function setMatching(string $associationPath, ?callable $builder = null, array $options = []): static
    {
        $sharedOptions = ['negateMatch' => false, 'matching' => true] + $options;

        $contains = [];
        $nested = &$contains;
        foreach (explode('.', $associationPath) as $association) {
            $nested[$association] = $sharedOptions;
            $nested = &$nested[$association];
        }

        $nested = ['matching' => true, 'queryBuilder' => $builder ?? fn($q) => $q] + $options;
        $this->contain($contains);

        return $this;
    }

    /**
     * Returns the current tree of associations to be matched.
     *
     * @return array<int|string, mixed>
     */
    public function getMatching(): array
    {
        $matching = [];
        foreach ($this->containments as $alias => $options) {
            $this->collectMatching((string)$alias, $options, $matching);
        }

        return $matching;
    }

    /**
     * Collects matching associations from the containment tree.
     *
     * @param string $alias The association alias.
     * @param array<int|string, mixed> $options The association options.
     * @param array<int|string, mixed> $output The output tree, filled by reference.
     * @return void
     */
    private function collectMatching(string $alias, array $options, array &$output): void
    {
        if (($options['matching'] ?? false) === true) {
            $output[$alias] = $options;
        }

        foreach ($options as $nestedAlias => $nestedOptions) {
            if (is_string($nestedAlias) && is_array($nestedOptions)) {
                $this->collectMatching($nestedAlias, $nestedOptions, $output);
            }
        }
    }

    /**
     * Gets the normalized containment tree for a repository.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $repository The source collection.
     * @return array<string, \Crustum\Mongo\ODM\EagerLoadable>
     */
    public function normalized(BaseCollection $repository): array
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
     * Whether the query carries in-pipeline eager loads that affect the row set.
     *
     * `matching()` always joins through the pipeline (lookup + unwind), so a
     * `count()` must aggregate rather than `countDocuments($filter)`. Regular
     * `contain()` on `select`/`reference` strategies loads externally and does
     * not change the row count.
     *
     * @return bool
     */
    public function hasInPipelineLoads(): bool
    {
        if ($this->getMatching() !== []) {
            return true;
        }

        foreach ($this->containments as $options) {
            if (is_array($options) && ($options['matching'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Attaches in-pipeline strategies and records external strategies.
     *
     * @param \Crustum\Mongo\Database\Query\SelectQuery $query The source query.
     * @param \Crustum\Mongo\ODM\BaseCollection $repository The source collection.
     * @return void
     */
    public function attachAssociations(SelectQuery $query, BaseCollection $repository): void
    {
        $this->external = [];
        if ($this->pipelineAttached) {
            return;
        }

        foreach ($this->normalized($repository) as $loadable) {
            $this->dispatch($loadable, $query);
        }

        $this->ensureKeyFieldsSelected($query, $repository);
        $this->pipelineAttached = true;
    }

    /**
     * Whether in-pipeline eager stages have already been attached.
     *
     * @return bool
     */
    public function isPipelineAttached(): bool
    {
        return $this->pipelineAttached;
    }

    /**
     * Ensures belongsTo foreign keys are present in the projection.
     *
     * A `belongsTo` association reads its key from the source row, so an
     * explicit `select()` that omits the foreign key would break eager
     * loading. Mirroring cake's auto-fields behaviour, the foreign key is
     * appended for `belongsTo` associations. HasMany/HasOne read their key
     * from the source primary key (`_id`), which cake requires to be selected
     * explicitly — omitting it is a real "Unable to load" error.
     *
     * @param \Crustum\Mongo\Database\Query\SelectQuery $query The source query.
     * @param \Crustum\Mongo\ODM\BaseCollection $repository The source collection.
     * @return void
     */
    protected function ensureKeyFieldsSelected(SelectQuery $query, BaseCollection $repository): void
    {
        $projection = $query->clause('select');
        if ($projection === [] || $this->external === []) {
            return;
        }

        $alias = $repository->getAlias();
        foreach ($this->external as $loadable) {
            $instance = $loadable->instance();
            if ($instance === null) {
                continue;
            }

            if ($instance->type() !== Association::MANY_TO_ONE) {
                continue;
            }

            $key = $instance->getForeignKey();
            if (is_array($key)) {
                $key = $key[0] ?? null;
            }

            if ($key === null) {
                continue;
            }

            if ($key === false) {
                continue;
            }

            if (array_key_exists($key, $projection)) {
                continue;
            }

            $query->select([$alias . '.' . $key]);
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

            // cake60: a non-nested association whose binding/foreign key is
            // missing from the selected source fields cannot be eager loaded.
            // belongsTo reads its FK from the source document; an absent or
            // null value there is a classic optional association → skip it and
            // leave the property null. HasMany/HasOne read the source binding
            // key (`_id`) which cake requires to be selected → throw.
            $aliasPath = $loadable->aliasPath();
            if (!str_contains($aliasPath, '.') && $instance->requiresKeys($loadable->getConfig())) {
                $source = $instance->getSource();
                $isManyToOne = $instance->type() === Association::MANY_TO_ONE;
                $keyField = $isManyToOne
                    ? $instance->getForeignKey()
                    : $instance->getBindingKey();
                $keyField = is_array($keyField) ? ($keyField[0] ?? null) : $keyField;
                if ($keyField !== null && $keyField !== false) {
                    if ($isManyToOne) {
                        continue;
                    }

                    $found = false;
                    foreach ($results as $result) {
                        if ($result instanceof Document && $result->has($keyField)) {
                            $found = true;
                            break;
                        }
                    }

                    if (!$found) {
                        $message = "Unable to load `{$aliasPath}` association. Ensure foreign key in `{$source->getAlias()}` is selected.";
                        throw new InvalidArgumentException($message);
                    }
                }
            }

            $callback = $instance->eagerLoader($loadable->getConfig() + [
                'query' => $query,
                'contain' => $loadable->associations(),
                'sourcePath' => $loadable->propertyPath(),
            ]);
            $results = $callback($results);
        }

        return $results;
    }

    /**
     * Gets a flattened association map for result nesting.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $repository The source collection.
     * @return array<int, array<string, mixed>>
     */
    public function associationsMap(BaseCollection $repository): array
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
     * @param \Crustum\Mongo\ODM\BaseCollection $repository The parent repository.
     * @param string $alias The association alias.
     * @param string $aliasPath The dotted alias path.
     * @param string $propertyPath The dotted property path.
     * @return \Crustum\Mongo\ODM\EagerLoadable
     */
    private function normalize(BaseCollection $repository, string $alias, array $options, string $aliasPath, string $propertyPath): EagerLoadable
    {
        $association = $repository->getAssociation($alias);
        if (!$association instanceof Association) {
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
                $nestedAssociation = $target->getAssociation($nestedAlias);
                $nestedProperty = $nestedAssociation instanceof Association
                    ? $nestedAssociation->getProperty()
                    : strtolower((string)$nestedAlias);
                $loadable->addAssociation(
                    $nestedAlias,
                    $this->normalize($target, $nestedAlias, $nestedOptions, $aliasPath . '.' . $nestedAlias, $propertyPath . '.' . $nestedProperty),
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
        $strategy = $association->getStrategy();
        if ($strategy === Association::STRATEGY_EMBED || $strategy === Association::STRATEGY_LOOKUP) {
            return $strategy;
        }

        return Association::STRATEGY_SELECT;
    }

    /**
     * Applies a containment query builder to the association config.
     *
     * @param array<string, mixed> $config The association config.
     * @param \Crustum\Mongo\ODM\BaseCollection $target The target collection.
     * @return array<string, mixed>
     */
    private function applyQueryBuilder(array $config, BaseCollection $target): array
    {
        if (!isset($config['queryBuilder']) || !is_callable($config['queryBuilder'])) {
            return $config;
        }

        $query = $target->query();
        ($config['queryBuilder'])($query);
        $compiled = $query->compile();
        $config['conditions'] ??= $compiled['filter'] ?? [];
        $config['fields'] ??= array_keys($compiled['options']['projection'] ?? []);
        $config['sort'] ??= $compiled['options']['sort'] ?? [];

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
        if (!$association instanceof Association) {
            return;
        }

        $strategy = $loadable->getConfig()['strategy'];
        $matching = (bool)($loadable->getConfig()['matching'] ?? false);
        if (!$matching && ($strategy === 'select' || $strategy === 'reference')) {
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
            $map[] = [
                'alias' => $loadable->name(),
                'aliasPath' => $loadable->aliasPath(),
                'propertyPath' => $loadable->propertyPath(),
                'strategy' => $loadable->getConfig()['strategy'],
                'instance' => $loadable->instance(),
                'config' => $loadable->getConfig(),
                'nestKey' => $loadable->aliasPath(),
                'matching' => (bool)($loadable->getConfig()['matching'] ?? false),
            ];
            $this->map($loadable->associations(), $map);
        }
    }
}
