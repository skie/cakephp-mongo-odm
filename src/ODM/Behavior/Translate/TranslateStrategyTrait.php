<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Behavior\Translate;

use Cake\Core\InstanceConfigTrait;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\I18n\I18n;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\ODM\Marshaller;

/**
 * Contains common code needed by TranslateBehavior strategy classes.
 *
 * @require-implements \Crustum\Mongo\ODM\Behavior\Translate\TranslateStrategyInterface
 */
trait TranslateStrategyTrait
{
    use InstanceConfigTrait;

    /**
     * Collection instance
     *
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected BaseCollection $collection;

    /**
     * The locale name that will be used to override fields in the bound collection
     * from the translations collection
     *
     * @var string|null
     */
    protected ?string $locale = null;

    /**
     * Instance of BaseCollection responsible for translating
     *
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected BaseCollection $translationCollection;

    /**
     * Return translation collection instance.
     *
     * @return \Crustum\Mongo\ODM\BaseCollection
     */
    public function getTranslationCollection(): BaseCollection
    {
        return $this->translationCollection;
    }

    /**
     * Initializes the runtime config from the defaults.
     *
     * Cake 5.4's `InstanceConfigTrait::setConfig()` triggers `initCfg()` which
     * reads `$_defaultConfig`; this class declares `$defaultConfig` (Cake 6
     * naming), so the config is initialized here the same way the crustum
     * `Behavior` base does.
     *
     * @param array<string, mixed> $config Runtime configuration.
     * @return void
     */
    protected function initConfig(array $config): void
    {
        $this->_config = array_replace($this->defaultConfig, $config);
        $this->_configInitialized = true;
    }

    /**
     * Sets the locale to be used.
     *
     * When fetching records, the content for the locale set via this method,
     * and likewise when saving data, it will save the data in that locale.
     *
     * Note that in case an entity has a `_locale` property set, that locale
     * will win over the locale set via this method (and over the globally
     * configured one for that matter)!
     *
     * @param string|null $locale The locale to use for fetching and saving
     *   records. Pass `null` in order to unset the current locale, and to make
     *   the behavior falls back to using the globally configured locale.
     * @return $this
     */
    public function setLocale(?string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    /**
     * Returns the current locale.
     *
     * If no locale has been explicitly set via `setLocale()`, this method will return
     * the currently configured global locale excluding any options set after @.
     *
     * @return string
     * @see \Cake\I18n\I18n::getLocale()
     * @see \Crustum\Mongo\ODM\Behavior\TranslateBehavior::setLocale()
     */
    public function getLocale(): string
    {
        return $this->locale ?: explode('@', I18n::getLocale())[0];
    }

    /**
     * Unset empty translations to avoid persistence.
     *
     * Should only be called if $this->_config['allowEmptyTranslations'] is false.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity to check for empty translations fields inside.
     * @return void
     */
    protected function unsetEmptyFields(EntityInterface $entity): void
    {
        if (!$entity->has('_translations')) {
            return;
        }

        /** @var array<\Cake\Datasource\EntityInterface> $translations */
        $translations = $entity->get('_translations');
        foreach ($translations as $locale => $translation) {
            $fields = $translation->extract($this->getConfig()['fields'], false);
            foreach ($fields as $field => $value) {
                if ($value === null || $value === '') {
                    $translation->unset($field);
                }
            }

            $translation = $translation->extract($this->getConfig()['fields']);

            // If now, the current locale property is empty,
            // unset it completely.
            if (array_filter($translation) === []) {
                unset($translations[$locale]);
            }
        }

        // If now, the whole $translations is empty, unset _translations property completely
        if ($translations === []) {
            $entity->unset('_translations');
        } else {
            $entity->set('_translations', $translations);
        }
    }

    /**
     * Build a set of properties that should be included in the marshaling process.

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
        if (isset($options['translations']) && !$options['translations']) {
            return [];
        }

        return [
            '_translations' => function ($value, EntityInterface $entity) use ($marshaller, $options) {
                if (!is_array($value)) {
                    return null;
                }

                /** @var array<string, \Cake\Datasource\EntityInterface> $translations */
                $translations = $entity->has('_translations') ? (array)$entity->get('_translations') : [];

                $options['validate'] = $this->getConfig()['validator'];
                $errors = [];
                foreach ($value as $language => $fields) {
                    $translation = $translations[$language] ?? $this->collection->newEmptyDocument();
                    assert($translation instanceof Document);
                    $translations[$language] = $translation;
                    $marshaller->merge($translation, $fields, $options);

                    $translationErrors = $translation->getErrors();
                    if ($translationErrors !== []) {
                        $errors[$language] = $translationErrors;
                    }
                }

                // Set errors into the root entity, so validation errors match the original form data position.
                if ($errors !== []) {
                    $entity->setErrors(['_translations' => $errors]);
                }

                return $translations;
            },
        ];
    }

    /**
     * Unsets the temporary `_i18n` property after the entity has been saved
     *
     * @param \Cake\Event\EventInterface<\Crustum\Mongo\ODM\BaseCollection> $event The beforeSave event that was fired
     * @param \Cake\Datasource\EntityInterface $entity The entity that is going to be saved
     * @return void
     */
    public function afterSave(EventInterface $event, EntityInterface $entity): void
    {
        $entity->unset('_i18n');
    }
}
