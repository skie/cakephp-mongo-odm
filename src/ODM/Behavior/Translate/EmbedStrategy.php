<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Behavior\Translate;

use ArrayObject;
use Cake\Collection\CollectionInterface;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\ResultSetInterface;
use Cake\Event\EventInterface;
use Crustum\Mongo\Database\Expression\QueryExpression;
use Crustum\Mongo\Database\QueryBuilder;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Locator\LocatorAwareTrait;
use Crustum\Mongo\ODM\Query\SelectQuery;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

/**
 * Embeds the translations directly inside the source document.
 *
 * Translations are stored under a single embedded object field (by default
 * `_translations`) keyed by locale:
 *
 * ```
 * {
 *     "title": "Default locale title",
 *     "_translations": {
 *         "de_DE": { "title": "Titel" },
 *         "fr_FR": { "title": "Titre" }
 *     }
 * }
 * ```
 *
 * The default locale lives in the root fields (cake semantics); every other
 * locale lives in the embedded map. Because the data is in the same document
 * there is no second collection, no `$lookup` and no association: reads merge
 * the active locale's fields with a plain result formatter, writes persist the
 * `_translations` field as part of the document, and translated fields can be
 * filtered/ordered with dotted paths (`_translations.de_DE.title`).
 *
 * @see \Crustum\Mongo\ODM\Behavior\Translate\ShadowCollectionStrategy
 */
class EmbedStrategy implements TranslateStrategyInterface
{
    use LocatorAwareTrait;
    use TranslateStrategyTrait;

    /**
     * Default config
     *
     * These are merged with user-provided configuration.
     *
     * @var array<string, mixed>
     */
    protected array $defaultConfig = [
        'fields' => [],
        'defaultLocale' => null,
        'referenceName' => null,
        'allowEmptyTranslations' => true,
        'onlyTranslated' => false,
        'strategy' => 'embed',
        'collectionLocator' => null,
        'validator' => false,
        'embedField' => '_translations',
    ];

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $collection Collection instance.
     * @param array<string, mixed> $config Configuration.
     */
    public function __construct(BaseCollection $collection, array $config = [])
    {
        $this->initConfig($config);
        $this->collection = $collection;
        // Translations are embedded in the source document, so the "translation
        // collection" is the source collection itself.
        $this->translationCollection = $collection;
    }

    /**
     * Callback method that listens to the `beforeFind` event in the bound
     * collection. It adds a formatter that copies the translated fields of the
     * active locale into the root document.
     *
     * @param \Cake\Event\EventInterface<\Crustum\Mongo\ODM\BaseCollection> $event The beforeFind event that was fired.
     * @param \Crustum\Mongo\ODM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query Query.
     * @param \ArrayObject<string, mixed> $options The options for the query.
     * @return void
     */
    public function beforeFind(EventInterface $event, SelectQuery $query, ArrayObject $options): void
    {
        $config = $this->getConfig();
        $locale = $options['locale'] ?? $this->getLocale();
        $isDefault = $locale === $config['defaultLocale'];

        if (!$isDefault) {
            $this->applyTranslatedFilters($query, $config, $locale, $options);
            $this->rewriteTranslatedClauses($query, $config, $locale);

            // The merge needs the embedded translations in the projection; a
            // selective `select()` would otherwise drop the field.
            if ($query->clause('select') !== []) {
                $query->selectAlso([$config['embedField']]);
            }
        }

        // Always hydrate the embedded translations so a later patch/save can
        // merge into them; only non-default locales are merged into the root.
        $query->formatResults(
            fn(CollectionInterface $results): CollectionInterface => $this->rowMapper($results, $locale, $isDefault),
            SelectQuery::PREPEND,
        );
    }

