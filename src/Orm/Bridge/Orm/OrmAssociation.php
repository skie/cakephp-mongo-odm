<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge\Orm;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Utility\Inflector;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\Orm\Bridge\ConventionsTrait;
use Crustum\Mongo\Orm\Bridge\OrmTableAwareInterface;
use InvalidArgumentException;
use function Cake\Core\pluginSplit;

/**
 * Direction-2 cross-boundary association (ODM Collection source → ORM Table target).
 *
 * A Mongo document relates to SQL rows; the association loads them with a
 * batched SQL `whereIn` on the target Table. This is the mirror of the
 * Direction-1 bridge classes with source/target swapped.
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §8
 */
abstract class OrmAssociation
{
    use ConventionsTrait;

    /**
     * Association alias.
     *
     * @var string
     */
    protected string $name;

    /**
     * The ODM source collection (Direction-2 source).
     *
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected BaseCollection $source;

    /**
     * Target Table options (alias or class) resolved via the locator.
     *
     * @var string
     */
    protected string $className;

    /**
     * Foreign key on the owning side.
     *
     * For OrmBelongsTo: the SQL column holding the Mongo `_id`.
     * For OrmHasOne/HasMany: the SQL field holding the Mongo document key.
     *
     * @var array<string>|string
     */
    protected array|string $foreignKey;

    /**
     * Binding key on the matched side.
     *
     * Defaults to the source Mongo `_id` (OrmBelongsTo) or target SQL primary
     * key (OrmHasOne/HasMany).
     *
     * @var array<string>|string
     */
    protected array|string $bindingKey;

    /**
     * Entity property the loaded association is attached to.
     *
     * @var string
     */
    protected string $propertyName;

    /**
     * Target conditions applied to the batched load.
     *
     * @var array<string, mixed>
     */
    protected array $conditions = [];

    /**
     * Constructor.
     *
     * @param string $name Association alias.
     * @param \Crustum\Mongo\ODM\BaseCollection $source The ODM source collection.
     * @param array<string, mixed> $options Association options.
     */
    public function __construct(string $name, BaseCollection $source, array $options = [])
    {
        $this->name = $name;
        $this->source = $source;
        $this->className = (string)($options['className'] ?? $name);
        $this->propertyName = (string)($options['property'] ?? $this->defaultProperty());
        $this->foreignKey = $options['foreignKey'] ?? $this->defaultForeignKey();
        $this->bindingKey = $options['bindingKey'] ?? $this->defaultBindingKey();
        $this->conditions = (array)($options['conditions'] ?? []);
    }

    /**
     * Gets the association alias.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Gets the ODM source collection.
     *
     * @return \Crustum\Mongo\ODM\BaseCollection
     */
    public function getSource(): BaseCollection
    {
        return $this->source;
    }

    /**
     * Gets the target ORM table, resolving it lazily.
     *
     * @return \Cake\ORM\Table
     * @throws \InvalidArgumentException When the alias does not resolve to a table.
     */
    public function getTarget(): Table
    {
        if ($this->source instanceof OrmTableAwareInterface) {
            return $this->source->getOrmTable($this->className);
        }

        throw new InvalidArgumentException(sprintf(
            'Source collection `%s` must implement `%s` to resolve ORM target `%s`.',
            $this->source::class,
            OrmTableAwareInterface::class,
            $this->className,
        ));
    }

    /**
     * Gets the foreign key.
     *
     * @return array<string>|string
     */
    public function getForeignKey(): array|string
    {
        return $this->foreignKey;
    }

    /**
     * Gets the binding key.
     *
     * @return array<string>|string
     */
    public function getBindingKey(): array|string
    {
        return $this->bindingKey;
    }

    /**
     * Gets the entity property name.
     *
     * @return string
     */
    public function getProperty(): string
    {
        return $this->propertyName;
    }

    /**
     * Gets the target conditions.
     *
     * @return array<string, mixed>
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    /**
     * Builds a lazy query against the target ORM table.
     *
     * @param string $type Finder name.
     * @param mixed ...$args Finder arguments.
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array<string, mixed>>
     */
    public function find(string $type = 'all', mixed ...$args): SelectQuery
    {
        $query = $this->getTarget()->find($type, ...$args);
        if ($this->getConditions() !== []) {
            $query->where($this->getConditions());
        }

        return $query;
    }

    /**
     * Loads the association for a set of source documents and attaches results.
     *
     * Runs one batched SQL query, matches by key, and sets the association
     * property on each source document.
     *
     * @param iterable<\Cake\Datasource\EntityInterface> $documents Source documents.
     * @return void
     */
    public function load(iterable $documents): void
    {
        $rows = [];
        $keys = [];
        foreach ($documents as $document) {
            $rows[] = $document;
            $value = $this->extractField($document, $this->sourceKeyField());
            if ($value !== null && $value !== '') {
                $keys[(string)$value] = $value;
            }
        }

        $map = $keys === [] ? [] : $this->loadByKeys(array_values($keys));

        foreach ($rows as $row) {
            $this->injectRow($row, $map);
        }
    }

    /**
     * Loads the target rows matching the given source keys and builds a map.
     *
     * @param list<int|string> $keys Source key values.
     * @return array<string, mixed> Map of target-key string to row(s).
     */
    abstract public function loadByKeys(array $keys): array;

    /**
     * Injects the loaded association value into a single source document.
     *
     * @param \Cake\Datasource\EntityInterface|array<string, mixed> $row The source row.
     * @param array<string, mixed> $map Keyed map built by {@see loadByKeys()}.
     * @param string|null $nestKey Override for the property name.
     * @return \Cake\Datasource\EntityInterface|array<string, mixed>
     */
    public function injectRow(EntityInterface|array $row, array $map, ?string $nestKey = null): EntityInterface|array
    {
        $key = $this->extractField($row, $this->sourceKeyField());
        $value = $key !== null ? ($map[(string)$key] ?? $this->emptyValue()) : $this->emptyValue();
        $this->attachToRow($row, $value, $nestKey);

        return $row;
    }

    /**
     * Source-side field whose value is matched against the target.
     *
     * @return string
     */
    abstract protected function sourceKeyField(): string;

    /**
     * Default value attached when nothing matches (null for singular, [] for many).
     *
     * @return mixed
     */
    abstract protected function emptyValue(): mixed;

    /**
     * Default foreign key derived from the association name.
     *
     * @return string
     */
    abstract protected function defaultForeignKey(): string;

    /**
     * Default binding key for the target.
     *
     * @return string
     */
    abstract protected function defaultBindingKey(): string;

    /**
     * Default entity property name (singular for single-value types).
     *
     * @return string
     */
    protected function defaultProperty(): string
    {
        [, $name] = pluginSplit($this->name);

        return Inflector::underscore($name);
    }

    /**
     * Single foreign key (string) used in the batched `whereIn`.
     *
     * @return string
     */
    protected function foreignKey(): string
    {
        $key = $this->getForeignKey();

        return is_array($key) ? ($key[0] ?? '') : $key;
    }

    /**
     * Single binding key (string) read from the source row.
     *
     * @return string
     */
    protected function bindingKey(): string
    {
        $key = $this->getBindingKey();

        return is_array($key) ? ($key[0] ?? '_id') : $key;
    }
}
