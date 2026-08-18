<?php
declare(strict_types=1);

namespace Crustum\Mongo\View\Helper;

use Cake\View\Helper;

/**
 * Migration helper for baking Mongo migration files.
 *
 * Mirrors the cakephp/migrations MigrationHelper surface used by the bake
 * templates, adapted for Mongo collection operations.
 *
 * @extends \Cake\View\Helper<\Cake\View\View>
 */
class MongoMigrationHelper extends Helper
{
    /**
     * Returns the terminator method for a collection builder chain.
     *
     * @param string|null $action Migration action name
     * @return string
     */
    public function collectionMethod(?string $action = null): string
    {
        if ($action === 'drop_table') {
            return 'drop';
        }

        if ($action === 'create_table') {
            return 'create';
        }

        return 'update';
    }

    /**
     * Returns the method to use for index manipulation.
     *
     * @param string|null $action Migration action name
     * @return string
     */
    public function indexMethod(?string $action = null): string
    {
        if ($action === 'drop_field') {
            return 'removeIndex';
        }

        return 'addIndex';
    }

    /**
     * Returns the method to use for field manipulation.
     *
     * @param string|null $action Migration action name
     * @return string
     */
    public function columnMethod(?string $action = null): string
    {
        if ($action === 'drop_field') {
            return 'removeField';
        }

        return 'addColumn';
    }

    /**
     * Builds column options for baked migration output from a parsed field.
     *
     * @param array<string, mixed> $definition Parsed field definition
     * @return array<string, mixed>
     */
    public function fieldOptions(array $definition): array
    {
        $options = [];

        if ($definition['null'] ?? false) {
            $options['null'] = true;
        }

        if (array_key_exists('default', $definition)) {
            $options['default'] = $definition['default'];
        }

        if (isset($definition['length'])) {
            $options['length'] = $definition['length'];
        }

        if (isset($definition['precision'])) {
            $options['precision'] = $definition['precision'];
        }

        if (isset($definition['scale'])) {
            $options['scale'] = $definition['scale'];
        }

        ksort($options);

        return $options;
    }

    /**
     * Returns a string-like representation of a baked option value.
     *
     * @param string|float|int|bool|null $value A value to represent as a string
     * @param bool $numbersAsString Set to true to return numbers as strings
     * @return string|float|int|bool|null
     */
    public function value(string|float|int|bool|null $value, bool $numbersAsString = false): string|float|int|bool|null
    {
        if (in_array($value, [null, 'null', 'NULL'], true)) {
            return 'null';
        }

        if ($value === 'true' || $value === 'false') {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (!$numbersAsString && is_numeric($value)) {
            return str_contains((string)$value, '.') ? (float)$value : (int)$value;
        }

        return sprintf("'%s'", addslashes((string)$value));
    }

    /**
     * Returns an array converted into a formatted multiline string.
     *
     * @param array<string|int, mixed> $list Array of items to stringify
     * @param array<string, mixed> $options Formatting options
     * @param array<string, mixed> $wantedOptions Options to include in the output
     * @return string
     */
    public function stringifyList(array $list, array $options = [], array $wantedOptions = []): string
    {
        if ($wantedOptions !== []) {
            $list = array_intersect_key($list, $wantedOptions);
        }

        $options += [
            'indent' => 2,
        ];

        if ($list === []) {
            return '';
        }

        ksort($list);

        foreach ($list as $key => &$value) {
            if (is_array($value)) {
                $value = $this->stringifyList($value, [
                    'indent' => $options['indent'] + 1,
                ]);
                $value = sprintf('[%s]', $value);
            } else {
                $value = $this->value($value, $key === 'default');
            }

            if (!is_numeric($key)) {
                $value = sprintf("'%s' => %s", $key, $value);
            }
        }

        $start = '';
        $end = '';
        $join = ', ';
        if ($options['indent']) {
            $join = ',';
            $start = "\n" . str_repeat('    ', $options['indent']);
            $join .= $start;
            $end = "\n" . str_repeat('    ', $options['indent'] - 1);
        }

        return $start . implode($join, $list) . ',' . $end;
    }
}
