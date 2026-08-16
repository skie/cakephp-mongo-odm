<?php
declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

/**
 * ODM-only map for crustum/src/ODM. — V3 (ODM-only, green with CATCH skips)
 *
 * Milestone: green with ONLY the 13 ODM sub-layers — no Cake\* / Database /
 * Datasource / MongoBSON platform layers, so only intra-ODM arrows are shown.
 * Everything external is unscanned (no arrows). Genuine intra-ODM upward edges
 * (cycles) are CATCH-named per-class via `skipClassViolation`.
 *
 * Kept as history. Prefer the current `structarmed-odm.php` (same layers, skips
 * removed — real state visible as red).
 */
return Architecture::define()
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
        'ODMLocator' => ['ODMException', 'ODMQuery'],
        'ODMMarshaller' => ['ODMAttribute'],
        'ODMRules' => ['ODMAssociation'],
        'ODMResultSet' => ['ODMMapping', 'ODMQuery'],
        'ODMQuery' => ['ODMAssociation', 'ODMResultSet'],
        'ODMEagerLoader' => ['ODMAssociation'],
        'ODMAssociation' => ['ODMDocument', 'ODMLocator', 'ODMQuery'],
        'ODMBehavior' => ['ODMAssociation', 'ODMException', 'ODMLocator', 'ODMMarshaller', 'ODMQuery'],
        'ODMCollection' => [
            'ODMAssociation', 'ODMAttribute', 'ODMBehavior', 'ODMDocument', 'ODMEagerLoader',
            'ODMEnum', 'ODMException', 'ODMLocator', 'ODMMapping', 'ODMMarshaller',
            'ODMQuery', 'ODMResultSet', 'ODMRules',
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
    ->skipClassViolation('Crustum\\Mongo\\ODM\\Document', 'Crustum\\Mongo\\ODM\\Association\\Embedded');
