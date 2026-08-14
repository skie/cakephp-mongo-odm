<?php
declare(strict_types=1);

namespace TestApp;

use Cake\Http\BaseApplication;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\RouteBuilder;

/**
 * Application used by bake command tests.
 *
 * Loads the Bake + TwigView plugins (needed for bake command discovery and
 * template rendering) on top of Crustum/Mongo, without the shared test-app
 * bootstrap side effects (no double plugin add, no HTTP wiring).
 */
class BakeTestApplication extends BaseApplication
{
    /**
     * @inheritDoc
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue;
    }

    /**
     * @inheritDoc
     */
    public function routes(RouteBuilder $routes): void
    {
    }

    /**
     * @inheritDoc
     */
    public function bootstrap(): void
    {
        $this->addPlugin('Cake/TwigView');
        $this->addPlugin('Bake');
        $this->addPlugin('Crustum/Mongo', [
            'bootstrap' => true,
            'routes' => true,
        ]);
    }
}
