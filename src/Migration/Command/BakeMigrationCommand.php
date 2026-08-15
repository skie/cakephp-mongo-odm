<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Utility\Inflector;
use Crustum\Mongo\Migration\Config\ConfigInterface;
use Crustum\Mongo\Migration\Migration\ManagerFactory;
use Crustum\Mongo\Migration\Util\ColumnParser;
use Crustum\Mongo\Migration\Util\PhpArrayPrinter;
use Crustum\Mongo\Migration\Util\Util;
use RuntimeException;

/**
 * Bakes a Mongo migration class into the migrations folder.
 *
 * The migration name drives the generated action:
 * - `CreateArticles name:string age:int` → create collection + validator
 * - `AddTagsIndex` → empty migration
 *
 * Usage:
 * ```
 * bin/cake bake mongo_migration CreateArticles
 * bin/cake bake mongo_migration CreateArticles name:string age:int? email:string:unique
 * ```
 */
class BakeMigrationCommand extends Command
{
    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Bake a Mongo migration class.';
    }

    /**
     * The default name added to the application command list.
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'bake mongo_migration';
    }

    /**
     * Configure the option parser.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser The option parser to configure
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription([
            'Bake a Mongo migration class',
            '',
            '<info>bin/cake bake mongo_migration CreateArticles</info>',
            '<info>bin/cake bake mongo_migration CreateArticles name:string age:int? email:string:unique</info>',
            '',
            'Column grammar: name:type[length]?[:unique] — e.g. name:string[100], age:int?, email:string:unique',
        ])->addOption('plugin', [
            'short' => 'p',
            'help' => 'The plugin to run migrations for',
        ])->addOption('connection', [
            'short' => 'c',
            'help' => 'The datasource connection to use',
            'default' => 'mongo',
        ])->addOption('source', [
            'short' => 's',
            'default' => ConfigInterface::DEFAULT_MIGRATION_FOLDER,
            'help' => 'The folder where your migrations are',
        ])->addOption('force', [
            'short' => 'f',
            'boolean' => true,
            'help' => 'Force overwriting an existing migration with the same name',
        ]);

        return $parser;
    }

    /**
     * Execute the command.
     *
     * @param \Cake\Console\Arguments $args The command arguments
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int|null The exit code or null for success
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $all = $args->getArguments();
        $name = isset($all[0]) && is_string($all[0]) ? $all[0] : null;
        if ($name === null || $name === '') {
            $io->err('You must provide a migration name in CamelCase.');
            $this->abort();
        }

        $className = Inflector::camelize($name);
        if (!preg_match('/^[A-Z]{1}[a-zA-Z0-9]+$/', $className)) {
            $io->err('The class name can only contain "A-Z" and "0-9" and has to start with a letter.');
            $this->abort();
        }

        $path = $this->migrationPath($args);
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Could not create migrations folder `%s`.', $path));
        }

        $version = Util::getCurrentTimestamp();
        $file = $path . DIRECTORY_SEPARATOR . $version . '_' . Inflector::underscore($className) . '.php';

        $existing = glob($path . DIRECTORY_SEPARATOR . '*_' . Inflector::underscore($className) . '.php');
        $existing = is_array($existing) ? $existing : [];
        if ($existing && !$args->getOption('force')) {
            $io->abort(sprintf(
                'A migration with the name `%s` already exists. Use --force to overwrite.',
                $className,
            ));
        }

        foreach ($existing as $oldFile) {
            if (file_exists($oldFile)) {
                unlink($oldFile);
            }
        }

        $parser = new ColumnParser();
        $columnArgs = array_values(array_filter(
            $all,
            fn($arg): bool => is_string($arg) && $arg !== $name,
        ));
        $fields = $parser->parseFields($columnArgs);
        $indexes = $parser->parseIndexes($columnArgs);

        $content = $this->buildFile($className, $fields, $indexes);

        if (file_put_contents($file, $content) === false) {
            throw new RuntimeException(sprintf('Could not write migration file `%s`.', $file));
        }

        $io->success(sprintf('Baked `%s` to `%s`.', $className, $file));

        return self::CODE_SUCCESS;
    }

    /**
     * Builds the migration file content.
     *
     * @param string $className Migration class name
     * @param array<string, array<string, mixed>> $fields Parsed fields
     * @param array<string, array{key: array<string, int>, unique: bool}> $indexes Parsed indexes
     * @return string PHP file content
     */
    protected function buildFile(string $className, array $fields, array $indexes): string
    {
        $collectionName = $this->collectionName($className);

        $body = [];

        if ($fields !== [] || $indexes !== []) {
            $printer = new PhpArrayPrinter();
            $lines = [];
            $lines[] = sprintf("        \$this->collection('%s')", $collectionName);

            foreach ($fields as $fieldName => $definition) {
                $type = $definition['type'];
                $options = [];
                if ($definition['null'] ?? false) {
                    $options['null'] = true;
                }

                if (isset($definition['default'])) {
                    $options['default'] = $definition['default'];
                }

                $optionsStr = $options !== [] ? ', ' . $printer->print($options, 3) : '';
                $lines[] = sprintf("            ->addColumn('%s', '%s'%s)", $fieldName, $type, $optionsStr);
            }

            foreach ($indexes as $index) {
                $options = ['unique' => $index['unique']];
                $lines[] = sprintf(
                    '            ->addIndex(%s, %s)',
                    $printer->print($index['key'], 3),
                    $printer->print($options, 3),
                );
            }

            $terminator = str_starts_with($className, 'Create') ? 'create' : 'update';
            $lines[] = '            ->' . $terminator . '();';

            $body[] = implode("\n", $lines);
        } else {
            $body[] = '        // Write your migration logic here.';
        }

        $upBody = implode("\n", $body);

        return <<<PHP
<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseMigration;

class {$className} extends BaseMigration
{
    public function up(): void
    {
{$upBody}
    }

    public function down(): void
    {
    }
}

PHP;
    }

    /**
     * Infers the collection name from the migration class name.
     *
     * Handles the cake naming conventions:
     * - `CreateArticles` → `articles`
     * - `AddPriceToProducts` → `products`
     * - `RemoveFieldsFromUsers` → `users`
     *
     * @param string $className Migration class name
     * @return string The collection name
     */
    protected function collectionName(string $className): string
    {
        if (preg_match('/^Create(.+)$/', $className, $matches)) {
            return Inflector::underscore($matches[1]);
        }

        if (preg_match('/^(?:Add|Remove|Alter).+?To(.*)$/', $className, $matches)) {
            return Inflector::underscore($matches[1]);
        }

        if (preg_match('/^(?:Add|Remove|Alter)(?:Fields|Field|Columns|Column)?(?:From)?(.*)$/', $className, $matches)) {
            return Inflector::underscore($matches[1]);
        }

        return Inflector::underscore($className);
    }

    /**
     * Resolves the migrations folder path.
     *
     * @param \Cake\Console\Arguments $args The command arguments
     * @return string The migrations folder
     */
    protected function migrationPath(Arguments $args): string
    {
        $factory = new ManagerFactory([
            'plugin' => $args->getOption('plugin'),
            'source' => $args->getOption('source'),
            'connection' => (string)$args->getOption('connection'),
        ]);
        $config = $factory->createConfig();

        return $config->getMigrationPath();
    }
}
