<?php
declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

/**
 * ODM-only map for crustum/src/ODM.
 *
 * Only ODM sub-layers are registered. Everything else (Cake\*, Crustum\Database,
 * Crustum\Datasource, MongoDB BSON/Model) is EXTERNAL — not checked, no arrows.
 *
 * Layer order (top → bottom):
 *   ODMCollection → ODMBehavior → ODMAssociation → ODMEagerLoader → ODMQuery →
 *   ODMResultSet → ODMRules → ODMMarshaller → ODMLocator → ODMMapping →
 *   ODMDocument → {ODMAttribute, ODMEnum, ODMException}
 *
 * No skips: violations below are the REAL as-built coupling (mostly upward edges
 * to ODMCollection). Treat as backlog — do not widen ruleset to hide them.
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
    ]);
