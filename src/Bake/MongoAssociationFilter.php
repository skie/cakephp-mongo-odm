<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/bake (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Bake;

use Cake\Utility\Inflector;
use Crustum\Mongo\ODM\Association;
use Crustum\Mongo\ODM\Association\BelongsTo;
use Crustum\Mongo\ODM\Association\BelongsToMany;
use Crustum\Mongo\ODM\Association\HasMany;
use Crustum\Mongo\ODM\Association\HasOne;
use Crustum\Mongo\ODM\BaseCollection;
use Exception;

/**
 * Builds the association data format consumed by bake templates, mirroring
 * `Bake\Utility\Model\AssociationFilter` but for `BaseCollection`.
 */
class MongoAssociationFilter
{
    /**
     * Association type → class map.
     *
     * @var array<string, class-string<\Crustum\Mongo\ODM\Association>>
     */
    protected const TYPE_CLASSES = [
        'BelongsTo' => BelongsTo::class,
        'HasOne' => HasOne::class,
        'HasMany' => HasMany::class,
        'BelongsToMany' => BelongsToMany::class,
    ];

    /**
     * Returns the filtered associations for templates.
     *
     * Format per type (`BelongsTo`, `HasOne`, `HasMany`, `BelongsToMany`):
     * ```
     * [alias => ['property', 'variable', 'primaryKey', 'displayField', 'foreignKey', 'alias', 'controller', 'fields', 'navLink']]
     * ```
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $model The collection.
     * @return array<string, array<string, array<string, mixed>>> associations
     */
    public function filterAssociations(BaseCollection $model): array
    {
        $associations = [];
        $junctionAliases = $this->belongsToManyJunctionAliases($model);

        foreach (static::TYPE_CLASSES as $type => $class) {
            foreach ($model->associations()->type($class) as $assoc) {
                $target = $assoc->getTarget();
                $assocName = $assoc->getName();
                $alias = $target->getAlias();
                if ($type === 'HasMany' && in_array($alias, $junctionAliases, true)) {
                    continue;
                }

                $navLink = true;
                if ($model::class === BaseCollection::class) {
                    $navLink = false;
                }

                try {
                    $foreignKey = (array)$assoc->getForeignKey();
                    $associations[$type][$assocName] = [
                        'property' => $assoc->getProperty(),
                        'variable' => Inflector::variable($assocName),
                        'primaryKey' => (array)$target->getPrimaryKey(),
                        'displayField' => $target->getDisplayField(),
                        'foreignKey' => $assoc->getForeignKey(),
                        'alias' => $alias,
                        'controller' => $this->controllerFor($assoc, $alias),
                        'fields' => array_values(array_diff(
                            $target->getSchema()->columns(),
                            $foreignKey,
                        )),
                        'navLink' => $navLink,
                    ];
                } catch (Exception) {
                    // Skip bogus association names.
                }
            }
        }

        return $associations;
    }

    /**
     * Removes HasMany aliases that are already BelongsToMany junction collections.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $collection Collection.
     * @param array<string> $aliases HasMany aliases.
     * @return array<string>
     */
    public function filterHasManyAssociationsAliases(BaseCollection $collection, array $aliases): array
    {
        return array_values(array_diff($aliases, $this->belongsToManyJunctionAliases($collection)));
    }

    /**
     * Junction aliases for every BelongsToMany association on the collection.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $collection Collection.
     * @return array<string>
     */
    protected function belongsToManyJunctionAliases(BaseCollection $collection): array
    {
        /** @var array<\Crustum\Mongo\ODM\Association\BelongsToMany> $associations */
        $associations = $collection->associations()->type(BelongsToMany::class);

        return array_map(
            fn(BelongsToMany $association): string => $association->junction()->getAlias(),
            $associations,
        );
    }

    /**
     * Derives the controller name from an association target.
     *
     * @param \Crustum\Mongo\ODM\Association $assoc The association.
     * @param string $alias The target alias.
     * @return string
     */
    protected function controllerFor(Association $assoc, string $alias): string
    {
        $className = $assoc->getClassName();
        if ($className === '') {
            return $alias;
        }

        $pos = strrpos($className, '\\');
        if ($pos !== false) {
            $className = substr($className, $pos + 1);
        }

        $className = (string)preg_replace('/(.*)Collection$/', '\1', $className);
        if ($className === '') {
            return $alias;
        }

        return $className;
    }
}
