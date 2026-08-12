<?php
declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

return Architecture::define()
    ->layerPattern('Cache', '/^Cake\\\\Cache\\\\.*$/')
    ->layerPattern('Collection', '/^Cake\\\\Collection\\\\.*$/')
    ->layerPattern('CakeDatabase', '/^Cake\\\\Database\\\\.*$/')
    ->layerPattern('CakeDatasource', '/^Cake\\\\Datasource\\\\.*$/')
    ->layerPattern('Event', '/^Cake\\\\Event\\\\.*$/')
    ->layerPattern('I18n', '/^Cake\\\\I18n\\\\.*$/')
    ->layerPattern('CakeORM', '/^Cake\\\\ORM\\\\.*$/')
    ->layerPattern('Utility', '/^Cake\\\\Utility\\\\.*$/')
    ->layerPattern('Validation', '/^Cake\\\\Validation\\\\.*$/')
    ->layerPattern('Datasource', '/^Crustum\\\\Mongo\\\\Datasource\\\\.*$/')
    ->layerPattern('Database', '/^Crustum\\\\Mongo\\\\Database\\\\.*$/')
    ->layerPattern('Orm', '/^Crustum\\\\Mongo\\\\Orm\\\\.*$/')
    ->layerPattern('ODM', '/^Crustum\\\\Mongo\\\\ODM\\\\.*$/')
    ->ruleset([
        'Database' => ['+Cache', 'Datasource', 'I18n'],
        'Datasource' => ['Cache', 'Collection', 'Database', '+Event', 'Utility'],
        // 'ODM' => ['Collection', 'Database', 'Datasource', 'Event', '+Utility', 'Validation'],
        'Database' => ['+Cache', 'Datasource', 'CakeDatasource', 'CakeDatabase', 'I18n', 'Collection', 'Utility'],
        'Datasource' => ['Cache', 'Collection', 'CakeDatasource', 'Database', '+Event', 'Utility'],
        'ODM' => ['Collection', 'Database', 'CakeDatabase', 'Datasource', 'CakeDatasource', 'Event', '+Utility', 'Validation'],
        'Orm' => ['CakeORM', 'Datasource', 'Database', 'CakeDatasource', 'CakeDatabase', 'ODM', 'Collection', 'Event', '+Utility', 'Validation'],
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
