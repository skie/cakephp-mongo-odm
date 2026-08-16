<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Behavior\Translate;

use Cake\Datasource\QueryInterface;
use Crustum\Mongo\ODM\Locator\LocatorAwareTrait;
use Crustum\Mongo\ODM\Marshaller;
use Crustum\Mongo\ODM\Query\SelectQuery;

/**
 * Base class for translate strategies that store translations in a separate
 * collection (a "shadow" collection).
 *
 * Provides the shared config, locale handling, marshaling map, and the
 * `translations` finder which contains the translation collection and groups
 * its rows under `_translations`.
 */
abstract class AbstractStrategy implements TranslateStrategyInterface
{
    use LocatorAwareTrait;
    use TranslateStrategyTrait {
        buildMarshalMap as private _buildMarshalMap;
    }

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
     * Custom finder method used to retrieve all translations for the found records.
     *
     * Fetched translations can be filtered by locale by passing the `locales` key
     * in the options array. The translations live in a separate collection, so
     * the strategy contains it and groups the rows under `_translations`.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query The original query to modify
     * @param array<string> $locales A list of locales or options with the `locales` key defined
     * @return \Crustum\Mongo\ODM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array>
     */
    public function findTranslations(SelectQuery $query, array $locales = []): SelectQuery
    {
        $targetAlias = $this->translationCollection->getAlias();

        return $query
            ->contain([$targetAlias => function (QueryInterface $query) use ($locales, $targetAlias): QueryInterface {
                if ($locales !== []) {
                    $query->where(["{$targetAlias}.locale IN" => $locales]);
                }

                return $query;
            }])
            ->formatResults($this->groupTranslations(...), SelectQuery::PREPEND);
    }

    /**
     * @param \Crustum\Mongo\ODM\Marshaller<\Cake\Datasource\EntityInterface> $marshaller The marshaller of the collection the behavior is attached to.
     * @param array<string, callable> $map The property map being built.
     * @param array<string, mixed> $options The options array used in the marshaling call.
     * @return array<string, callable> A map of `[property => callable]` of additional properties to marshal.
     */
    public function buildMarshalMap(Marshaller $marshaller, array $map, array $options): array
    {
        $this->translatedFields();

        return $this->_buildMarshalMap($marshaller, $map, $options);
    }

    /**
     * Lazy define and return the main collection fields.
     *
     * @return array<string>
     */
    protected function mainFields(): array
    {
        /** @var array<string> $fields */
        $fields = $this->getConfig('mainCollectionFields');

        if ($fields) {
            return $fields;
        }

        $fields = $this->collection->getSchema()->columns();

        $this->setConfig('mainCollectionFields', $fields);

        return $fields;
    }

    /**
     * Lazy define and return the translation collection fields.
     *
     * @return array<string>
     */
    protected function translatedFields(): array
    {
        $fields = $this->getConfig('fields');

        if ($fields) {
            return $fields;
        }

        $collection = $this->translationCollection;
        $fields = $collection->getSchema()->columns();
        $fields = array_values(array_diff($fields, ['_id', 'id', '_shadow_id', 'locale']));

        $this->setConfig('fields', $fields);

        return $fields;
    }
}
