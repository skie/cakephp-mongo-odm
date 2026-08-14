<?php
declare(strict_types=1);

namespace Crustum\Mongo\Migration\Migration;

use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Schema\CollectionSchema;

/**
 * Dumps live Mongo schema into a plain array representation suitable for
 * `config/schema_mongo.php` and for `SchemaDiff` comparison.
 *
 * Output shape (per collection):
 * ```
 * [
 *   'articles' => [
 *     'validator' => ['$jsonSchema' => [...]],
 *     'indexes' => [
 *       'articles_author' => [
 *         'key' => ['author_id' => 1],
 *         'options' => ['unique' => true, 'sparse' => true],
 *       ],
 *     ],
 *   ],
 * ]
 * ```
 */
class SchemaDumper
{
    /**
     * The connection to introspect.
     *
     * @var \Crustum\Mongo\Database\Connection
     */
    protected Connection $connection;

    /**
     * Constructor.
     *
     * @param \Crustum\Mongo\Database\Connection $connection The connection
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Returns the dumped schema for all collections (system and journal
     * collections excluded).
     *
     * @return array<string, array<string, mixed>>
     */
    public function dumpAll(): array
    {
        $schema = [];
        $names = $this->connection->getSchemaCollection()->listCollections();
        sort($names);

        foreach ($names as $name) {
            if (str_starts_with($name, 'system.')) {
                continue;
            }

            if (in_array($name, ['_migrations', '_seeds'], true)) {
                continue;
            }

            $schema[$name] = $this->dumpCollection($name);
        }

        return $schema;
    }

    /**
     * Returns the dumped schema for a single collection.
     *
     * @param string $name Collection name
     * @return array<string, mixed>
     */
    public function dumpCollection(string $name): array
    {
        $described = $this->connection->getSchemaCollection()->describe($name);
        if (!$described instanceof CollectionSchema) {
            return [];
        }

        $definition = [];

        $validator = $described->validator()->toArray();
        if ($validator !== []) {
            $definition['validator'] = $validator;
        }

        $indexes = [];
        foreach ($described->indexes() as $indexName => $index) {
            if (array_keys($index->getKey()) === ['_id']) {
                continue;
            }

            $indexes[$indexName] = [
                'key' => $index->getKey(),
            ];

            $options = $index->createIndexOptions();
            unset($options['name']);
            if ($options !== []) {
                $indexes[$indexName]['options'] = $options;
            }
        }

        if ($indexes !== []) {
            $definition['indexes'] = $indexes;
        }

        return $definition;
    }
}
