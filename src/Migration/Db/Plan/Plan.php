<?php
declare(strict_types=1);

namespace Crustum\Mongo\Migration\Db\Plan;

use Crustum\Mongo\Migration\Db\Action\AddField;
use Crustum\Mongo\Migration\Db\Action\AddIndex;
use Crustum\Mongo\Migration\Db\Action\CreateCollection;
use Crustum\Mongo\Migration\Db\Action\DropCollection;
use Crustum\Mongo\Migration\Db\Action\DropIndex;
use Crustum\Mongo\Migration\Db\Action\RemoveField;
use Crustum\Mongo\Migration\Db\Action\RenameCollection;
use Crustum\Mongo\Migration\Db\Action\SetValidator;
use Crustum\Mongo\Migration\Db\Adapter\AdapterInterface;

/**
 * Turns an Intent into an ordered, conflict-free sequence of adapter calls.
 *
 * Ported and reduced from cakephp/migrations `Db\Plan\Plan`: instead of SQL
 * dialect statements, actions are executed through the Mongo adapter. Mongo has
 * no "add column" DDL — fields are `$jsonSchema` validator properties — so
 * `AddField`/`RemoveField` actions are folded into a `setValidator` call built
 * from the declared fields (merged with the live validator for updates).
 */
class Plan
{
    /**
     * CreateCollection actions keyed by collection.
     *
     * @var array<string, \Crustum\Mongo\Migration\Db\Action\CreateCollection>
     */
    protected array $creates = [];

    /**
     * Explicit SetValidator actions keyed by collection.
     *
     * @var array<string, \Crustum\Mongo\Migration\Db\Action\SetValidator>
     */
    protected array $validators = [];

    /**
     * AddField actions keyed by collection.
     *
     * @var array<string, array<string, \Crustum\Mongo\Migration\Db\Action\AddField>>
     */
    protected array $fields = [];

    /**
     * RemoveField actions keyed by collection.
     *
     * @var array<string, array<string, \Crustum\Mongo\Migration\Db\Action\RemoveField>>
     */
    protected array $fieldRemoves = [];

    /**
     * AddIndex actions keyed by "collection\0indexName".
     *
     * @var array<string, \Crustum\Mongo\Migration\Db\Action\AddIndex>
     */
    protected array $indexCreates = [];

    /**
     * DropIndex actions keyed by "collection\0indexName".
     *
     * @var array<string, \Crustum\Mongo\Migration\Db\Action\DropIndex>
     */
    protected array $indexDrops = [];

    /**
     * RenameCollection actions.
     *
     * @var list<\Crustum\Mongo\Migration\Db\Action\RenameCollection>
     */
    protected array $renames = [];

    /**
     * DropCollection actions.
     *
     * @var list<\Crustum\Mongo\Migration\Db\Action\DropCollection>
     */
    protected array $drops = [];

    /**
     * Constructor.
     *
     * @param \Crustum\Mongo\Migration\Db\Plan\Intent $intent All the actions to execute
     */
    public function __construct(Intent $intent)
    {
        $this->gather($intent->getActions());
        $this->resolveConflicts();
    }

    /**
     * Executes this plan using the given adapter.
     *
     * @param \Crustum\Mongo\Migration\Db\Adapter\AdapterInterface $executor The adapter
     * @return void
     */
    public function execute(AdapterInterface $executor): void
    {
        foreach ($this->creates as $collection => $action) {
            $options = $action->getOptions();
            $validator = $this->validatorFor($collection, $executor, $options['validator'] ?? null);
            if ($validator !== null) {
                $options['validator'] = $validator;
            }

            $executor->createCollection($collection, $options);
        }

        $validatorCollections = array_values(array_unique(array_merge(
            array_keys($this->validators),
            array_keys($this->fields),
            array_keys($this->fieldRemoves),
        )));
        foreach ($validatorCollections as $collection) {
            if (isset($this->creates[$collection])) {
                continue;
            }

            $validator = $this->validatorFor($collection, $executor, null);
            if ($validator !== null) {
                $executor->setValidator($collection, $validator);
            }
        }

        foreach ($this->indexCreates as $key => $action) {
            $options = $action->getOptions();
            $options['name'] = $action->getIndexName();
            $executor->createIndex($action->getCollectionName(), $action->getKey(), $options);
        }

        foreach ($this->indexDrops as $action) {
            $executor->dropIndex($action->getCollectionName(), $action->getIndexName());
        }

        foreach ($this->renames as $action) {
            $executor->renameCollection($action->getFrom(), $action->getTo(), $action->getDropTarget());
        }

        foreach ($this->drops as $action) {
            $executor->dropCollection($action->getCollectionName());
        }
    }

