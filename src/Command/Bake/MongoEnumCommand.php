<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/bake EnumCommand (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Command\Bake;

use Bake\Command\SimpleBakeCommand;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Utility\Inflector;
use InvalidArgumentException;
use Override;

/**
 * Enum code generator for the Mongo ODM.
 *
 * Usage:
 * ```
 * bin/cake bake mongo_enum ArticleStatus draft,published,archived
 * bin/cake bake mongo_enum Priority low:0,medium:1,high:2 --int
 * ```
 *
 * @ported-from \Bake\Command\EnumCommand
 */
class MongoEnumCommand extends SimpleBakeCommand
{
    /**
     * Task name used in path generation.
     *
     * @var string
     */
    public string $pathFragment = 'Model/Enum/';

    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Create a model Enum for the Mongo ODM';
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'mongo_enum';
    }

    /**
     * @inheritDoc
     */
    public function fileName(string $name): string
    {
        return $name . '.php';
    }

    /**
     * @inheritDoc
     */
    public function template(): string
    {
        return 'Crustum/Mongo.Enum/enum';
    }

    /**
     * Get template data.
     *
     * @param \Cake\Console\Arguments $arguments The arguments for the command.
     * @return array<string, mixed>
     */
    #[Override]
    public function templateData(Arguments $arguments): array
    {
        $cases = $this->parseCases($arguments->getArgument('cases') ?? '', (bool)$arguments->getOption('int'));
        $isOfTypeInt = $this->isOfTypeInt($cases);
        $backingType = $isOfTypeInt ? 'int' : 'string';
        if ($arguments->getOption('int')) {
            if ($cases && !$isOfTypeInt) {
                throw new InvalidArgumentException('Cases do not match requested `int` backing type.');
            }

            $backingType = 'int';
        }

        $data = parent::templateData($arguments);
        $data['backingType'] = $backingType;
        $data['cases'] = $this->formatCases($cases);

        return $data;
    }

    /**
     * Parses the cases argument into a name → value map.
     *
     * @param string $definition The raw cases definition.
     * @param bool $isInt Whether the enum is int-backed.
     * @return array<string, int|string>
     */
    protected function parseCases(string $definition, bool $isInt): array
    {
        if ($definition === '') {
            return [];
        }

        $cases = [];
        foreach (explode(',', $definition) as $k => $case) {
            $case = trim($case);
            if ($case === '') {
                continue;
            }

            if (str_contains($case, ':')) {
                [$name, $value] = explode(':', $case, 2);
                $cases[trim($name)] = $isInt ? (int)trim($value) : trim($value);
            } else {
                $cases[$case] = $isInt ? $k : $case;
            }
        }

        return $cases;
    }

    /**
     * @param array<string, int|string> $definition
     * @return bool
     */
    protected function isOfTypeInt(array $definition): bool
    {
        if ($definition === []) {
            return false;
        }

        return array_all($definition, fn($value): bool => is_int($value));
    }

    /**
     * @param array<string, int|string> $cases
     * @return array<string>
     */
    protected function formatCases(array $cases): array
    {
        $formatted = [];
        foreach ($cases as $case => $value) {
            $case = Inflector::camelize(Inflector::underscore($case));
            if (is_string($value)) {
                $value = "'" . $value . "'";
            }

            $formatted[] = 'case ' . $case . ' = ' . $value . ';';
        }

        return $formatted;
    }

    /**
     * Generate a class stub.
     *
     * @param string $name The class name.
     * @param \Cake\Console\Arguments $args The console arguments.
     * @param \Cake\Console\ConsoleIo $io The console io.
     * @return void
     */
    #[Override]
    protected function bake(string $name, Arguments $args, ConsoleIo $io): void
    {
        parent::bake($name, $args, $io);

        $path = $this->getPath($args);
        $filename = $path . $name . '.php';

        if (file_exists($filename)) {
            require_once $filename;
        }
    }

    /**
     * Execute the command without generating a test skeleton.
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io.
     * @return int|null The exit code or null for success.
     */
    #[Override]
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $this->extractCommonProperties($args);
        $name = $args->getArgumentAt(0);
        if (empty($name)) {
            $io->error('You must provide a name to bake a ' . $this->name());
            $this->abort();
        }

        $name = $this->_getName($name);
        $name = Inflector::camelize($name);
        $this->bake($name, $args, $io);

        return static::CODE_SUCCESS;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = $this->_setCommonOptions($parser);

        $parser->setDescription(
            'Bake backed enums for use in the Mongo ODM.',
        )->addArgument('name', [
            'help' => 'Name of the enum to bake. You can use Plugin.name to bake plugin enums.',
            'required' => true,
        ])->addArgument('cases', [
            'help' => 'List of either `one,two` for string or `foo:0,bar:1` for int type.',
        ])->addOption('int', [
            'help' => 'Using backed enums with int instead of string as return type.',
            'boolean' => true,
            'short' => 'i',
        ])->addOption('no-test', [
            'boolean' => true,
            'help' => 'Do not generate a test skeleton.',
        ]);

        return $parser;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function defaultName(): string
    {
        return 'bake mongo_enum';
    }
}
