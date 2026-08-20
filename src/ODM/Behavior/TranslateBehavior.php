<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Behavior;

use ArrayObject;
use Cake\Event\EventInterface;
use Cake\I18n\I18n;
use Cake\Utility\Inflector;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Behavior;
use Crustum\Mongo\ODM\Behavior\Translate\ShadowCollectionStrategy;
use Crustum\Mongo\ODM\Behavior\Translate\TranslateStrategyInterface;
use Crustum\Mongo\ODM\Marshaller;
use Crustum\Mongo\ODM\PropertyMarshalInterface;
use Crustum\Mongo\ODM\Query\SelectQuery;
use function Cake\Core\namespaceSplit;

/**
 * This behavior provides a way to translate dynamic data by keeping translations
 * in a separate collection linked to the original record from another one. Translated
 * fields can be configured to override those in the main collection when fetched or
 * put aside into another property for the same entity.
 *
 * If you wish to override fields, you need to call the `locale` method in this
 * behavior for setting the language you want to fetch from the translations collection.
 *
 * If you want to bring all or certain languages for each of the fetched records,
 * you can use the custom `translations` finders that is exposed to the collection.
 *
 * @ported-from \Cake\ORM\Behavior\TranslateBehavior
 */
class TranslateBehavior extends Behavior implements PropertyMarshalInterface
{
    /**
     * Default config
     *
     * These are merged with user-provided configuration when the behavior is used.
     *
     * @var array<string, mixed>
     */
    protected array $defaultConfig = [
        'implementedFinders' => ['translations' => 'findTranslations'],
        'fields' => [],
        'defaultLocale' => null,
        'referenceName' => '',
        'allowEmptyTranslations' => true,
        'onlyTranslated' => false,
        'strategy' => 'subquery',
        'collectionLocator' => null,
        'validator' => false,
        'strategyClass' => null,
    ];

    /**
     * Default strategy class name.
     *
     * @var class-string<\Crustum\Mongo\ODM\Behavior\Translate\TranslateStrategyInterface>
     */
    protected static string $defaultStrategyClass = ShadowCollectionStrategy::class;

    /**
     * Translation strategy instance.
     *
     * @var \Crustum\Mongo\ODM\Behavior\Translate\TranslateStrategyInterface|null
     */
    protected ?TranslateStrategyInterface $strategy = null;

    /**
     * Constructor
     *
     * ### Options
     *
     * - `fields`: List of fields which need to be translated. Providing this fields
     *   list is mandatory. If the fields list is empty when
     *   using `ShadowCollectionStrategy` then the list will be auto generated based on
     *   shadow collection schema.
     * - `defaultLocale`: The locale which is treated as default by the behavior.
     *   Fields values for default locale will be stored in the primary collection itself
     *   and the rest in translation collection. If not explicitly set the value of
     *   `I18n::getDefaultLocale()` will be used to get default locale.
     *   If you do not want any default locale and want translated fields
     *   for all locales to be stored in translation collection then set this config
     *   to empty string `''`.
     * - `allowEmptyTranslations`: By default if a record has been translated and
     *   stored as an empty string the translate behavior will take and use this
     *   value to overwrite the original field value. If you don't want this behavior
     *   then set this option to `false`.
     * - `validator`: The validator that should be used when translation records
     *   are created/modified. Default `null`.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $collection The collection this behavior is attached to.
     * @param array<string, mixed> $config The config for this behavior.
     */
    public function __construct(BaseCollection $collection, array $config = [])
    {
        $config += [
            'defaultLocale' => I18n::getDefaultLocale(),
            'referenceName' => $this->referenceName($collection),
            'collectionLocator' => $collection->associations()->getCollectionLocator(),
        ];

        parent::__construct($collection, $config);
    }

    /**
     * Initialize hook
     *
     * @param array<string, mixed> $config The config for this behavior.
     * @return void
     */
    public function initialize(array $config): void
    {
        $this->getStrategy();
    }