    /**
     * Executes the inverse plan (rollback) using the given adapter.
     *
     * @param \Crustum\Mongo\Migration\Db\Adapter\AdapterInterface $executor The adapter
     * @return void
     */
    public function executeInverse(AdapterInterface $executor): void
    {
        foreach (array_reverse($this->drops) as $action) {
            $executor->createCollection($action->getCollectionName());
        }

        foreach (array_reverse($this->renames) as $action) {
            $executor->renameCollection($action->getTo(), $action->getFrom(), $action->getDropTarget());
        }

        foreach (array_reverse($this->indexDrops) as $action) {
            $executor->createIndex($action->getCollectionName(), [$action->getIndexName() => 1]);
        }

        foreach (array_reverse($this->indexCreates) as $action) {
            $executor->dropIndex($action->getCollectionName(), $action->getIndexName());
        }

        foreach (array_reverse($this->validators) as $action) {
            $executor->setValidator($action->getCollectionName(), null);
        }

        foreach (array_reverse($this->creates) as $action) {
            $executor->dropCollection($action->getCollectionName());
        }
    }

    /**
     * Builds the validator to apply for a collection.
     *
     * An explicit SetValidator wins; otherwise fields are merged into the live
     * validator (for creates the live validator is empty).
     *
     * @param string $collection Collection name
     * @param \Crustum\Mongo\Migration\Db\Adapter\AdapterInterface $executor Adapter
     * @param array<string, mixed>|null $optionValidator Validator passed as a create option
     * @return array<string, mixed>|null
     */
    protected function validatorFor(string $collection, AdapterInterface $executor, ?array $optionValidator): ?array
    {
        if ($optionValidator !== null) {
            return $optionValidator;
        }

        if (isset($this->validators[$collection])) {
            return $this->validators[$collection]->getValidator();
        }

        if (!isset($this->fields[$collection]) && !isset($this->fieldRemoves[$collection])) {
            return null;
        }

        $properties = [];
        if ($executor->hasCollection($collection)) {
            $existing = $executor->getSchemaManager()->getValidator($collection);
            if (is_array($existing) && isset($existing['$jsonSchema']['properties'])) {
                $properties = $existing['$jsonSchema']['properties'];
            }
        }

        foreach (array_keys($this->fieldRemoves[$collection] ?? []) as $name) {
            unset($properties[$name]);
        }

        foreach ($this->fields[$collection] ?? [] as $field) {
            $properties[$field->getFieldName()] = ['bsonType' => $this->bsonType($field->getType())];
        }

        return [
            '$jsonSchema' => [
                'bsonType' => 'object',
                'properties' => $properties,
                'additionalProperties' => true,
            ],
        ];
    }

    /**
     * Buckets the intent actions into the plan's steps.
     *
     * @param list<\Crustum\Mongo\Migration\Db\Action\Action> $actions The actions
     * @return void
     */
    protected function gather(array $actions): void
    {
        foreach ($actions as $action) {
            if ($action instanceof CreateCollection) {
                $this->creates[$action->getCollectionName()] = $action;
            } elseif ($action instanceof SetValidator) {
                $this->validators[$action->getCollectionName()] = $action;
            } elseif ($action instanceof AddField) {
                $this->fields[$action->getCollectionName()][$action->getFieldName()] = $action;
            } elseif ($action instanceof RemoveField) {
                $this->fieldRemoves[$action->getCollectionName()][$action->getFieldName()] = $action;
            } elseif ($action instanceof AddIndex) {
                $this->indexCreates[$action->getCollectionName() . "\0" . $action->getIndexName()] = $action;
            } elseif ($action instanceof DropIndex) {
                $this->indexDrops[$action->getCollectionName() . "\0" . $action->getIndexName()] = $action;
            } elseif ($action instanceof RenameCollection) {
                $this->renames[] = $action;
            } elseif ($action instanceof DropCollection) {
                $this->drops[] = $action;
            }
        }
    }

    /**
     * Removes conflicting or redundant actions.
     *
     * @return void
     */
    protected function resolveConflicts(): void
    {
        // A dropped collection cancels every other action for it.
        foreach ($this->drops as $drop) {
            $name = $drop->getCollectionName();
            unset($this->creates[$name], $this->validators[$name], $this->fields[$name], $this->fieldRemoves[$name]);
            $this->indexCreates = array_filter(
                $this->indexCreates,
                fn(AddIndex $a): bool => $a->getCollectionName() !== $name,
            );
            $this->indexDrops = array_filter(
                $this->indexDrops,
                fn(DropIndex $a): bool => $a->getCollectionName() !== $name,
            );
        }

        // addIndex + dropIndex for the same index name are a net no-op,
        // regardless of the order they were declared in.
        foreach (array_keys($this->indexCreates) as $key) {
            if (isset($this->indexDrops[$key])) {
                unset($this->indexCreates[$key], $this->indexDrops[$key]);
            }
        }
    }

    /**
     * Maps a canonical Mongo type to a BSON type spelling for `$jsonSchema`.
     *
     * @param string $type Canonical type name
     * @return string BSON type spelling
     */
    protected function bsonType(string $type): string
    {
        return match ($type) {
            'objectid' => 'objectId',
            'integer', 'int' => 'int',
            'int64' => 'long',
            'float' => 'double',
            'decimal128' => 'decimal',
            'boolean', 'bool' => 'bool',
            'date', 'datetime' => 'date',
            'timestamp' => 'timestamp',
            'binary' => 'binData',
            'hash', 'object' => 'object',
            'array', 'collection' => 'array',
            default => 'string',
        };
    }
}
