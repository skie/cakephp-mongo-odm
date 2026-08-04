<?php
declare(strict_types=1);

namespace Crustum\Mongo;

use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\ContainerInterface;
use Cake\Core\PluginApplicationInterface;
use Cake\Datasource\FactoryLocator;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\RouteBuilder;
use Crustum\Mongo\ODM\Locator\CollectionLocator;
use Crustum\PluginManifest\Manifest\ManifestInterface;
use Crustum\PluginManifest\Manifest\ManifestTrait;
use Override;

/**
 * Plugin for Crustum/Mongo
 *
 * @uses \Crustum\PluginManifest\Manifest\ManifestTrait
 */
class MongoPlugin extends BasePlugin implements ManifestInterface
{
    use ManifestTrait;

    /**
     * @inheritDoc
     */
    #[Override]
    public function bootstrap(PluginApplicationInterface $app): void
    {
        parent::bootstrap($app);

        $collectionLocator = new CollectionLocator();
        FactoryLocator::add('Collection', $collectionLocator);
        FactoryLocator::add('Mongo', $collectionLocator);

        if (!Configure::check('Mongo')) {
            if (file_exists(CONFIG . 'mongo.php')) {
                Configure::load('mongo', 'default');
            } elseif (file_exists($this->getConfigPath() . 'mongo.php')) {
                Configure::load('Crustum/Mongo.mongo', 'default', false);
            }
        }
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function routes(RouteBuilder $routes): void
    {
        parent::routes($routes);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function console(CommandCollection $commands): CommandCollection
    {
        return parent::console($commands);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function services(ContainerInterface $container): void
    {
    }

    /**
     * Plugin install assets via crustum/plugin-manifest.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function manifest(): array
    {
        $pluginPath = dirname(__DIR__);

        return array_merge(
            static::manifestConfig(
                $pluginPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'mongo.php',
                CONFIG . 'mongo.php',
                false,
            ),
            static::manifestBootstrapAppend(
                "if (file_exists(CONFIG . 'mongo.php')) {\n    Configure::load('mongo', 'default');\n}",
                '// Mongo Plugin Configuration',
            ),
            static::manifestStarRepo('Crustum/Mongo'),
        );
    }
}
