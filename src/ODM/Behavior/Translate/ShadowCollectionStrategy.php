<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Behavior\Translate;

use ArrayObject;
use Cake\Collection\CollectionInterface;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Crustum\Mongo\ODM\Association;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Query\SelectQuery;
use function Cake\Core\pluginSplit;

/**
 * This class provides a way to translate dynamic data by keeping translations
 * in a separate shadow collection where each document corresponds to a document
 * of the primary collection.
 */
class ShadowCollectionStrategy extends AbstractStrategy
{
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
        'strategy' => 'subquery',
        'collectionLocator' => null,
        'validator' => false,
    ];

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $collection Collection instance.
     * @param array<string, mixed> $config Configuration.
     */
    public function __construct(BaseCollection $collection, array $config = [])
    {
        $collectionAlias = $collection->getAlias();
        [$plugin] = pluginSplit($collection->getRegistryAlias(), true);
        $collectionReferenceName = $config['referenceName'];

        $config += [
            'mainCollectionAlias' => $collectionAlias,
            'translationCollection' => $plugin . $collectionReferenceName . 'Translations',
            'hasOneAlias' => $collectionAlias . 'Translation',
        ];

        if (isset($config['collectionLocator'])) {
            $this->collectionLocator = $config['collectionLocator'];
        }

        $this->initConfig($config);
        $this->collection = $collection;
        $translationCollection = $this->getCollectionLocator()->get(
            $this->getConfig()['translationCollection'],
            ['allowFallbackClass' => true],
        );
        assert($translationCollection instanceof BaseCollection);
        $this->translationCollection = $translationCollection;

        $this->setupAssociations();
    }

    /**
     * Create a hasMany association for all records.
     *
     * Don't create a hasOne association here as the join conditions are modified
     * in before find - so create/modify it there.
     *
     * @return void
     */
    protected function setupAssociations(): void
    {
        $config = $this->getConfig();

        $targetAlias = $this->translationCollection->getAlias();

        if ($this->collection->associations()->has($targetAlias)) {
            $this->collection->associations()->remove($targetAlias);
        }

        $this->collection->hasMany($targetAlias, [
            'className' => $config['translationCollection'],
            'foreignKey' => '_shadow_id',
            'strategy' => $config['strategy'],
            'propertyName' => '_i18n',
            'dependent' => true,
        ]);
    }

    /**
     * Callback method that listens to the `beforeFind` event in the bound
     * table. It modifies the passed query by eager loading the translated fields
     * and adding a formatter to copy the values into the main collection records.
     *
     * @param \Cake\Event\EventInterface<\Crustum\Mongo\ODM\BaseCollection> $event The beforeFind event that was fired.
     * @param \Crustum\Mongo\ODM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query Query.
     * @param \ArrayObject<string, mixed> $options The options for the query.
     * @return void
     */
    public function beforeFind(EventInterface $event, SelectQuery $query, ArrayObject $options): void
    {
        $locale = $options['locale'] ?? $this->getLocale();
        $config = $this->getConfig();

        if ($locale === $config['defaultLocale']) {
            return;
        }

        $fieldsAdded = $this->addFieldsToQuery($query, $config);
        $orderByTranslatedField = $this->iterateClause($query, 'order', $config);
        $filteredByTranslatedField =
            $this->traverseClause($query, 'where', $config) ||
            $config['onlyTranslated'] ||
            ($options['filterByCurrentLocale'] ?? null);

        if (!$fieldsAdded && !$orderByTranslatedField && !$filteredByTranslatedField) {
            return;
        }

        $preserveNull = true;
        if (isset($options['filterByCurrentLocale'])) {
            $preserveNull = !$options['filterByCurrentLocale'];
        } elseif ($config['onlyTranslated']) {
            $preserveNull = false;
        }

        $query->lookup($this->translationCollection->getCollection(), [
            'let' => ['rootId' => '$_id'],
            'as' => 'translation',
            'pipeline' => fn($q) => $q
                ->where([
                    '$expr' => ['$eq' => ['$_shadow_id', '$$rootId']],
                    'locale' => $locale,
                ]),
        ]);
        $query->unwind('$translation', ['preserveNullAndEmptyArrays' => $preserveNull]);

        if ($query->clause('select') !== []) {
            $query->select(['translation']);
        }

        $query->formatResults(
            fn(CollectionInterface $results): CollectionInterface => $this->rowMapper($results, $locale),
            SelectQuery::PREPEND,
        );
    }

    /**
     * Create a hasOne association for record with required locale.
     *
     * @param string $locale Locale
     * @param \ArrayObject<string, mixed> $options Find options
     * @return void
     */
    protected function setupHasOneAssociation(string $locale, ArrayObject $options): void
    {
        $config = $this->getConfig();

        [$plugin] = pluginSplit($config['translationCollection']);
        $hasOneTargetAlias = $plugin ? ($plugin . '.' . $config['hasOneAlias']) : $config['hasOneAlias'];
        if (!$this->getCollectionLocator()->exists($hasOneTargetAlias)) {
            // Load table before hand with fallback class usage enabled
            $this->getCollectionLocator()->get(
                $hasOneTargetAlias,
                [
                    'className' => $config['translationCollection'],
                    'allowFallbackClass' => true,
                ],
            );
        }

        if (isset($options['filterByCurrentLocale'])) {
            $joinType = $options['filterByCurrentLocale'] ? 'INNER' : 'LEFT';
        } else {
            $joinType = $config['onlyTranslated'] ? 'INNER' : 'LEFT';
        }

        if ($this->collection->associations()->has($config['hasOneAlias'])) {
            $this->collection->associations()->remove($config['hasOneAlias']);
        }

        $this->collection->hasOne($config['hasOneAlias'], [
            'foreignKey' => ['_shadow_id'],
            'joinType' => $joinType,
            'propertyName' => 'translation',
            'className' => $config['translationCollection'],
            'conditions' => [
                $config['hasOneAlias'] . '.locale' => $locale,
            ],
            'strategy' => Association::STRATEGY_LOOKUP,
        ]);
    }

    /**
     * Add translation fields to query.
     *
     * If the query is using autofields (directly or implicitly) add the
     * main collection's fields to the query first.
     *
     * Only add translations for fields that are in the main collection, always
     * add the locale field though.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query The query to check.
     * @param array<string, mixed> $config The config to use for adding fields.
     * @return bool Whether a join to the translation table is required.
     */
    protected function addFieldsToQuery(SelectQuery $query, array $config): bool
    {
        if ($query->isAutoFieldsEnabled()) {
            return true;
        }

        $select = array_keys($query->clause('select'));

        if ($select === []) {
            return true;
        }

        $alias = $config['mainCollectionAlias'];
        foreach ($this->translatedFields() as $field) {
            if (array_intersect($select, [$field, "{$alias}.{$field}"])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether a translated field is referenced by a clause.
     *
     * The objective is to determine whether the translations lookup is
     * required: when a translated field is used in an order clause the query
     * must join the translations. The crustum ODM stores clauses as plain
     * arrays (there are no SQL expression trees and no ambiguous-field
     * errors), so this walks the Mongo sort map instead of an expression
     * tree.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query the query to check.
     * @param string $name The clause name.
     * @param array<string, mixed> $config The config to use for adding fields.
     * @return bool Whether a join to the translation table is required.
     */
    protected function iterateClause(SelectQuery $query, string $name = '', array $config = []): bool
    {
        $clause = $query->clause($name);
        if (!is_array($clause) || $clause === []) {
            return false;
        }

        $fields = $this->translatedFields();

        foreach (array_keys($clause) as $field) {
            if (!is_string($field)) {
                continue;
            }

            if (str_contains($field, '.')) {
                continue;
            }

            if (in_array($field, $fields, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether a translated field is referenced by a where clause.
     *
     * Walks the Mongo filter map (nested conditions included); when a
     * translated field is filtered on the query must join the translations.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query the query to check.
     * @param string $name The clause name.
     * @param array<string, mixed> $config The config to use for adding fields.
     * @return bool Whether a join to the translation table is required.
     */
    protected function traverseClause(SelectQuery $query, string $name = '', array $config = []): bool
    {
        $clause = $query->clause($name);
        if (!is_array($clause) || $clause === []) {
            return false;
        }

        $fields = $this->translatedFields();
        $found = false;

        $walker = function (array $conditions) use (&$walker, $fields, &$found): void {
            if ($found) {
                return;
            }

            foreach ($conditions as $key => $value) {
                if (!is_string($key)) {
                    if (is_array($value)) {
                        $walker($value);
                    }

                    continue;
                }

                $field = preg_split('/\s+(IS|IN|NOT|!=|>|<|>=|<=|LIKE|REGEX)/', $key, 2)[0] ?? $key;
                if (str_contains($field, '.')) {
                    continue;
                }

                if (in_array($field, $fields, true)) {
                    $found = true;

                    return;
                }

                if (is_array($value)) {
                    $walker($value);
                }
            }
        };

        $walker($clause);

        return $found;
    }

    /**
     * Modifies the entity before it is saved so that translated fields are persisted
     * in the database too.
     *
     * @param \Cake\Event\EventInterface<\Crustum\Mongo\ODM\BaseCollection> $event The beforeSave event that was fired.
     * @param \Cake\Datasource\EntityInterface $entity The entity that is going to be saved.
     * @param \ArrayObject<string, mixed> $options the options passed to the save method.
     * @return void
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $locale = $entity->has('_locale') ? $entity->get('_locale') : $this->getLocale();
        $newOptions = [$this->translationCollection->getAlias() => ['validate' => false]];
        $options['associated'] = $newOptions + $options['associated'];

        // Check early if empty translations are present in the entity.
        // If this is the case, unset them to prevent persistence.
        // This only applies if $this->getConfig()['allowEmptyTranslations'] is false
        if ($this->getConfig()['allowEmptyTranslations'] === false) {
            $this->unsetEmptyFields($entity);
        }

        $this->bundleTranslatedFields($entity);
        $bundled = $entity->has('_i18n') ? (array)$entity->get('_i18n') : [];
        $noBundled = $bundled === [];

        // No additional translation records need to be saved,
        // as the entity is in the default locale.
        if ($noBundled && $locale === $this->getConfig('defaultLocale')) {
            return;
        }

        $values = $entity->extract($this->translatedFields(), true);
        $fields = array_keys($values);
        $noFields = $fields === [];

        // If there are no fields and no bundled translations, or both fields
        // in the default locale and bundled translations we can
        // skip the remaining logic as it is not necessary.
        if ($noFields && $noBundled || ($fields && $bundled)) {
            return;
        }

        /** @var string $primaryKey */
        $primaryKey = current((array)$this->collection->getPrimaryKey());
        $id = $entity->has($primaryKey) ? $entity->get($primaryKey) : null;

        // When we have no key and bundled translations, we
        // need to mark the entity dirty so the root
        // entity persists. When $noFields holds and we reached this point
        // there is at least one bundled translation ($noBundled was false).
        if ($noFields && !$id) {
            foreach ($this->translatedFields() as $field) {
                $entity->setDirty($field, true);
            }

            return;
        }

        if ($noFields) {
            return;
        }

        $where = ['locale' => $locale];
        $translation = null;
        if ($id) {
            $where['_shadow_id'] = $id;

            /** @var \Cake\Datasource\EntityInterface|null $translation */
            $translation = $this->translationCollection->find()
                ->select(array_merge(['_shadow_id', 'locale'], $fields))
                ->where($where)
                ->first();
        }

        if ($translation) {
            $translation->patch($values);
        } else {
            $translation = new ($this->translationCollection->getDocumentClass())(
                $where + $values,
                [
                    'useSetters' => false,
                    'markNew' => true,
                ],
            );
        }

        $entity->set('_i18n', array_merge($bundled, [$translation]));
        $entity->set('_locale', $locale, ['setter' => false]);
        $entity->setDirty('_locale', false);

        foreach ($fields as $field) {
            $entity->setDirty($field, false);
        }
    }

    /**
     * Returns a fully aliased field name for translated fields.
     *
     * If the requested field is configured as a translation field, field with
     * an alias of a corresponding association is returned. Collection-aliased
     * field name is returned for all other fields.
     *
     * @param string $field Field name to be aliased.
     * @return string
     */
    public function translationField(string $field): string
    {
        if ($this->getLocale() === $this->getConfig('defaultLocale')) {
            return $this->collection->aliasField($field);
        }

        $translatedFields = $this->translatedFields();
        if (in_array($field, $translatedFields, true)) {
            return $this->getConfig('hasOneAlias') . '.' . $field;
        }

        return $this->collection->aliasField($field);
    }

    /**
     * Modifies the results from a collection find in order to merge the translated
     * fields into each entity for a given locale.
     *
     * @param \Cake\Collection\CollectionInterface<mixed, mixed> $results Results to map.
     * @param string $locale Locale string
     * @return \Cake\Collection\CollectionInterface<mixed, mixed>
     */
    protected function rowMapper(CollectionInterface $results, string $locale): CollectionInterface
    {
        $allowEmpty = $this->getConfig()['allowEmptyTranslations'];

        return $results->map(function ($row) use ($allowEmpty, $locale) {
            /** @var \Cake\Datasource\EntityInterface|array<string, mixed>|null $row */
            if ($row === null) {
                return $row;
            }

            $hydrated = $row instanceof EntityInterface;

            if (empty($row['translation'])) {
                $row['_locale'] = $locale;
                unset($row['translation']);

                if ($hydrated) {
                    /** @var \Cake\Datasource\EntityInterface $row */
                    $row->setDirty('_locale', false);
                }

                return $row;
            }

            $translation = $row['translation'];
            assert($translation instanceof EntityInterface || is_array($translation));

            $translationHydrated = $translation instanceof EntityInterface;
            if ($translationHydrated) {
                /** @var \Cake\Datasource\EntityInterface $translation */
                $keys = $translation->getVisible();
            } else {
                /** @var non-empty-array<string, mixed> $translation */
                $keys = array_keys($translation);
            }

            foreach ($keys as $field) {
                if ($field === 'locale') {
                    $row['_locale'] = $translation[$field];
                    continue;
                }

                if (in_array($field, ['_id', '_shadow_id'], true)) {
                    continue;
                }

                if ($translation[$field] !== null && ($allowEmpty || $translation[$field] !== '')) {
                    $row[$field] = $translation[$field];
                    if ($hydrated) {
                        /** @var \Cake\Datasource\EntityInterface $row */
                        $row->setDirty($field, false);
                    }
                }
            }

            unset($row['translation']);

            if ($hydrated) {
                /** @var \Cake\Datasource\EntityInterface $row */
                $row->setDirty('_locale', false);
            }

            return $row;
        });
    }

    /**
     * Modifies the results from a table find in order to merge full translation
     * records into each entity under the `_translations` key.
     *
     * @param \Cake\Collection\CollectionInterface<mixed, mixed> $results Results to modify.
     * @return \Cake\Collection\CollectionInterface<mixed, mixed>
     */
    public function groupTranslations(CollectionInterface $results): CollectionInterface
    {
        return $results->map(function ($row) {
            if (!$row instanceof EntityInterface) {
                return $row;
            }

            $translations = $row->has('_i18n') ? $row->get('_i18n') : [];
            if ($translations === []) {
                if ($row->has('_translations')) {
                    return $row;
                }

                $row->set('_translations', [])
                    ->setDirty('_translations', false);
                $row->unset('_i18n');

                return $row;
            }

            $result = [];
            foreach ($translations as $translation) {
                if (!$translation instanceof EntityInterface) {
                    continue;
                }

                $translation->unset('_id');
                $translation->unset('_shadow_id');
                $result[(string)$translation->get('locale')] = $translation;
            }

            $row->set('_translations', $result)
                ->setDirty('_translations', false);
            $row->unset('_i18n');

            return $row;
        });
    }

    /**
     * Helper method used to generated multiple translated field entities
     * out of the data found in the `_translations` property in the passed
     * entity. The result will be put into its `_i18n` property.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity.
     * @return void
     */
    protected function bundleTranslatedFields(EntityInterface $entity): void
    {
        /** @var array<string, \Crustum\Mongo\ODM\Document> $translations */
        $translations = $entity->has('_translations') ? (array)$entity->get('_translations') : [];

        if (!$translations && !$entity->isDirty('_translations')) {
            return;
        }

        if ($entity->isNew()) {
            $key = null;
        } else {
            $primaryKey = (array)$this->collection->getPrimaryKey();
            $key = $entity->get((string)current($primaryKey));
        }

        foreach ($translations as $lang => $translation) {
            if ($translation->isNew()) {
                $update = [
                    'locale' => $lang,
                ];
                if ($key !== null) {
                    $update['_shadow_id'] = $key;
                }

                $translation->patch($update, ['guard' => false]);
            }
        }

        $entity->set('_i18n', $translations);
    }
}
