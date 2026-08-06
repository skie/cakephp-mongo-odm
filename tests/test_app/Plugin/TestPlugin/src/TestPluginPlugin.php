<?php
declare(strict_types=1);

namespace TestPlugin;

use Cake\Core\BasePlugin;
use Cake\Event\EventManagerInterface;
use Cake\Http\MiddlewareQueue;

class TestPluginPlugin extends BasePlugin
{
    public function events(EventManagerInterface $event): EventManagerInterface
    {
        $event->on('TestPlugin.load', function (): void {
        });

        return $event;
    }

    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        $middlewareQueue->add(fn($request, $handler) => $handler->handle($request));

        return $middlewareQueue;
    }
}
