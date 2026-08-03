<?php
declare(strict_types=1);

use Cake\Routing\RouteBuilder;

/**
 * Mongo plugin routes.
 *
 * @var \Cake\Routing\RouteBuilder $routes
 */
$routes->plugin(
    'Crustum/Mongo',
    ['path' => '/mongo'],
    function (RouteBuilder $builder): void {
        $builder->fallbacks();
    }
);