    /**
     * Modifies the entity before it is saved so that translated fields are
     * persisted in the embedded `_translations` field.
     *
     * The active locale's values that were copied into the root fields by the
     * row mapper must not overwrite the default-locale root values, so the
     * translated root fields are kept clean unless the default locale is being
     * saved.
     *
     * @param \Cake\Event\EventInterface<\Crustum\Mongo\ODM\BaseCollection> $event The beforeSave event that was fired.
     * @param \Cake\Datasource\EntityInterface $entity The entity that is going to be saved.
     * @param \ArrayObject<string, mixed> $options the options passed to the save method.
     * @return void
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $config = $this->getConfig();
        $locale = $entity->has('_locale') ? $entity->get('_locale') : $this->getLocale();

        $translations = $entity->has($config['embedField'])
            ? $this->normalize($entity->get($config['embedField']))
            : [];
        if (!is_array($translations)) {
            $translations = [];
        }

        // Preserve the stored translations when only a subset was patched: the
        // marshaller may hand back just the incoming locales, so merge them
        // over the previously-loaded map instead of replacing it wholesale.
        if (!$entity->isNew() && $entity->isDirty($config['embedField'])) {
            $original = $entity->has($config['embedField']) ? $this->normalize($entity->getOriginal($config['embedField'])) : null;
            if (is_array($original)) {
                $translations = array_replace($original, $translations);
            }
        }

        $changed = false;

        // Serialize bundled translation documents (marshalled via `_translations`
        // or created through `getOrCreateTranslation()`).
        foreach ($translations as $lang => $translation) {
            if ($translation instanceof EntityInterface) {
                $translations[$lang] = $translation->toArray();
                $changed = true;
            }
        }

        // Drop empty translation fields and empty locales when configured.
        if ($config['allowEmptyTranslations'] === false) {
            foreach ($translations as $lang => $fields) {
                if (!is_array($fields)) {
                    continue;
                }

                $filtered = array_filter($fields, static fn(mixed $v): bool => $v !== null && $v !== '');
                if ($filtered === $fields) {
                    continue;
                }

                if ($filtered === []) {
                    unset($translations[$lang]);
                } else {
                    $translations[$lang] = $filtered;
                }

                $changed = true;
            }
        }

        // Fold dirty root translated fields into the active locale's embedded
        // map (cake semantics: a non-default locale is saved through the
        // translation, never into the default-locale root fields).
        if ($locale !== $config['defaultLocale']) {
            foreach ($this->translatedFields() as $field) {
                if ($entity->isDirty($field)) {
                    $translations[$locale][$field] = $entity->get($field);
                    $changed = true;
                    $entity->setDirty($field, false);
                }
            }
        }

        if ($changed) {
            // An empty map must be persisted as an empty object, not an empty
            // array, to satisfy the `bsonType: object` validator.
            $entity->set($config['embedField'], $translations === [] ? (object)[] : $translations);
        }
    }

    /**
     * Returns a fully aliased field name for translated fields.
     *
     * A translated field resolves to the embedded dotted path
     * (`_translations.{locale}.{field}`) for the current locale; any other
     * field keeps its collection alias.
     *
     * @param string $field Field name to be aliased.
     * @return string
     */
    public function translationField(string $field): string
    {
        if ($this->getLocale() === $this->getConfig('defaultLocale')) {
            return $this->collection->aliasField($field);
        }

        if (in_array($field, $this->translatedFields(), true)) {
            return $this->getConfig('embedField') . '.' . $this->getLocale() . '.' . $field;
        }

        return $this->collection->aliasField($field);
    }

    /**
     * Custom finder method used to retrieve all translations for the found records.
     *
     * The translations are already embedded in each document, so no secondary
     * query is issued: the formatter only rebuilds `_translations` as an array
     * of translation documents.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query The original query to modify
     * @param array<string> $locales A list of locales or options with the `locales` key defined
     * @return \Crustum\Mongo\ODM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array>
     */
    public function findTranslations(SelectQuery $query, array $locales = []): SelectQuery
    {
        return $query->formatResults(
            fn(CollectionInterface $results): CollectionInterface => $this->mapTranslations($results, $locales),
            SelectQuery::PREPEND,
        );
    }

    /**
     * Modifies the results from a collection find in order to rebuild the full
     * translation records under the `_translations` key.
     *
     * @param \Cake\Datasource\ResultSetInterface<array-key, \Cake\Datasource\EntityInterface|array<string, mixed>> $results Results to modify.
     * @return \Cake\Collection\CollectionInterface<mixed, mixed>
     */
    public function groupTranslations(ResultSetInterface $results): CollectionInterface
    {
        return $this->mapTranslations($results, []);
    }

