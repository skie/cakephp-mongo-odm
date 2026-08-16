<?php
declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

/**
 * ODM-only map for crustum/src/ODM. — V2 (green, full platform)
 *
 * Milestone: first GREEN run. `ODMCollection` is a strict top layer; every sub-layer
 * is granted the platform set it depends on (Cake\*, Database, Datasource, MongoBSON);
 * genuine intra-ODM upward edges (cycles) are CATCH-named per-class via
 * `skipClassViolation` — the ruleset itself stays a strict top-down DAG.
 *
 * Layers: ODMCollection → ODMBehavior → ODMAssociation → ODMEagerLoader → ODMQuery →
 * ODMResultSet → ODMRules → ODMMarshaller → ODMLocator → ODMMapping → ODMDocument →
 * {ODMAttribute, ODMEnum, ODMException}
 *
 * Kept as history. Prefer the current `structarmed-odm.php` (ODM-only, no skips —
 * real state visible) or v3 (ODM-only, green with skips).
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
        'ODMException' => ['CakeDatasource', 'CakeUtility'],
        'ODMEnum' => ['CakeUtility'],
        'ODMAttribute' => ['CakeUtility'],
        'ODMMapping' => ['ODMAttribute', 'CakeUtility', 'Database', 'MongoBSON'],
        'ODMDocument' => ['ODMAttribute', 'CakeDatasource', 'MongoBSON'],
        'ODMLocator' => ['ODMException', 'ODMQuery', 'CakeDatabase', 'CakeDatasource', 'CakeORM', 'CakeUtility'],
        'ODMMarshaller' => ['ODMAttribute', 'CakeValidation', 'Database', 'CakeDatasource'],
        'ODMRules' => ['ODMAssociation', 'CakeDatabase', 'CakeDatasource', 'CakeEvent', 'CakeUtility', 'CakeValidation'],
        'ODMResultSet' => ['ODMMapping', 'ODMQuery', 'CakeCollection', 'CakeDatasource', 'CakeI18n', 'CakeORM', 'Database', 'MongoBSON'],
        'ODMQuery' => ['ODMAssociation', 'ODMResultSet', 'CakeCollection', 'CakeDatabase', 'CakeDatasource', 'Database', 'MongoBSON'],
        'ODMEagerLoader' => ['ODMAssociation', 'CakeDatabase', 'CakeDatasource', 'MongoBSON'],
        'ODMAssociation' => ['ODMDocument', 'ODMLocator', 'ODMQuery', 'CakeDatabase', 'CakeDatasource', 'CakeUtility', 'CakeValidation', 'Database', 'MongoBSON'],
        'ODMBehavior' => ['ODMAssociation', 'ODMException', 'ODMLocator', 'ODMMarshaller', 'ODMQuery', 'CakeCollection', 'CakeDatasource', 'CakeEvent', 'CakeI18n', 'CakeUtility', 'Database', 'MongoBSON'],
        'ODMCollection' => [
            'ODMAssociation', 'ODMAttribute', 'ODMBehavior', 'ODMDocument', 'ODMEagerLoader',
            'ODMEnum', 'ODMException', 'ODMLocator', 'ODMMapping', 'ODMMarshaller',
            'ODMQuery', 'ODMResultSet', 'ODMRules',
            'CakeCache', 'CakeCollection', 'CakeDatabase', 'CakeDatasource', 'CakeEvent',
            'CakeI18n', 'CakeORM', 'CakeUtility', 'CakeValidation', 'Database', 'Datasource', 'MongoBSON',
        ],
    ])
    // -------------------------------------------------------------------------
    // CATCH: intra-ODM upward edges (cycles) — documented in the docblock, do not widen.
    // -------------------------------------------------------------------------
    // ODMAssociation → ODMCollection
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Association', 'Crustum\\Mongo\\ODM\\BaseCollection')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Association\\BelongsTo', 'Crustum\\Mongo\\ODM\\BaseCollection')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Association\\BelongsToMany', 'Crustum\\Mongo\\ODM\\BaseCollection')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Association\\DBRef', 'Crustum\\Mongo\\ODM\\BaseCollection')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Association\\Embedded', 'Crustum\\Mongo\\ODM\\BaseCollection')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Association\\EmbedMany', 'Crustum\\Mongo\\ODM\\BaseCollection')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Association\\EmbedOne', 'Crustum\\Mongo\\ODM\\BaseCollection')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Association\\HasMany', 'Crustum\\Mongo\\ODM\\BaseCollection')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Association\\HasOne', 'Crustum\\Mongo\\ODM\\BaseCollection')
    // ODMBehavior → ODMCollection
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Behavior', 'Crustum\\Mongo\\ODM\\BaseCollection')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\BehaviorRegistry', 'Crustum\\Mongo\\ODM\\BaseCollection')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Behavior\\CounterCacheBehavior', 'Crustum\\Mongo\\ODM\\BaseCollection')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Behavior\\SoftDeleteBehavior', 'Crustum\\Mongo\\ODM\\BaseCollection')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Behavior\\TimestampBehavior', 'Crustum\\Mongo\\ODM\\BaseCollection')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Behavior\\TranslateBehavior', 'Crustum\\Mongo\\ODM\\BaseCollection')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Behavior\\Translate\\AbstractStrategy', 'Crustum\\Mongo\\ODM\\BaseCollection')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Behavior\\Translate\\ShadowCollectionStrategy', 'Crustum\\Mongo\\ODM\\BaseCollection')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Behavior\\Translate\\TranslateStrategyInterface', 'Crustum\\Mongo\\ODM\\BaseCollection')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Behavior\\Translate\\TranslateStrategyTrait', 'Crustum\\Mongo\\ODM\\BaseCollection')
    // ODMBehavior → ODMDocument
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Behavior\\Translate\\AbstractStrategy', 'Crustum\\Mongo\\ODM\\Document')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Behavior\\Translate\\ShadowCollectionStrategy', 'Crustum\\Mongo\\ODM\\Document')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Behavior\\Translate\\TranslateStrategyTrait', 'Crustum\\Mongo\\ODM\\Document')
    // ODMQuery → ODMCollection
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Query\\CommonQueryTrait', 'Crustum\\Mongo\\ODM\\BaseCollection')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Query\\SelectQuery', 'Crustum\\Mongo\\ODM\\BaseCollection')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Query\\UnhydratedSelectQuery', 'Crustum\\Mongo\\ODM\\BaseCollection')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Query\\InsertQuery', 'Crustum\\Mongo\\ODM\\BaseCollection')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Query\\UpdateQuery', 'Crustum\\Mongo\\ODM\\BaseCollection')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Query\\DeleteQuery', 'Crustum\\Mongo\\ODM\\BaseCollection')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Query\\QueryFactory', 'Crustum\\Mongo\\ODM\\BaseCollection')
    // ODMQuery → ODMEagerLoader
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Query\\SelectQuery', 'Crustum\\Mongo\\ODM\\EagerLoader')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Query\\UnhydratedSelectQuery', 'Crustum\\Mongo\\ODM\\EagerLoader')
    // ODMEagerLoader → ODMQuery / ODMCollection / ODMDocument
    ->skipClassViolation('Crustum\\Mongo\\ODM\\EagerLoader', ['Crustum\\Mongo\\ODM\\Query\\SelectQuery', 'Crustum\\Mongo\\ODM\\BaseCollection', 'Crustum\\Mongo\\ODM\\Document'])
    ->skipClassViolation('Crustum\\Mongo\\ODM\\LazyEagerLoader', ['Crustum\\Mongo\\ODM\\Query\\SelectQuery', 'Crustum\\Mongo\\ODM\\BaseCollection'])
    // ODMResultSet → ODMCollection / ODMAssociation / ODMDocument
    ->skipClassViolation('Crustum\\Mongo\\ODM\\ResultSet', [
        'Crustum\\Mongo\\ODM\\BaseCollection',
        'Crustum\\Mongo\\ODM\\Association\\BelongsToMany',
        'Crustum\\Mongo\\ODM\\Association\\Embedded',
        'Crustum\\Mongo\\ODM\\Association\\HasMany',
        'Crustum\\Mongo\\ODM\\Document',
    ])
    ->skipClassViolation('Crustum\\Mongo\\ODM\\ResultSetFactory', 'Crustum\\Mongo\\ODM\\Document')
    // ODMRules → ODMCollection
    ->skipClassViolation('Crustum\\Mongo\\ODM\\RulesChecker', 'Crustum\\Mongo\\ODM\\BaseCollection')
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Rule\\LinkConstraint', 'Crustum\\Mongo\\ODM\\BaseCollection')
    // ODMMarshaller → ODMCollection / ODMDocument
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Marshaller', ['Crustum\\Mongo\\ODM\\BaseCollection', 'Crustum\\Mongo\\ODM\\Document'])
    // ODMLocator → ODMCollection
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Locator\\CollectionLocator', [
        'Crustum\\Mongo\\ODM\\BaseCollection',
        'Crustum\\Mongo\\ODM\\AssociationCollection',
    ])
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Locator\\CollectionAwareTrait', 'Crustum\\Mongo\\ODM\\BaseCollection')
    // ODMDocument → ODMAssociation
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Document', 'Crustum\\Mongo\\ODM\\Association\\Embedded')
    // Legacy Cake ORM bridge traits
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
