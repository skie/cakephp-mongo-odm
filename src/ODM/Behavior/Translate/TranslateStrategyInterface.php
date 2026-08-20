<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Behavior\Translate;

use ArrayObject;
use Cake\Collection\CollectionInterface;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\ResultSetInterface;
use Cake\Event\EventInterface;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\PropertyMarshalInterface;
use Crustum\Mongo\ODM\Query\SelectQuery;

/**
 * This interface describes the methods for translate behavior strategies.
 *
 * @ported-from \Cake\ORM\Behavior\Translate\TranslateStrategyInterface
 */
interface TranslateStrategyInterface extends PropertyMarshalInterface
{
    /**
     * Return translation collection instance.
     *
     * @return \Crustum\Mongo\ODM\BaseCollection
     */
    public function getTranslationCollection(): BaseCollection;

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
    public function setLocale(?string $locale): static;

    /**
     * Returns the current locale.
     *
     * If no locale has been explicitly set via `setLocale()`, this method will
     * return the currently configured global locale.
     *
     * @return string
     */
    public function getLocale(): string;

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
    public function translationField(string $field): string;

    /**
     * Custom finder method used to retrieve all translations for the found records.
     *
     * The strategy decides how translations are attached to the query (a
     * separate collection via containment, or embedded data in the document).
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query The original query to modify
     * @param array<string> $locales A list of locales or options with the `locales` key defined
     * @return \Crustum\Mongo\ODM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array>
     */
    public function findTranslations(SelectQuery $query, array $locales = []): SelectQuery;

    /**
     * Modifies the results from a collection find in order to merge full translation records
     * into each entity under the `_translations` key.
     *
     * @param \Cake\Datasource\ResultSetInterface<array-key, \Cake\Datasource\EntityInterface|array<string, mixed>> $results Results to modify.
     * @return \Cake\Collection\CollectionInterface<mixed, mixed>
     */
    public function groupTranslations(ResultSetInterface $results): CollectionInterface;

    /**
     * Callback method that listens to the `beforeFind` event in the bound
     * collection. It modifies the passed query by eager loading the translated fields
     * and adding a formatter to copy the values into the main collection records.
     *
     * @param \Cake\Event\EventInterface<\Crustum\Mongo\ODM\BaseCollection> $event The beforeFind event that was fired.
     * @param \Crustum\Mongo\ODM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query Query
     * @param \ArrayObject<string, mixed> $options The options for the query
     * @return void
     */
    public function beforeFind(EventInterface $event, SelectQuery $query, ArrayObject $options): void;

    /**
     * Modifies the entity before it is saved so that translated fields are persisted
     * in the database too.
     *
     * @param \Cake\Event\EventInterface<\Crustum\Mongo\ODM\BaseCollection> $event The beforeSave event that was fired
     * @param \Cake\Datasource\EntityInterface $entity The entity that is going to be saved
     * @param \ArrayObject<string, mixed> $options the options passed to the save method
     * @return void
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void;

    /**
     * Unsets the temporary `_i18n` property after the entity has been saved
     *
     * @param \Cake\Event\EventInterface<\Crustum\Mongo\ODM\BaseCollection> $event The beforeSave event that was fired
     * @param \Cake\Datasource\EntityInterface $entity The entity that is going to be saved
     * @return void
     */
    public function afterSave(EventInterface $event, EntityInterface $entity): void;
}
