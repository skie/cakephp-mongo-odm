<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Utility\Inflector;
use Crustum\Mongo\Migration\Util\ColumnParser;
use Override;

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
 *
 * @inspired-by \Migrations\Command\BakeMigrationCommand
 */
class BakeMigrationCommand extends BakeSimpleMigrationCommand
{
    /**
     * @inheritDoc
     */
    #[Override]
    public function name(): string
    {
        return 'mongo_migration';
    }

    /**
     * Migration class name for the current bake run.
     */
    protected string $migrationName = '';

    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Bake a Mongo migration class.';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function defaultName(): string
    {
        return 'bake mongo_migration';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    protected function bake(string $name, Arguments $args, ConsoleIo $io): void
    {
        $this->migrationName = $name;
        parent::bake($name, $args, $io);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function templateData(Arguments $arguments): array
    {
        $className = $this->migrationName;
        $data = parent::templateData($arguments);

        /** @var array<int, string> $args */
        $args = $arguments->getArguments();
        unset($args[0]);

        $columnParser = new ColumnParser();
        $fields = $columnParser->parseFields($args);
        $indexes = $columnParser->parseIndexes($args);
        $action = $this->detectAction($className);

        if (!$action && $fields !== []) {
            $this->io->abort(
                'When applying fields the migration name should start with one of the following prefixes: '
                . '`Create`, `Drop`, `Add`, `Remove`, `Alter`.',
            );
        }

        if ($action === []) {
            return $data;
        }

        [$actionName, $collection] = $action;

        return array_merge($data, [
            'action' => $actionName,
            'collections' => [$collection],
            'columns' => [
                'fields' => $fields,
                'indexes' => $indexes,
            ],
        ]);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = parent::buildOptionParser($parser);

        $parser->setDescription([
            'Bake a Mongo migration class',
            '',
            '<info>bin/cake bake mongo_migration CreateArticles</info>',
            '<info>bin/cake bake mongo_migration CreateArticles name:string age:int? email:string:unique</info>',
            '',
            'Column grammar: name:type[length]?[:unique] — e.g. name:string[100], age:int?, email:string:unique',
        ]);

        return $parser;
    }

    /**
     * Detects the action and collection from the migration class name.
     *
     * @param string $name Migration class name
     * @return array<int, string>
     */
    public function detectAction(string $name): array
    {
        if (preg_match('/^(Create|Drop)(.*)/', $name, $matches)) {
            $action = strtolower($matches[1]) . '_table';
            $collection = Inflector::underscore($matches[2]);

            return [$action, $collection];
        }

        if (preg_match('/^(Add).+?(?:To)(.*)/', $name, $matches)) {
            return ['add_field', Inflector::underscore($matches[2])];
        }

        if (preg_match('/^(Remove).+?(?:From)(.*)/', $name, $matches)) {
            return ['drop_field', Inflector::underscore($matches[2])];
        }

        if (preg_match('/^(Alter).+?(?:On)(.*)/', $name, $matches)) {
            return ['alter_field', Inflector::underscore($matches[2])];
        }

        if (preg_match('/^(Alter)(.*)/', $name, $matches)) {
            return ['alter_table', Inflector::underscore($matches[2])];
        }

        return [];
    }

    /**
     * Infers the collection name from the migration class name.
     *
     * @param string $className Migration class name
     * @return string The collection name
     */
    public function collectionName(string $className): string
    {
        $action = $this->detectAction($className);
        if ($action !== []) {
            return $action[1];
        }

        return Inflector::underscore($className);
    }
}
