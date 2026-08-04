<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association;

/**
 * Provides Cake-style association option normalization.
 *
 * Dotted paths are converted into nested arrays while preserving explicit
 * association configuration.
 *
 * @see cake60/src/ORM/AssociationsNormalizerTrait.php
 */
trait AssociationsNormalizerTrait
{
    /**
     * Normalizes dotted and nested association names.
     *
     * @param array<int|string, mixed>|string $associations Association names and options.
     * @return array<string, mixed>
     */
    protected function normalizeAssociations(array|string $associations): array
    {
        $result = [];
        foreach ((array)$associations as $key => $value) {
            if (is_int($key)) {
                $key = (string)$value;
                $value = [];
            }
            $parts = explode('.', (string)$key);
            $pointer = &$result;
            foreach ($parts as $part) {
                $pointer[$part] ??= [];
                $pointer = &$pointer[$part];
            }
            if (is_array($value)) {
                $pointer = array_replace_recursive($pointer, $value);
            } else {
                $pointer = $value;
            }
        }

        return $result;
    }
}
