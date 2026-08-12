<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/bake (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\View\Helper;

use Bake\View\Helper\DocBlockHelper;
use Cake\Core\App;
use Cake\Utility\Inflector;

/**
 * Mongo-aware DocBlock helper.
 *
 * Mirrors `Bake\View\Helper\DocBlockHelper` but generates `@method` hints for
 * the Mongo ODM Document API (`newEmptyDocument`, `patchDocument`, …) instead
 * of the SQL Entity API, and maps Mongo field types to PHP DocBlock types.
 */
class MongoDocBlockHelper extends DocBlockHelper
{
    /**
     * Converts a Mongo canonical type to its DocBlock type counterpart.
     *
     * @param string $type The field type.
     * @return string The DocBlock type.
     */
    public function columnTypeToHintType(string $type): ?string
    {
        return match ($type) {
            'objectid', 'id' => '\MongoDB\BSON\ObjectId',
            'string', 'binary', 'uuid' => 'string',
            'integer', 'int', 'int64', 'float' => 'int|float',
            'decimal128' => 'string',
            'boolean', 'bool' => 'bool',
            'date', 'datetime', 'timestamp' => '\Cake\I18n\DateTime',
            'array', 'collection' => 'array',
            'hash', 'object' => 'array',
            'raw' => 'mixed',
            default => 'string',
        };
    }

    /**
     * Builds a map of Document associations as DocBlock types.
     *
     * @param array<string, array<string, mixed>> $propertySchema The property schema.
     * @return array<string, string> The property DocType map.
     */
    public function buildEntityAssociationHintTypeMap(array $propertySchema): array
    {
        $properties = [];
        foreach ($propertySchema as $property => $info) {
            if ($info['kind'] !== 'association') {
                continue;
            }

            $properties[$property] = '\\' . ltrim((string)$info['type'], '\\') . '|null';
        }

        return $properties;
    }

    /**
     * Builds table annotations for a Collection.
     *
     * @param array<string, array<string, array<string, mixed>>> $associations Associations data.
     * @param array<string, array<string, string>> $associationInfo Association info map.
     * @param array<string, array<mixed>> $behaviors Behaviors data.
     * @param string $document The Document class name.
     * @param string $namespace App namespace.
     * @return array<int, string>
     */
    public function buildTableAnnotations(
        array $associations,
        array $associationInfo,
        array $behaviors,
        string $document,
        string $namespace,
    ): array {
        $annotations = [];
        foreach ($associations as $type => $assocs) {
            foreach ($assocs as $assoc) {
                $typeStr = Inflector::camelize($type);
                if (isset($associationInfo[$assoc['alias']])) {
                    $tableFqn = $associationInfo[$assoc['alias']]['targetFqn'];
                    $annotations[] = "@property {$tableFqn}&\Cake\ORM\Association\\{$typeStr} \${$assoc['alias']}";
                }
            }
        }

        // phpcs:disable
        $annotations[] = "@method \\{$namespace}\\Model\\Document\\{$document} newEmptyDocument()";
        $annotations[] = "@method \\{$namespace}\\Model\\Document\\{$document} newDocument(array \$data, array \$options = [])";
        $annotations[] = "@method array<\\{$namespace}\\Model\\Document\\{$document}> newDocuments(array \$data, array \$options = [])";
        $annotations[] = "@method \\{$namespace}\\Model\\Document\\{$document} get(mixed \$primaryKey, array|string \$finder = 'all', \\Psr\\SimpleCache\\CacheInterface|string|null \$cache = null, \Closure|string|null \$cacheKey = null, mixed ...\$args)";
        $annotations[] = "@method \\{$namespace}\\Model\\Document\\{$document} findOrCreate(\$search, ?callable \$callback = null, array \$options = [])";
        $annotations[] = "@method \\{$namespace}\\Model\\Document\\{$document} patchDocument(\\Cake\\Datasource\\EntityInterface \$entity, array \$data, array \$options = [])";
        $annotations[] = "@method array<\\{$namespace}\\Model\\Document\\{$document}> patchDocuments(iterable \$entities, array \$data, array \$options = [])";
        $annotations[] = "@method \\{$namespace}\\Model\\Document\\{$document}|false save(\\Cake\\Datasource\\EntityInterface \$entity, array \$options = [])";
        $annotations[] = "@method \\{$namespace}\\Model\\Document\\{$document} saveOrFail(\\Cake\\Datasource\\EntityInterface \$entity, array \$options = [])";
        $annotations[] = "@method iterable<\\{$namespace}\\Model\\Document\\{$document}>|\\Cake\\Datasource\\ResultSetInterface<\\{$namespace}\\Model\\Document\\{$document}>|false saveMany(iterable \$entities, array \$options = [])";
        $annotations[] = "@method iterable<\\{$namespace}\\Model\\Document\\{$document}>|\\Cake\\Datasource\\ResultSetInterface<\\{$namespace}\\Model\\Document\\{$document}> saveManyOrFail(iterable \$entities, array \$options = [])";
        $annotations[] = "@method iterable<\\{$namespace}\\Model\\Document\\{$document}>|\\Cake\\Datasource\\ResultSetInterface<\\{$namespace}\\Model\\Document\\{$document}>|false deleteMany(iterable \$entities, array \$options = [])";
        $annotations[] = "@method iterable<\\{$namespace}\\Model\\Document\\{$document}>|\\Cake\\Datasource\\ResultSetInterface<\\{$namespace}\\Model\\Document\\{$document}> deleteManyOrFail(iterable \$entities, array \$options = [])";
        // phpcs:enable

        foreach (array_keys($behaviors) as $behavior) {
            $className = App::className($behavior, 'Model/Behavior', 'Behavior');
            if (!$className) {
                $className = "Crustum\Mongo\ODM\Behavior\\{$behavior}Behavior";
            }

            $annotations[] = '@mixin \\' . $className;
        }

        return $annotations;
    }
}