    /**
     * Rebuilds the embedded translations into translation documents under the
     * `_translations` key, optionally filtered by locale.
     *
     * @param \Cake\Collection\CollectionInterface<mixed, mixed> $results Results to modify.
     * @param array<string> $locales Locales to keep; empty means all.
     * @return \Cake\Collection\CollectionInterface<mixed, mixed>
     */
    protected function mapTranslations(CollectionInterface $results, array $locales): CollectionInterface
    {
        $config = $this->getConfig();
        $documentClass = $this->collection->getDocumentClass();

        return $results->map(function ($row) use ($config, $documentClass, $locales) {
            if (!$row instanceof EntityInterface) {
                return $row;
            }

            $translations = $row->has($config['embedField']) ? $row->get($config['embedField']) : [];
            $row->unset($config['embedField']);

            $result = [];
            foreach ((array)$this->normalize($translations) as $lang => $data) {
                if ($locales !== [] && !in_array($lang, $locales, true)) {
                    continue;
                }

                // The row mapper may have hydrated the locales into Documents
                // already; accept both raw arrays and Documents.
                $data = $data instanceof EntityInterface ? $data->toArray() : $this->normalize($data);
                if (!is_array($data)) {
                    continue;
                }

                // Mirror the shadow/EAV translation rows: each translation
                // carries its own `locale` marker.
                $data['locale'] = $lang;
                $translationDocument = new $documentClass($data, [
                    'markClean' => true,
                    'markNew' => false,
                ]);
                $result[$lang] = $translationDocument;
            }

            $row->set('_translations', $result)
                ->setDirty('_translations', false);

            return $row;
        });
    }

    /**
     * Copies the translated fields of the active locale into the root document
     * and sets the `_locale` marker.
     *
     * @param \Cake\Collection\CollectionInterface<mixed, mixed> $results Results to map.
     * @param string $locale Locale string.
     * @param bool $isDefault Whether the active locale is the default one.
     * @return \Cake\Collection\CollectionInterface<mixed, mixed>
     */
    protected function rowMapper(CollectionInterface $results, string $locale, bool $isDefault): CollectionInterface
    {
        $config = $this->getConfig();
        $allowEmpty = $config['allowEmptyTranslations'];
        $documentClass = $this->collection->getDocumentClass();

        return $results->map(function ($row) use ($config, $allowEmpty, $locale, $isDefault, $documentClass): null|array|EntityInterface {
            /** @var \Cake\Datasource\EntityInterface|array<string, mixed>|null $row */
            if ($row === null) {
                return $row;
            }

            $raw = $row instanceof EntityInterface
                ? ($row->has($config['embedField']) ? $row->get($config['embedField']) : null)
                : ($row[$config['embedField']] ?? null);

            // Hydrate every locale into a translation document so a subsequent
            // patch/save can merge into it (the marshaller requires Documents).
            $translations = [];
            foreach ((array)$this->normalize($raw) as $lang => $fields) {
                $data = $fields instanceof EntityInterface ? $fields->toArray() : $this->normalize($fields);
                if (!is_array($data)) {
                    continue;
                }

                $translations[$lang] = new $documentClass($data, [
                    'markClean' => true,
                    'markNew' => false,
                ]);
            }

            if ($row instanceof EntityInterface) {
                $row->set($config['embedField'], $translations);
                $row->setDirty($config['embedField'], false);
            }

            if ($isDefault) {
                $this->hideEmbeddedTranslations($row, $config['embedField']);

                return $row;
            }

            $current = $translations[$locale] ?? null;
            $current = $current instanceof EntityInterface ? $current->toArray() : $this->normalize($current);

            if (!is_array($current) || $current === []) {
                $row['_locale'] = $locale;
                if ($row instanceof EntityInterface) {
                    $row->setDirty('_locale', false);
                }

                $this->hideEmbeddedTranslations($row, $config['embedField']);

                return $row;
            }

            foreach ($current as $field => $value) {
                if ($value !== null && ($allowEmpty || $value !== '')) {
                    $row[$field] = $value;
                    if ($row instanceof EntityInterface) {
                        $row->setDirty($field, false);
                    }
                }
            }

            $row['_locale'] = $locale;
            if ($row instanceof EntityInterface) {
                $row->setDirty('_locale', false);
            }

            // The embedded translations were merged into the root fields; hide
            // the storage map so it does not leak into `toArray()`, but keep it
            // on the entity so a subsequent save can still merge into it.
            $this->hideEmbeddedTranslations($row, $config['embedField']);

            return $row;
        });
    }

