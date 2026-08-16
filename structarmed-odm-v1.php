<?php
declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

/**
 * ODM-only map for crustum/src/ODM. — V1 (first draft)
 *
 * Initial extraction: platform layers registered, ODM sub-layers bucketed from the
 * live IMPORTS matrix. Known limitations of this draft (fixed in v2):
 *   - `ODMQuery ⇄ ODMEagerLoader` silent mutual allow (SelectQuery ↔ EagerLoader)
 *   - `ODMCollection` listed as allowed-lower for sub-layers (not a strict top layer)
 *   - no per-class skips yet — real upward coupling still red
 *
 * Kept as history. Prefer v2 (green) or the current `structarmed-odm.php`.
 */
return Architecture::define()
    ->layerPattern('CakeCache', '/^Cake\\\\Cache\\\\.*$/')
    ->layerPattern('CakeCollection', '/^Cake\\\\Collection\\\\.*$/')
    ->layerPattern('CakeDatabase', '/^Cake\\\\Database\\\\.*$/')
    ->layerPattern('CakeDatasource', '/^Cake\\\\Datasource\\\\.*$/')
    ->layerPattern('CakeEvent', '/^Cake\\\\Event\\\\.*$/')
    ->layerPattern('CakeI18n', '/^Cake\\\\I18n\\\\.*$/')
    ->layerPattern('CakeORM', '/^Cake\\\\ORM\\\\.*$/')
    ->layerPattern('CakeUtility', '/^Cake\\\\Utility\\\\.*$/')
    ->layerPattern('CakeValidation', '/^Cake\\\\Validation\\\\.*$/')
    ->layerPattern('MongoBSON', '/^MongoDB\\\\(BSON|Model)\\\\.*$/')
    ->layerPattern('Database', '/^Crustum\\\\Mongo\\\\Database\\\\.*$/')
    ->layerPattern('Datasource', '/^Crustum\\\\Mongo\\\\Datasource\\\\.*$/')
    ->layerPattern('ODMException', '/^Crustum\\\\Mongo\\\\ODM\\\\Exception\\\\.*$/')
    ->layerPattern('ODMEnum', '/^Crustum\\\\Mongo\\\\ODM\\\\Enum\\\\.*$/')
    ->layerPattern('ODMAttribute', '/^Crustum\\\\Mongo\\\\ODM\\\\Attribute\\\\.*$/')
    ->layerPattern('ODMMapping', '/^Crustum\\\\Mongo\\\\ODM\\\\Mapping\\\\.*$/')
    ->layerPattern('ODMDocument', '/^Crustum\\\\Mongo\\\\ODM\\\\Document$/')
    ->layerPattern('ODMLocator', '/^Crustum\\\\Mongo\\\\ODM\\\\Locator\\\\.*$/')
    ->layerPattern('ODMMarshaller', '/^Crustum\\\\Mongo\\\\ODM\\\\(Marshaller|PropertyMarshalInterface)$/')
    ->layerPattern('ODMRules', '/^Crustum\\\\Mongo\\\\ODM\\\\(RulesChecker|RulesAwareTrait|Rule\\\\.*)$/')
    ->layerPattern('ODMResultSet', '/^Crustum\\\\Mongo\\\\ODM\\\\(ResultSet|ResultSetFactory)$/')
    ->layerPattern('ODMQuery', '/^Crustum\\\\Mongo\\\\ODM\\\\Query\\\\.*$/')
    ->layerPattern('ODMEagerLoader', '/^Crustum\\\\Mongo\\\\ODM\\\\(EagerLoader|EagerLoadable|LazyEagerLoader)$/')
    ->layerPattern('ODMAssociation', '/^Crustum\\\\Mongo\\\\ODM\\\\Association\\\\.*$/')
    ->layerPattern('ODMBehavior', '/^Crustum\\\\Mongo\\\\ODM\\\\(Behavior|BehaviorRegistry|Behavior\\\\.*)$/')
    ->layerPattern('ODMCollection', '/^Crustum\\\\Mongo\\\\ODM\\\\(BaseCollection|CollectionEventsTrait|CollectionRegistry|RulesAwareTrait|AssociationsNormalizerTrait|AssociationCollection|PropertyMarshalInterface)$/')
    ->ruleset([
        'ODMException' => [],
        'ODMEnum' => [],
        'ODMAttribute' => [],
        'ODMMapping' => ['ODMAttribute'],
        'ODMDocument' => ['ODMAttribute'],
        'ODMLocator' => ['ODMCollection', 'ODMException', 'ODMQuery'],
        'ODMMarshaller' => ['ODMAttribute'],
        'ODMRules' => ['ODMAssociation', 'ODMCollection'],
        'ODMResultSet' => ['ODMMapping', 'ODMQuery'],
        'ODMQuery' => ['ODMCollection', 'ODMAssociation', 'ODMEagerLoader', 'ODMResultSet'],
        'ODMEagerLoader' => ['ODMAssociation', 'ODMQuery'],
        'ODMAssociation' => ['ODMCollection', 'ODMDocument', 'ODMLocator', 'ODMQuery'],
        'ODMBehavior' => ['ODMAssociation', 'ODMCollection', 'ODMLocator', 'ODMMarshaller', 'ODMQuery'],
        'ODMCollection' => ['ODMAssociation', 'ODMAttribute', 'ODMBehavior', 'ODMException', 'ODMLocator', 'ODMMapping', 'ODMQuery', 'ODMRules'],
    ])
    ->skipClassViolation(
        'Crustum\\Mongo\\ODM\\Locator\\CollectionAwareTrait',
        'Cake\\ORM\\Table',
    )
    ->skipClassViolation(
        'Crustum\\Mongo\\ODM\\ResultSetFactory',
        'Cake\\ORM\\DtoMapper',
    )
    ->skipClassViolation(
        'Crustum\\Mongo\\ODM\\BaseCollection',
        'Cake\\ORM\\Locator\\LocatorAwareTrait',
    );