    /**
     * Set default strategy class name.
     *
     * @param class-string<\Crustum\Mongo\ODM\Behavior\Translate\TranslateStrategyInterface> $class Class name.
     * @return void
     * @since 4.0.0
     */
    public static function setDefaultStrategyClass(string $class): void
    {
        static::$defaultStrategyClass = $class;
    }

    /**
     * Get default strategy class name.
     *
     * @return class-string<\Crustum\Mongo\ODM\Behavior\Translate\TranslateStrategyInterface>
     * @since 4.0.0
     */
    public static function getDefaultStrategyClass(): string
    {
        return static::$defaultStrategyClass;
    }

    /**
     * Get strategy class instance.
     *
     * @return \Crustum\Mongo\ODM\Behavior\Translate\TranslateStrategyInterface
     * @since 4.0.0
     */
    public function getStrategy(): TranslateStrategyInterface
    {
        return $this->strategy ??= $this->createStrategy();
    }

    /**
     * Create strategy instance.
     *
     * @return \Crustum\Mongo\ODM\Behavior\Translate\TranslateStrategyInterface
     * @since 4.0.0
     */
    protected function createStrategy(): TranslateStrategyInterface
    {
        $config = array_diff_key(
            $this->getConfig(),
            ['implementedFinders', 'strategyClass'],
        );
        /** @var class-string<\Crustum\Mongo\ODM\Behavior\Translate\TranslateStrategyInterface> $className */
        $className = $this->getConfig('strategyClass', static::$defaultStrategyClass);

        return new $className($this->collection, $config);
    }

    /**
     * Set strategy class instance.
     *
     * @param \Crustum\Mongo\ODM\Behavior\Translate\TranslateStrategyInterface $strategy Strategy class instance.
     * @return $this
     * @since 4.0.0
     */
    public function setStrategy(TranslateStrategyInterface $strategy): static
    {
        $this->strategy = $strategy;

        return $this;
    }

    /**
     * Gets the Model callbacks this behavior is interested in.
     *
     * @return array<string, mixed>
     */
    public function implementedEvents(): array
    {
        return [
            'Collection.beforeFind' => 'beforeFind',
            'Collection.beforeMarshal' => 'beforeMarshal',
            'Collection.beforeSave' => 'beforeSave',
            'Collection.afterSave' => 'afterSave',
        ];
    }

    /**
     * Hoist fields for the default locale under `_translations` key to the root
     * in the data.
     *
     * This allows `_translations.{locale}.field_name` type naming even for the
     * default locale in forms.
     *
     * @param \Cake\Event\EventInterface<\Crustum\Mongo\ODM\BaseCollection> $event The event that was fired.
     * @param \ArrayObject<string, mixed> $data The data being marshalled.
     * @param \ArrayObject<string, mixed> $options The options for marshalling.
     * @return void
     */
    public function beforeMarshal(EventInterface $event, ArrayObject $data, ArrayObject $options): void
    {
        if (isset($options['translations']) && !$options['translations']) {
            return;
        }

        $defaultLocale = $this->getConfig('defaultLocale');
        if (!isset($data['_translations'][$defaultLocale])) {
            return;
        }

        foreach ($data['_translations'][$defaultLocale] as $field => $value) {
            $data[$field] = $value;
        }

        unset($data['_translations'][$defaultLocale]);
    }

    /**
     * {@inheritDoc}
     *
     * Add in `_translations` marshaling handlers. You can disable marshaling
     * of translations by setting `'translations' => false` in the options
     * provided to `BaseCollection::newDocument()` or `BaseCollection::patchDocument()`.
     *
     * @param \Crustum\Mongo\ODM\Marshaller<\Cake\Datasource\EntityInterface> $marshaller The marshaller of the collection the behavior is attached to.
     * @param array<string, callable> $map The property map being built.
     * @param array<string, mixed> $options The options array used in the marshaling call.
     * @return array<string, callable> A map of `[property => callable]` of additional properties to marshal.
     */
    public function buildMarshalMap(Marshaller $marshaller, array $map, array $options): array
    {
        return $this->getStrategy()->buildMarshalMap($marshaller, $map, $options);
    }