    /**
     * Hides the embedded translations field on hydrated documents so it does
     * not leak into `toArray()`, while keeping it available for saving. Raw
     * rows are stripped instead (they are never re-saved).
     *
     * @param \Cake\Datasource\EntityInterface|array<string, mixed> $row The result row.
     * @param string $field The embedded translations field.
     * @return void
     */
    protected function hideEmbeddedTranslations(EntityInterface|array &$row, string $field): void
    {
        if ($row instanceof EntityInterface) {
            $row->setHidden(array_merge($row->getHidden(), [$field]));
            $row->setDirty($field, false);

            return;
        }

        unset($row[$field]);
    }

    /**
     * Adds a filter that only keeps records having a translation for the
     * active locale when `onlyTranslated` (or `filterByCurrentLocale`) is set.
     *
     * `filterByCurrentLocale` explicitly overrides `onlyTranslated` (a `false`
     * value keeps every record, mirroring the shadow strategy's LEFT join).
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query The query.
     * @param array<string, mixed> $config The strategy config.
     * @param string $locale The active locale.
     * @param \ArrayObject<string, mixed> $options The find options.
     * @return void
     */
    protected function applyTranslatedFilters(SelectQuery $query, array $config, string $locale, ArrayObject $options): void
    {
        if (array_key_exists('filterByCurrentLocale', (array)$options)) {
            $filter = (bool)$options['filterByCurrentLocale'];
        } else {
            $filter = (bool)($config['onlyTranslated'] ?? false);
        }

        if ($filter) {
            $query->where((new QueryExpression())->exists($config['embedField'] . '.' . $locale));
        }
    }

    /**
     * Rewrites bare translated-field references inside the where and order
     * clauses to the embedded dotted path for the active locale.
     *
     * This mirrors the shadow strategy semantics, where a translated field is
     * queried against the translation for the current locale rather than the
     * default-locale root field.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query The query.
     * @param array<string, mixed> $config The strategy config.
     * @param string $locale The active locale.
     * @return void
     */
    protected function rewriteTranslatedClauses(SelectQuery $query, array $config, string $locale): void
    {
        $fields = $this->translatedFields();
        if ($fields === []) {
            return;
        }

        $prefix = $config['embedField'] . '.' . $locale . '.';

        $where = $query->clause('where');
        if (is_array($where) && $where !== []) {
            $rewritten = $this->walkConditions($where, $fields, $prefix);
            if ($rewritten !== $where) {
                $query->where($rewritten, [], true);
            }
        }

        $order = $query->clause('order');
        if (is_array($order) && $order !== []) {
            $rewritten = [];
            foreach ($order as $field => $direction) {
                $rewritten[is_string($field) && in_array($field, $fields, true) ? $prefix . $field : $field] = $direction;
            }

            if ($rewritten !== $order) {
                $query->orderBy($rewritten, true);
            }
        }
    }

    /**
     * Rewrites bare translated-field condition keys to the embedded dotted
     * path, preserving any operator suffix.
     *
     * Translated-field filters are top-level string-keyed conditions; nested
     * AND/OR groups are passed through unchanged.
     *
     * @param array<string, mixed> $conditions The conditions to walk.
     * @param array<string> $fields The translated fields.
     * @param string $prefix The dotted path prefix (`_translations.{locale}.`).
     * @return array<string, mixed>
     */
    protected function walkConditions(array $conditions, array $fields, string $prefix): array
    {
        $result = [];
        foreach ($conditions as $key => $value) {
            if (!is_string($key)) {
                $result[$key] = $value;
                continue;
            }

            [$field] = QueryBuilder::splitConditionKey($key);
            $suffix = substr(trim($key), strlen($field));

            $result[in_array($field, $fields, true) ? $prefix . $field . $suffix : $key] = $value;
        }

        return $result;
    }

    /**
     * Returns the list of translated fields from the behavior config.
     *
     * @return array<string>
     */
    protected function translatedFields(): array
    {
        $fields = $this->getConfig('fields');

        return is_array($fields) ? $fields : [];
    }

    /**
     * Converts BSON containers into PHP arrays for hydration.
     *
     * @param mixed $value Value to normalize.
     * @return mixed
     */
    protected function normalize(mixed $value): mixed
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            return $value->getArrayCopy();
        }

        return $value;
    }
}
