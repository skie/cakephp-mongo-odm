<?php
declare(strict_types=1);

namespace Crustum\Mongo\Migration\Util;

/**
 * Formats PHP arrays for baked migration files using short array syntax and
 * clean indentation, instead of `var_export`'s `array (...)` output.
 */
class PhpArrayPrinter
{
    /**
     * Renders an array as readable PHP code.
     *
     * @param array<mixed> $array The array to render
     * @param int $indentLevel Initial indentation level (spaces = level * 4)
     * @return string PHP array literal
     */
    public function print(array $array, int $indentLevel = 0): string
    {
        return $this->render($array, $indentLevel);
    }

    /**
     * Renders a single value (scalar, null, or array).
     *
     * @param mixed $value Value to render
     * @param int $level Current indentation level
     * @return string PHP literal
     */
    protected function render(mixed $value, int $level): string
    {
        if (is_array($value)) {
            return $this->renderArray($value, $level);
        }

        return var_export($value, true);
    }

    /**
     * Renders an array literal with short syntax.
     *
     * @param array<mixed> $array The array
     * @param int $level Current indentation level
     * @return string PHP array literal
     */
    protected function renderArray(array $array, int $level): string
    {
        if ($array === []) {
            return '[]';
        }

        $isList = array_is_list($array);
        $pad = str_repeat('    ', $level + 1);

        $lines = [];
        foreach ($array as $key => $value) {
            $prefix = $isList ? '' : var_export($key, true) . ' => ';

            if (is_array($value)) {
                $lines[] = $pad . $prefix . $this->renderArray($value, $level + 1) . ',';
            } else {
                $lines[] = $pad . $prefix . var_export($value, true) . ',';
            }
        }

        $closePad = str_repeat('    ', $level);

        return "[\n" . implode("\n", $lines) . "\n" . $closePad . ']';
    }
}
