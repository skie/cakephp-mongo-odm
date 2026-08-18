<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use Cake\Database\Exception\DatabaseException;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\QueryInterface;
use Crustum\Mongo\ODM\Association\BelongsToMany;
use Crustum\Mongo\ODM\Association\HasMany;
use Crustum\Mongo\ODM\Query\SelectQuery;
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
     * Matching loader (stored separately from containments, as in cake60).
     *
     * `matching()` / `notMatching()` filter the result rows through the lookup
     * pipeline and land in `_matchingData`; they are a distinct concern from
     * containment and survive `clearContain()`.
     *
     * @var \Crustum\Mongo\ODM\EagerLoader|null
     */
    private ?EagerLoader $matching = null;

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
     * Aggregation stages added to the query by this loader.
     *
     * @var list<array<string, mixed>>
     */
    private array $attachedPipeline = [];

    /**
     * Alias paths whose in-pipeline lookup stages were already attached.
     *
     * @var array<string, true>
     */
    private array $attachedLookupPaths = [];

    /**
     * Alias paths already queued for external loading.
     *
     * @var array<string, true>
     */
    private array $dispatchedExternalPaths = [];

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
        'finder' => true,
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
        $this->matching ??= new static();
        // `$options` first so an explicit `negateMatch`/`joinType`/`fields`
        // from `notMatching()`/`joinWith()` wins over the defaults.
        $sharedOptions = $options + ['negateMatch' => false, 'matching' => true];

        $contains = [];
        $nested = &$contains;
        foreach (explode('.', $associationPath) as $association) {
            $nested[$association] = $sharedOptions;
            $nested = &$nested[$association];
        }

        $nested = ['matching' => true, 'queryBuilder' => $builder ?? fn($q) => $q] + $options;
        $this->matching->contain($contains);

        return $this;
    }

    /**
     * Returns the current tree of associations to be matched.
     *
     * @return array<int|string, mixed>
     */
    public function getMatching(): array
    {
        return $this->matching instanceof EagerLoader ? $this->matching->getContain() : [];
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
            $this->normalized[$alias] = $this->normalize($repository, $alias, $options, $alias, '');
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
        return $this->getMatching() !== [];
    }

    /**
     * Attaches in-pipeline strategies and records external strategies.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $query The source query.
     * @param \Crustum\Mongo\ODM\BaseCollection $repository The source collection.
     * @return void
     */
    public function attachAssociations(SelectQuery $query, BaseCollection $repository): void
    {
        if ($this->pipelineAttached) {
            return;
        }

        $this->external = [];
        $this->attachedLookupPaths = [];
        $this->dispatchedExternalPaths = [];

        do {
            $before = $this->containments;
            $this->normalized = null;

            foreach ($this->normalized($repository) as $loadable) {
                $this->dispatch($loadable, $query);
            }

            if ($this->matching instanceof EagerLoader) {
                foreach ($this->matching->normalized($repository) as $loadable) {
                    $this->dispatch($loadable, $query);
                }
            }
        } while ($this->containments !== $before);

        $this->ensureKeyFieldsSelected($query, $repository);
        $this->ensureLookupPropertiesProjected($query, $repository);
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
     * Returns the aggregation stages this loader attached to a query.
     *
     * @return list<array<string, mixed>>
     */
    public function getAttachedPipeline(): array
    {
        return $this->attachedPipeline;
    }

    /**
     * Clears the tracked attached stages.
     *
     * @return void
     */
    public function clearAttachedPipeline(): void
    {
        $this->attachedPipeline = [];
        $this->pipelineAttached = false;
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
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $query The source query.
     * @param \Crustum\Mongo\ODM\BaseCollection $repository The source collection.
     * @return void
     */
    protected function ensureKeyFieldsSelected(SelectQuery $query, BaseCollection $repository): void
    {
        $projection = $query->clause('select');
        if ($projection === []) {
            return;
        }

        $needsId = false;
        foreach ($this->external as $loadable) {
            $instance = $loadable->instance();
            $type = $instance?->type();
            if (
                ($type === Association::ONE_TO_MANY || $type === Association::MANY_TO_MANY)
                && $instance->getStrategy() !== Association::STRATEGY_SELECT
            ) {
                $needsId = true;
                break;
            }
        }

        if (!$needsId) {
            foreach ($this->normalized($repository) as $loadable) {
                if (!empty($loadable->getConfig()['matching'])) {
                    continue;
                }

                $instance = $loadable->instance();
                if ($instance === null) {
                    continue;
                }

                if (!$instance->usesLookup($loadable->getConfig())) {
                    continue;
                }

                if (in_array($instance->type(), [Association::ONE_TO_ONE, Association::ONE_TO_MANY], true)) {
                    $needsId = true;
                    break;
                }
            }
        }

        if ($needsId && (int)($projection['_id'] ?? 1) === 0) {
            $query->select(['_id' => 1]);
        }

        if ($this->external === []) {
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
            $query->markAutoSelected($key);
        }
    }

    /**
     * Keeps in-pipeline lookup properties in a limited projection.
     *
     * Aggregation compiles `$project` after `$lookup`/`$unwind`, so an explicit
     * `select()` that omits contained properties would strip them from the raw
     * row before hydration — mirroring cake JOIN fields surviving a select list.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $query The source query.
     * @param \Crustum\Mongo\ODM\BaseCollection $repository The source collection.
     * @return void
     */
    protected function ensureLookupPropertiesProjected(SelectQuery $query, BaseCollection $repository): void
    {
        $projection = $query->clause('select');
        if ($projection === []) {
            return;
        }

        foreach ($this->normalized($repository) as $loadable) {
            if (!empty($loadable->getConfig()['matching'])) {
                continue;
            }

            $instance = $loadable->instance();
            if ($instance === null) {
                continue;
            }

            if (!$instance->usesLookup($loadable->getConfig())) {
                continue;
            }

            $property = $instance->getProperty();
            if (array_key_exists($property, $projection)) {
                continue;
            }

            $query->select([$property => 1]);
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
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $query   The executed source query.
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

            $aliasPath = $loadable->aliasPath();
            if (!str_contains($aliasPath, '.') && $instance->requiresKeys($loadable->getConfig())) {
                $source = $instance->getSource();
                $isManyToOne = $instance->type() === Association::MANY_TO_ONE;
                $keyField = $isManyToOne
                    ? $instance->getForeignKey()
                    : $instance->getBindingKey();
                $keyField = is_array($keyField) ? ($keyField[0] ?? null) : $keyField;
                if ($keyField !== null && $keyField !== false && !$isManyToOne) {
                    $found = false;
                    foreach ($results as $result) {
                        if ($result instanceof Document && $result->has($keyField)) {
                            $found = true;
                            break;
                        }

                        if (is_array($result) && array_key_exists($keyField, $result)) {
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

            $propertyPath = $loadable->propertyPath() ?? '';
            $sourcePath = str_contains($propertyPath, '.')
                ? implode('.', array_slice(explode('.', $propertyPath), 0, -1))
                : '';

            $callback = $instance->eagerLoader($loadable->getConfig() + [
                'query' => $query,
                'contain' => $loadable->associations(),
                'sourcePath' => $sourcePath,
            ]);
            $results = $callback($results);
        }

        $repository = $query->getRepository();
        if ($repository instanceof BaseCollection && $this->getMatching() === []) {
            foreach ($results as $key => $result) {
                if ($result instanceof Document) {
                    $this->reorderContainedProperties($result, $repository);
                    $results[$key] = $result;
                }
            }
        }

        return $results;
    }

    /**
     * Reorders top-level contained properties to match `contain()` order.
     *
     * In-pipeline `$lookup` associations are patched during `ResultSet` hydration;
     * external loaders attach later via `set()`, which would otherwise leave
     * lookup properties before select/subquery properties regardless of contain
     * order.
     *
     * @param \Cake\Datasource\EntityInterface $entity The hydrated root document.
     * @param \Crustum\Mongo\ODM\BaseCollection $repository The source collection.
     * @return void
     */
    private function reorderContainedProperties(EntityInterface $entity, BaseCollection $repository): void
    {
        if (!$entity instanceof Document) {
            return;
        }

        $propertyOrder = $this->topLevelContainedProperties($repository);
        if ($propertyOrder === []) {
            return;
        }

        $propertySet = array_flip($propertyOrder);
        $rootKeys = [];
        foreach (array_keys($entity->toArray()) as $key) {
            if ($key === '_matchingData') {
                continue;
            }

            if (isset($propertySet[$key])) {
                continue;
            }

            $rootKeys[] = $key;
        }

        $orderedKeys = array_merge($rootKeys, $propertyOrder);
        if ($entity->has('_matchingData')) {
            $orderedKeys[] = '_matchingData';
        }

        $entity->reorderFields($orderedKeys);
    }

    /**
     * Top-level association property names in normalized contain order.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $repository The source collection.
     * @return list<string>
     */
    private function topLevelContainedProperties(BaseCollection $repository): array
    {
        $properties = [];
        foreach (array_keys($this->getContain()) as $alias) {
            $alias = (string)$alias;
            if (str_contains($alias, '.')) {
                continue;
            }

            $association = $repository->getAssociation($alias);
            $properties[] = $association->getProperty();
        }

        return $properties;
    }

    /**
     * Gets a flattened association map for result nesting.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $repository The source collection.
     * @return list<array<string, mixed>>
     */
    public function associationsMap(BaseCollection $repository): array
    {
        $map = [];
        $this->map($this->normalized($repository), $map);
        if ($this->matching instanceof EagerLoader) {
            $this->map($this->matching->normalized($repository), $map);
        }

        return array_values($map);
    }

    /**
     * Returns the normalized attachable associations (contain + matching).
     *
     * ODM analog of cake60 `EagerLoader::attachableAssociations()`: the
     * normalized tree of associations the query can attach (in-pipeline lookup)
     * or load externally. Matching is folded into the same containment list.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $repository The collection.
     * @return array<string, \Crustum\Mongo\ODM\EagerLoadable>
     */
    public function attachableAssociations(BaseCollection $repository): array
    {
        $assocs = $this->normalized($repository);
        if ($this->matching instanceof EagerLoader) {
            foreach ($this->matching->normalized($repository) as $alias => $loadable) {
                $assocs[$alias] = $loadable;
            }
        }

        return $assocs;
    }

    /**
     * Clears all configured containment state but keeps matching joins.
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
        $this->matching = $this->matching instanceof EagerLoader ? clone $this->matching : null;
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

            if ($value instanceof EagerLoadable) {
                $asContain = $value->asContainArray();
                $key = (string)key($asContain);
                $value = current($asContain);
            }

            $path = explode('.', $key);
            $leaf = array_pop($path);
            $pointer =& $result;
            foreach ($path as $part) {
                $pointer[$part] ??= [];
                $pointer =& $pointer[$part];
            }

            if (is_array($value) && isset($value['config'], $value['associations'])) {
                $nested = $this->reformat($value['associations'], []);
                $value = $value['config'] + $nested;
            }

            if (is_callable($value)) {
                $value = ['queryBuilder' => $value];
            } elseif (is_string($value)) {
                $value = [$value => []];
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
            } elseif ($key === 'queryBuilder' && is_callable($value) && isset($result['queryBuilder']) && is_callable($result['queryBuilder'])) {
                $first = $result['queryBuilder'];
                $second = $value;
                $result['queryBuilder'] = static fn($query) => $second($first($query));
            } elseif (isset($this->containOptions[$key]) || $key === 'association') {
                $result[$key] = $value;
            } elseif ($key === 'associations' || $key === 'config') {
                continue;
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
        $association = $options['association'] ?? null;
        if (!$association instanceof Association) {
            $association = $repository->getAssociation($alias);
        }

        unset($options['association']);

        if (($options['matching'] ?? false) === true) {
            $propertyPath = '_matchingData.' . $alias;
        } else {
            $propertyPath = trim($propertyPath . '.' . $association->getProperty(), '.');
        }

        $target = $association->getTarget();
        $config = array_intersect_key($options, $this->containOptions);
        $config += ['strategy' => $this->defaultStrategy($association)];
        if (isset($config['finder']) && is_array($config['finder'])) {
            $finderName = array_key_first($config['finder']);
            $finderOptions = $config['finder'][$finderName] ?? [];
            $config['finder'] = fn(): QueryInterface => $association->find((string)$finderName, ...(array)$finderOptions);
        } elseif (isset($config['finder']) && is_string($config['finder'])) {
            $finderName = $config['finder'];
            $config['finder'] = fn(): QueryInterface => $association->find($finderName);
        }

        $config = $this->applyQueryBuilder($config, $target);
        $nestedMatching = $config['_matching'] ?? [];
        unset($config['_matching']);
        $loadable = new EagerLoadable(
            $alias,
            $association,
            $config,
            $aliasPath,
            $propertyPath,
            $association->canBeJoined($config),
            $config['matching'] ?? null,
            $alias,
        );

        foreach ($options as $nestedAlias => $nestedOptions) {
            if (isset($this->containOptions[$nestedAlias])) {
                continue;
            }

            if ($nestedAlias === 'association') {
                continue;
            }

            if ($nestedAlias === 'associations') {
                continue;
            }

            if ($nestedAlias === 'config') {
                continue;
            }

            if (!is_array($nestedOptions)) {
                continue;
            }

            $loadable->addAssociation(
                $nestedAlias,
                $this->normalize($target, $nestedAlias, $nestedOptions, $aliasPath . '.' . $nestedAlias, $propertyPath),
            );
        }

        foreach ($nestedMatching as $nestedAlias => $nestedOptions) {
            $nestedOptions = is_array($nestedOptions) ? $nestedOptions : [];
            $nestedOptions['matching'] = true;
            $loadable->addAssociation(
                $nestedAlias,
                $this->normalize($target, $nestedAlias, $nestedOptions, $aliasPath . '.' . $nestedAlias, $propertyPath),
            );
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
        $query->eagerLoaded(true);
        ($config['queryBuilder'])($query);

        if (!empty($config['matching']) && $query->getEagerLoader()->getContain() !== []) {
            throw new DatabaseException(sprintf(
                '`%s` association cannot contain() associations when using JOIN strategy.',
                $target->getAlias(),
            ));
        }

        $compiled = $query->compile();
        /** @var array<string, mixed> $filter */
        $filter = $compiled['filter'] ?? [];
        if ($filter !== []) {
            $config['conditions'] = array_merge(
                is_array($config['conditions'] ?? null) ? $config['conditions'] : [],
                $filter,
            );
        }

        $projection = $compiled['options']['projection'] ?? [];
        if ($projection !== [] && empty($config['fields'])) {
            $config['fields'] = array_keys($projection);
        }

        if (($compiled['options']['sort'] ?? []) !== [] && !isset($config['sort'])) {
            $config['sort'] = $compiled['options']['sort'];
        }

        $nestedMatching = $query->getEagerLoader()->getMatching();
        if ($nestedMatching !== []) {
            $config['_matching'] = $nestedMatching;
        }

        return $config;
    }

    /**
     * Dispatches one normalized node and all of its descendants.
     *
     * @param \Crustum\Mongo\ODM\EagerLoadable $loadable The node to dispatch.
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $query The source query.
     * @return void
     */
    private function dispatch(EagerLoadable $loadable, SelectQuery $query, ?string $parentProperty = null): void
    {
        $association = $loadable->instance();
        if (!$association instanceof Association) {
            return;
        }

        $strategy = $loadable->getConfig()['strategy'];
        $matching = (bool)($loadable->getConfig()['matching'] ?? false);
        $aliasPath = $loadable->aliasPath();
        $parentAliasPath = str_contains($aliasPath, '.')
            ? substr($aliasPath, 0, (int)strrpos($aliasPath, '.'))
            : null;
        $parentLookupMissing = $parentAliasPath !== null
            && !isset($this->attachedLookupPaths[$parentAliasPath]);
        if ($association instanceof BelongsToMany) {
            $path = $loadable->aliasPath();
            if (!isset($this->attachedLookupPaths[$path])) {
                $config = $loadable->getConfig();
                if ($parentProperty !== null) {
                    $config['lookupPrefix'] = $parentProperty;
                }

                if ($matching && $this->hasMatchingChildren($loadable)) {
                    $config['deferNegateMatch'] = true;
                }

                $stages = $association->buildPipeline($config);
                if ($stages !== []) {
                    $query->pipeline($stages);
                    $this->attachedPipeline = array_merge($this->attachedPipeline, $stages);
                }

                $this->attachedLookupPaths[$path] = true;
            }
        } elseif (
            !$matching
            && (
                $association instanceof HasMany
                || $strategy === 'select'
                || $strategy === 'reference'
                || $parentLookupMissing
                || ($strategy === Association::STRATEGY_LOOKUP && !$association->usesLookup($loadable->getConfig()))
            )
        ) {
            $path = $loadable->aliasPath();
            if (!isset($this->dispatchedExternalPaths[$path])) {
                $this->external[] = $loadable;
                $this->dispatchedExternalPaths[$path] = true;
            }
        } else {
            $path = $loadable->aliasPath();
            if (isset($this->attachedLookupPaths[$path])) {
                foreach ($loadable->associations() as $nested) {
                    $this->dispatch($nested, $query, $this->nestedLookupPrefix($loadable, $association));
                }

                return;
            }

            $config = $loadable->getConfig();
            if ($parentProperty !== null) {
                $config['lookupPrefix'] = $parentProperty;
            }

            if ($matching && $this->hasMatchingChildren($loadable)) {
                $config['deferNegateMatch'] = true;
            }

            $surrogate = $association->buildAttachSurrogateQuery($config);

            if ($matching && $surrogate->getEagerLoader()->getContain() !== []) {
                throw new DatabaseException(sprintf(
                    '`%s` association cannot contain() associations when using JOIN strategy.',
                    $association->getName(),
                ));
            }

            $association->formatAssociationResults($query, $surrogate, [
                'propertyPath' => $loadable->propertyPath(),
            ]);
            $association->bindNewAssociations($query, $surrogate, [
                'aliasPath' => $loadable->aliasPath(),
            ]);

            $config = $association->mergeSurrogateIntoConfig($surrogate, $config);

            $stages = $association->buildPipeline($config);
            if ($stages !== []) {
                $query->pipeline($stages);
                $this->attachedPipeline = array_merge($this->attachedPipeline, $stages);
            }

            $this->attachedLookupPaths[$path] = true;
        }

        foreach ($loadable->associations() as $nested) {
            $this->dispatch($nested, $query, $this->nestedLookupPrefix($loadable, $association));
        }
    }

    /**
     * Prefix for a nested `$lookup.localField` (and contain nesting).
     *
     * Matching unwinds onto the document root, so the prefix is the parent
     * property name. Nested contain keeps the parent document, so the prefix
     * is the full property path (`client.order`).
     *
     * @param \Crustum\Mongo\ODM\EagerLoadable $loadable The parent loadable.
     * @param \Crustum\Mongo\ODM\Association $association The parent association.
     * @return string
     */
    private function nestedLookupPrefix(EagerLoadable $loadable, Association $association): string
    {
        if (!empty($loadable->getConfig()['matching'])) {
            return $association->getProperty();
        }

        return $loadable->propertyPath() ?? $association->getProperty();
    }

    /**
     * Whether a loadable carries nested matching children.
     *
     * @param \Crustum\Mongo\ODM\EagerLoadable $loadable The loadable node.
     * @return bool
     */
    private function hasMatchingChildren(EagerLoadable $loadable): bool
    {
        foreach ($loadable->associations() as $child) {
            if (!empty($child->getConfig()['matching'])) {
                return true;
            }
        }

        return false;
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
                'nestKey' => $loadable->name(),
                'matching' => (bool)($loadable->getConfig()['matching'] ?? false),
            ];
            $this->map($loadable->associations(), $map);
        }
    }
}
