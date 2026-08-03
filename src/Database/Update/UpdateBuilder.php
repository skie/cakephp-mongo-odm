<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Update;

/**
 * Update builder for MongoDB update operations
 *
 * Provides fluent interface for building MongoDB update documents
 */
class UpdateBuilder
{
    /**
     * The update operations
     *
     * @var array<string, mixed>
     */
    protected array $_updates = [];

    /**
     * Update options
     *
     * @var array<string, mixed>
     */
    protected array $_options = [];

    /**
     * Set field values
     *
     * @param array<string, mixed> $fields The fields to set
     * @return $this
     */
    public function set(array $fields)
    {
        if (!isset($this->_updates['$set'])) {
            $this->_updates['$set'] = [];
        }

        $this->_updates['$set'] = array_merge(
            $this->_updates['$set'],
            $fields,
        );

        return $this;
    }

    /**
     * Increment field values
     *
     * @param array<string, int|float> $fields The fields and increment values
     * @return $this
     */
    public function inc(array $fields)
    {
        if (!isset($this->_updates['$inc'])) {
            $this->_updates['$inc'] = [];
        }

        $this->_updates['$inc'] = array_merge(
            $this->_updates['$inc'],
            $fields,
        );

        return $this;
    }

    /**
     * Push elements to arrays
     *
     * @param string                $field   The field name
     * @param mixed                 $value   The value to push
     * @param array<string, mixed>  $options Additional options ($each, $slice, $position, $sort)
     * @return $this
     */
    public function push(string $field, mixed $value, array $options = [])
    {
        if (!isset($this->_updates['$push'])) {
            $this->_updates['$push'] = [];
        }

        if ($options !== []) {
            $value = ['$each' => (array)$value] + $options;
        }

        $this->_updates['$push'][$field] = $value;

        return $this;
    }

    /**
     * Pull elements from arrays
     *
     * @param string $field The field name
     * @param mixed  $value The value or condition to pull
     * @return $this
     */
    public function pull(string $field, mixed $value)
    {
        if (!isset($this->_updates['$pull'])) {
            $this->_updates['$pull'] = [];
        }

        $this->_updates['$pull'][$field] = $value;

        return $this;
    }

    /**
     * Add to sets
     *
     * @param string $field The field name
     * @param mixed  $value The value to add
     * @return $this
     */
    public function addToSet(string $field, mixed $value)
    {
        if (!isset($this->_updates['$addToSet'])) {
            $this->_updates['$addToSet'] = [];
        }

        $this->_updates['$addToSet'][$field] = $value;

        return $this;
    }

    /**
     * Unset fields
     *
     * @param array<string> $fields The fields to remove
     * @return $this
     */
    public function unset(array $fields)
    {
        if (!isset($this->_updates['$unset'])) {
            $this->_updates['$unset'] = [];
        }

        foreach ($fields as $field) {
            $this->_updates['$unset'][$field] = '';
        }

        return $this;
    }

    /**
     * Multiply field values
     *
     * @param array<string, int|float> $fields The fields and multipliers
     * @return $this
     */
    public function mul(array $fields)
    {
        if (!isset($this->_updates['$mul'])) {
            $this->_updates['$mul'] = [];
        }

        $this->_updates['$mul'] = array_merge(
            $this->_updates['$mul'],
            $fields,
        );

        return $this;
    }

    /**
     * Set minimum value
     *
     * @param array<string, mixed> $fields The fields and minimum values
     * @return $this
     */
    public function min(array $fields)
    {
        if (!isset($this->_updates['$min'])) {
            $this->_updates['$min'] = [];
        }

        $this->_updates['$min'] = array_merge(
            $this->_updates['$min'],
            $fields,
        );

        return $this;
    }

    /**
     * Set maximum value
     *
     * @param array<string, mixed> $fields The fields and maximum values
     * @return $this
     */
    public function max(array $fields)
    {
        if (!isset($this->_updates['$max'])) {
            $this->_updates['$max'] = [];
        }

        $this->_updates['$max'] = array_merge(
            $this->_updates['$max'],
            $fields,
        );

        return $this;
    }

    /**
     * Set current date
     *
     * @param array<string>|string $fields The field(s) to set current date
     * @param bool                 $asDate Whether to set as date (true) or timestamp (false)
     * @return $this
     */
    public function currentDate(array|string $fields, bool $asDate = true)
    {
        if (!isset($this->_updates['$currentDate'])) {
            $this->_updates['$currentDate'] = [];
        }

        $fields = is_array($fields) ? $fields : [$fields];
        foreach ($fields as $field) {
            $this->_updates['$currentDate'][$field] = $asDate ? ['$type' => 'date'] : ['$type' => 'timestamp'];
        }

        return $this;
    }

    /**
     * Rename field
     *
     * @param string $oldName The old field name
     * @param string $newName The new field name
     * @return $this
     */
    public function rename(string $oldName, string $newName)
    {
        if (!isset($this->_updates['$rename'])) {
            $this->_updates['$rename'] = [];
        }

        $this->_updates['$rename'][$oldName] = $newName;

        return $this;
    }

    /**
     * Set on insert only
     *
     * @param array<string, mixed> $fields The fields to set on insert
     * @return $this
     */
    public function setOnInsert(array $fields)
    {
        if (!isset($this->_updates['$setOnInsert'])) {
            $this->_updates['$setOnInsert'] = [];
        }

        $this->_updates['$setOnInsert'] = array_merge(
            $this->_updates['$setOnInsert'],
            $fields,
        );

        return $this;
    }

    /**
     * Bitwise operations
     *
     * @param array<string, array<string, int>> $fields The fields and bitwise operations
     * @return $this
     */
    public function bit(array $fields)
    {
        if (!isset($this->_updates['$bit'])) {
            $this->_updates['$bit'] = [];
        }

        $this->_updates['$bit'] = array_merge_recursive(
            $this->_updates['$bit'],
            $fields,
        );

        return $this;
    }

    /**
     * Set upsert option
     *
     * @param bool $upsert Whether to upsert
     * @return $this
     */
    public function upsert(bool $upsert = true)
    {
        $this->_options['upsert'] = $upsert;

        return $this;
    }

    /**
     * Set array filters
     *
     * @param array<array<string, mixed>> $filters The array filters
     * @return $this
     */
    public function arrayFilters(array $filters)
    {
        $this->_options['arrayFilters'] = $filters;

        return $this;
    }

    /**
     * Set index hint
     *
     * @param array<string, int>|string $hint The index hint
     * @return $this
     */
    public function hint(array|string $hint)
    {
        $this->_options['hint'] = $hint;

        return $this;
    }

    /**
     * Get the update operations
     *
     * @return array<string, mixed>
     */
    public function getUpdate(): array
    {
        return $this->_updates;
    }

    /**
     * Get the update options
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->_options;
    }
}
