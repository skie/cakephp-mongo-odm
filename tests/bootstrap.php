<?php
declare(strict_types=1);

$findRoot = function (): string {
    $root = dirname(__DIR__);
    if (is_dir($root . '/vendor/cakephp/cakephp')) {
        return $root;
    }

    $root = dirname(__DIR__, 2);
    if (is_dir($root . '/vendor/cakephp/cakephp')) {
        return $root;
    }

    $root = dirname(__DIR__, 3);
    if (is_dir($root . '/vendor/cakephp/cakephp')) {
        return $root;
    }

    return dirname(__DIR__);
};

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

define('ROOT', $findRoot());
define('APP_DIR', 'TestApp');
define('WEBROOT_DIR', 'webroot');
define('APP', ROOT . '/tests/test_app/TestApp/');
define('CONFIG', ROOT . '/tests/test_app/TestApp/config/');
define('WWW_ROOT', ROOT . DS . WEBROOT_DIR . DS);
define('TESTS', ROOT . DS . 'tests' . DS);
define('TMP', ROOT . DS . 'tmp' . DS);
define('LOGS', TMP . 'logs' . DS);
define('CACHE', TMP . 'cache' . DS);
define('SESSIONS', TMP . 'sessions' . DS);
define('RESOURCES', ROOT . DS . 'resources' . DS);
define('CAKE_CORE_INCLUDE_PATH', ROOT . '/vendor/cakephp/cakephp');
define('CORE_PATH', CAKE_CORE_INCLUDE_PATH . DS);
define('CAKE', CORE_PATH . 'src' . DS);

require ROOT . '/vendor/cakephp/cakephp/src/functions.php';
require ROOT . '/vendor/autoload.php';

require TESTS . 'bootstrap_connection.php';

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\FactoryLocator;
use Cake\I18n\I18n;
use Cake\TestSuite\Fixture\SchemaLoader;
use Crustum\Mongo\MongoPlugin;
use Crustum\Mongo\ODM\Locator\CollectionLocator;

if (!function_exists('ensureDirectoryExists')) {
    /**
     * @param string $path Directory path
     * @return void
     */
    function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }
}

ensureDirectoryExists(TMP . 'cache/models');
ensureDirectoryExists(TMP . 'cache/persistent');
ensureDirectoryExists(TMP . 'cache/views');
ensureDirectoryExists(TMP . 'sessions');
ensureDirectoryExists(TMP . 'tests');
ensureDirectoryExists(LOGS);

date_default_timezone_set('UTC');

Configure::write('debug', true);
Configure::write('App', [
    'namespace' => 'App',
    'encoding' => 'UTF-8',
    'defaultLocale' => 'en_US',
    'defaultTimezone' => 'UTC',
    'base' => false,
    'dir' => 'src',
    'webroot' => 'webroot',
    'wwwRoot' => WWW_ROOT,
    'fullBaseUrl' => 'http://localhost',
    'paths' => [
        'plugins' => [ROOT . DS, TESTS . 'test_app' . DS . 'Plugin' . DS],
        'templates' => [APP . 'templates' . DS],
        'locales' => [RESOURCES . 'locales' . DS],
    ],
]);
Locale::setDefault('en_US');
I18n::setLocale('en_US');
Configure::write('Security', [
    'salt' => 'mongo-test-security-salt-change-me',
]);
Configure::write('Session', [
    'defaults' => 'php',
]);

$testToken = mongoTestEnv('TEST_TOKEN');
$cacheSuffix = $testToken !== null ? '_t' . $testToken : '';

Cache::setConfig('_cake_translations_', [
    'className' => 'File',
    'path' => CACHE . 'persistent/',
    'prefix' => 'mongo_test_translations' . $cacheSuffix . '_',
    'serialize' => true,
    'duration' => '+10 seconds',
]);
Cache::setConfig('_cake_model_', [
    'className' => 'File',
    'path' => CACHE . 'models/',
    'prefix' => 'mongo_test_model' . $cacheSuffix . '_',
    'serialize' => true,
    'duration' => '+10 seconds',
]);

if (!getenv('db_dsn')) {
    putenv('db_dsn=sqlite:///:memory:');
}

$testMongoDatabase = mongoTestDatabaseName('test_mongo_db');
$testMigratorDatabase = mongoTestDatabaseName('test_migrator_db', 'TEST_MONGO_MIGRATION_DB');

if (!defined('TEST_MONGO_DATABASE')) {
    define('TEST_MONGO_DATABASE', $testMongoDatabase);
}
if (!defined('TEST_MONGO_MIGRATION_DATABASE')) {
    define('TEST_MONGO_MIGRATION_DATABASE', $testMigratorDatabase);
}

putenv('TEST_MONGO_DB=' . $testMongoDatabase);
putenv('TEST_MONGO_MIGRATION_DB=' . $testMigratorDatabase);

ConnectionManager::setConfig('test', [
    'url' => getenv('db_dsn'),
    'timezone' => 'UTC',
]);
ConnectionManager::setConfig('mongo', mongoTestConnectionConfig('mongo'));
ConnectionManager::setConfig('test_mongo', mongoTestConnectionConfig($testMongoDatabase));
// Dedicated throwaway database for the Migrator test-suite helper, whose
// contract wipes every non-journal collection (doc 37 R5: never drop the
// shared bootstrap-owned collections). Named with the `test_` prefix so
// `addTestAliases()` maps the app name `migrator` to it during tests.
ConnectionManager::setConfig('test_migrator', mongoTestConnectionConfig($testMigratorDatabase));
ConnectionManager::alias('test_mongo', 'mongo');

Plugin::getCollection()->add(new MongoPlugin([
    'path' => dirname(__DIR__) . DS,
    'bootstrap' => true,
    'routes' => true,
]));

FactoryLocator::add('Collection', new CollectionLocator());
FactoryLocator::add('Mongo', new CollectionLocator());

$schemaLoader = new SchemaLoader();
$schemaLoader->loadInternalFile(TESTS . 'schema.php');

// Once per PHP process (ParaTest worker). Per-test rows: fixtures + TruncateStrategy.
mongoTestEnsureSchema(TESTS . 'schema_mongo.php', 'test_mongo');

$schemaLoader->loadInternalFile(TESTS . 'schema_orm.php');

if (file_exists(CONFIG . 'bootstrap.php')) {
    require CONFIG . 'bootstrap.php';
}