    /**
     * Sets the locale that should be used for all future find and save operations on
     * the collection where this behavior is attached to.
     *
     * When fetching records, the behavior will include the content for the locale set
     * via this method, and likewise when saving data, it will save the data in that
     * locale.
     *
     * Note that in case an entity has a `_locale` property set, that locale will win
     * over the locale set via this method (and over the globally configured one for
     * that matter)!
     *
     * @param string|null $locale The locale to use for fetching and saving records. Pass `null`
     * in order to unset the current locale, and to make the behavior falls back to using the
     * globally configured locale.
     * @return $this
     * @see \Crustum\Mongo\ODM\Behavior\TranslateBehavior::getLocale()
     */
    public function setLocale(?string $locale): static
    {
        $this->getStrategy()->setLocale($locale);

        return $this;
    }

    /**
     * Returns the current locale.
     *
     * If no locale has been explicitly set via `setLocale()`, this method will return
     * the currently configured global locale.
     *
     * @return string
     * @see \Cake\I18n\I18n::getLocale()
     * @see \Crustum\Mongo\ODM\Behavior\TranslateBehavior::setLocale()
     */
    public function getLocale(): string
    {
        return $this->getStrategy()->getLocale();
    }

    /**
     * Returns a fully aliased field name for translated fields.
     *
     * If the requested field is configured as a translation field, the `content`
     * field with an alias of a corresponding association is returned. Collection-aliased
     * field name is returned for all other fields.
     *
     * @param string $field Field name to be aliased.
     * @return string
     */
    public function translationField(string $field): string
    {
        return $this->getStrategy()->translationField($field);
    }

    /**
     * Custom finder method used to retrieve all translations for the found records.
     * Fetched translations can be filtered by locale by passing the `locales` key
     * in the options array.
     *
     * Translated values will be found for each entity under the property `_translations`,
     * containing an array indexed by locale name.
     *
     * ### Example:
     *
     * ```
     * $article = $articles->find('translations', locales: ['eng', 'deu'])->first();
     * $englishTranslatedFields = $article->get('_translations')['eng'];
     * ```
     *
     * If the `locales` array is not passed, it will bring all translations found
     * for each record.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query The original query to modify
     * @param array<string> $locales A list of locales or options with the `locales` key defined
     * @return \Crustum\Mongo\ODM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array>
     */
    public function findTranslations(SelectQuery $query, array $locales = []): SelectQuery
    {
        return $this->getStrategy()->findTranslations($query, $locales);
    }

    /**
     * Proxy method calls to strategy class instance.
     *
     * @param string $method Method name.
     * @param array<int, mixed> $args Method arguments.
     * @return mixed
     */
    public function __call(string $method, array $args): mixed
    {
        return $this->getStrategy()->{$method}(...$args);
    }

    /**
     * Determine the reference name to use for a given collection
     *
     * The reference name is usually derived from the class name of the collection object
     * (ArticlesCollection -> Articles), however for generic fallback instances it is
     * derived from the registry alias.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $collection The collection class to get a reference name for.
     * @return string
     */
    protected function referenceName(BaseCollection $collection): string
    {
        $parts = namespaceSplit($collection::class);
        $class = end($parts);
        if ($class !== 'BaseCollection') {
            $name = match (true) {
                str_ends_with($class, 'Collection') => substr($class, 0, -10),
                default => $class,
            };
            if ($name !== '') {
                return $name;
            }
        }

        $name = $collection->getCollection() ?: $collection->getAlias();

        return Inflector::camelize($name);
    }
}
