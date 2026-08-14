<?php
declare(strict_types=1);

namespace Crustum\Mongo\Migration\Migration;

/**
 * Compares a desired schema (from documents or a dumped file) against the
 * live database and emits the deltas as migration operations.
 *
 * Operations are the same primitives the adapter executes, so `diff` output
 * can be baked directly into a migration.
 */
class SchemaDiff
{
    /**
     * Returns the operations needed to turn the live database into the
     * desired schema.
     *
     * The desired schema is a map of collection name → definition:
     * ```
     * [
     *   'articles' => [
     *     'fields' => [...],
     *     'indexes' => ['articles_author' => ['key' => ['author_id' => 1], 'options' => []]],
     *     'validator' => ['$jsonSchema' => [...]],
     *   ],
     * ]
     * ```
     *
     * @param array<string, array<string, mixed>> $desired The desired schema map
     * @param array<string, array<string, mixed>> $actual The actual schema map (from introspection)
     * @return list<array{type: string, collection: string, index?: string, name?: string, key?: array<string, mixed>, options?: array<string, mixed>, validator?: array<string, mixed>|null}>
     */
    public function diff(array $desired, array $actual): array
    {
        $operations = [];

        foreach ($desired as $name => $definition) {
            if (!isset($actual[$name])) {
                $operations = [
                    ...$operations,
                    $this->createCollectionOp($name, $definition),
                    ...$this->desiredIndexOps($name, $definition),
                ];

                continue;
            }

            $actualDef = $actual[$name];

            $desiredValidator = $definition['validator'] ?? null;
            $actualValidator = $actualDef['validator'] ?? null;
            if ($desiredValidator !== $actualValidator) {
                $operations[] = [
                    'type' => 'setValidator',
                    'collection' => $name,
                    'validator' => $desiredValidator,
                ];
            }

            $desiredIndexes = $definition['indexes'] ?? [];
            $actualIndexes = $actualDef['indexes'] ?? [];

            foreach ($desiredIndexes as $indexName => $indexDef) {
                if (!isset($actualIndexes[$indexName])) {
                    $operations[] = $this->createIndexOp($name, $indexName, $indexDef);
                }
            }

            foreach ($actualIndexes as $indexName => $indexDef) {
                if (!isset($desiredIndexes[$indexName])) {
                    $operations[] = [
                        'type' => 'dropIndex',
                        'collection' => $name,
                        'name' => $indexName,
                    ];
                }
            }
        }

        foreach (array_keys($actual) as $name) {
            if (!isset($desired[$name])) {
                $operations[] = [
                    'type' => 'dropCollection',
                    'collection' => $name,
                ];
            }
        }

        return $operations;
    }

    /**
     * Builds the "create collection" operation with validator and indexes.
     *
     * @param string $name Collection name
     * @param array<string, mixed> $definition Collection definition
     * @return array{type: string, collection: string, options: array<string, mixed>}
     */
    protected function createCollectionOp(string $name, array $definition): array
    {
        $options = $definition['options'] ?? [];
        if (isset($definition['validator'])) {
            $options['validator'] = $definition['validator'];
        }

        return [
            'type' => 'createCollection',
            'collection' => $name,
            'options' => $options,
        ];
    }

    /**
     * Returns the createIndex operations for the indexes declared on a
     * collection definition.
     *
     * @param string $name Collection name
     * @param array<string, mixed> $definition Collection definition
     * @return list<array<string, mixed>>
     */
    protected function desiredIndexOps(string $name, array $definition): array
    {
        $ops = [];
        foreach ($this->desiredIndexes($definition) as $indexName => $indexDef) {
            $ops[] = $this->createIndexOp($name, $indexName, $indexDef);
        }

        return $ops;
    }

    /**
     * Returns a single createIndex operation.
     *
     * @param string $name Collection name
     * @param string $indexName Index name
     * @param array<string, mixed> $indexDef Index definition
     * @return array<string, mixed>
     */
    protected function createIndexOp(string $name, string $indexName, array $indexDef): array
    {
        return [
            'type' => 'createIndex',
            'collection' => $name,
            'name' => $indexName,
            'key' => $indexDef['key'] ?? [],
            'options' => $indexDef['options'] ?? [],
        ];
    }

    /**
     * Returns the desired index map keyed by index name.
     *
     * @param array<string, mixed> $definition Collection definition
     * @return array<string, array<string, mixed>>
     */
    protected function desiredIndexes(array $definition): array
    {
        return $definition['indexes'] ?? [];
    }
}
