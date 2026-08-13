<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge;

use Cake\Datasource\EntityInterface;
use Cake\Utility\Inflector;
use function Cake\Core\pluginSplit;

/**
 * Shared conventions for both bridge directions.
 *
 * Field extraction/attachment and cake naming conventions (property name,
 * singular model key) reused by Direction-1 (ORM Table source) and
 * Direction-2 (Collection source) bridge associations.
 */
trait ConventionsTrait
{
    /**
     * Extracts a single field value from an entity or array row.
     *
     * @param \Cake\Datasource\EntityInterface|array<string, mixed> $row The row.
     * @param string $field The field name.
     * @return mixed
     */
    protected function extractField(EntityInterface|array $row, string $field): mixed
    {
        if ($row instanceof EntityInterface) {
            return $row->get($field);
        }

        return $row[$field] ?? null;
    }

    /**
     * Attaches a loaded value to a source entity/array row under the property.
     *
     * @param \Cake\Datasource\EntityInterface|array<string, mixed> $row The row.
     * @param mixed $value The loaded value.
     * @param string|null $nestKey Override for the property name.
     * @return void
     */
    protected function attachToRow(EntityInterface|array &$row, mixed $value, ?string $nestKey = null): void
    {
        $property = $nestKey ?? $this->propertyName;
        if ($row instanceof EntityInterface) {
            $row->set($property, $value);

            return;
        }

        $row[$property] = $value;
    }

    /**
     * Default entity property name (underscored alias).
     *
     * @return string
     */
    protected function defaultProperty(): string
    {
        [, $name] = pluginSplit($this->name);

        return Inflector::underscore($name);
    }

    /**
     * Builds the singular underscored foreign key for an association name.
     *
     * @param string $name Model class or alias name.
     * @return string
     */
    protected function modelKey(string $name): string
    {
        [, $name] = pluginSplit($name);

        return Inflector::underscore(Inflector::singularize($name)) . '_id';
    }
}
