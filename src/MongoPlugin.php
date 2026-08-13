<?php
declare(strict_types=1);

namespace Crustum\Mongo;

use Cake\Collection\Collection;
use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\ContainerInterface;
use Cake\Core\PluginApplicationInterface;
use Cake\Datasource\FactoryLocator;
use Cake\Event\EventManager;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\RouteBuilder;
use Crustum\Mongo\Migration\Command\BakeMigrationCommand;
use Crustum\Mongo\Migration\Command\BakeMigrationDiffCommand;
use Crustum\Mongo\Migration\Command\BakeMigrationSnapshotCommand;
use Crustum\Mongo\Migration\Command\BakeSeedCommand;
use Crustum\Mongo\Migration\Command\DiffCommand;
use Crustum\Mongo\Migration\Command\DumpCommand;
use Crustum\Mongo\Migration\Command\MarkMigratedCommand;
use Crustum\Mongo\Migration\Command\MigrateCommand;
use Crustum\Mongo\Migration\Command\ResetCommand;
use Crustum\Mongo\Migration\Command\RollbackCommand;
use Crustum\Mongo\Migration\Command\SeedCommand;
use Crustum\Mongo\Migration\Command\StatusCommand;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\ODM\Locator\CollectionLocator;
use Crustum\Mongo\View\Form\DocumentContext;
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

        EventManager::instance()->on('View.beforeRender', function ($event): void {
            $view = $event->getSubject();
            $view->Form->addContextProvider('mongo', function ($request, array $data) {
                $first = null;
                if (is_iterable($data['entity'] ?? null)) {
                    $first = (new Collection($data['entity']))->first();
                }

                if (($data['entity'] ?? null) instanceof Document || $first instanceof Document) {
                    return new DocumentContext($request, $data);
                }
            });
        });
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
        $commands->add('mongo migrations migrate', MigrateCommand::class);
        $commands->add('mongo migrations rollback', RollbackCommand::class);
        $commands->add('mongo migrations status', StatusCommand::class);
        $commands->add('mongo migrations mark_migrated', MarkMigratedCommand::class);
        $commands->add('mongo migrations reset', ResetCommand::class);
        $commands->add('mongo migrations seed', SeedCommand::class);
        $commands->add('mongo migrations diff', DiffCommand::class);
        $commands->add('mongo schema dump', DumpCommand::class);
        $commands->add('bake mongo_migration', BakeMigrationCommand::class);
        $commands->add('bake mongo_migration_diff', BakeMigrationDiffCommand::class);
        $commands->add('bake mongo_migration_snapshot', BakeMigrationSnapshotCommand::class);
        $commands->add('bake mongo_seed', BakeSeedCommand::class);

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
