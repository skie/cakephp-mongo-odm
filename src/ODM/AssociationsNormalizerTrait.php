<?php
declare(strict_types=1);

/**
 * Migrated from Cake core `Cake\ORM\AssociationsNormalizerTrait`.
 */
namespace Crustum\Mongo\ODM;

/**
 * Contains methods for parsing the associated collections array that is
 * typically passed to a save or marshalling operation.
 */
trait AssociationsNormalizerTrait
{
    /**
     * List of option names accepted inside an association's options array.
     *
     * Unknown string keys are treated as nested association aliases so that
     * contain-style associated arrays also work with lowercase aliases.
     *
     * @var array<string, int>
     */
    protected array $associationOptions = [
        'associated' => 1,
        'association' => 1,
        'atomic' => 1,
        'checkExisting' => 1,
        'checkRules' => 1,
        'fields' => 1,
        'forceNew' => 1,
        'ids' => 1,
        'isMerge' => 1,
        'junctionProperty' => 1,
        'onlyIds' => 1,
        'accessibleFields' => 1,
        'patchableFields' => 1,
        'sourceCollection' => 1,
        'strictFields' => 1,
        'validate' => 1,
        '_cleanOnSuccess' => 1,
        '_primary' => 1,
    ];

    /**
     * Returns an array out of the original passed associations list where dot
     * notation is transformed into nested arrays so they can be parsed by
     * other routines.
     *
     * This method supports the same nested array format as contain(), allowing:
     * - Dot notation: ['First.Second']
     * - Nested arrays: ['First' => ['Second', 'Third']]
     * - Mixed with options: ['First' => ['Second', 'onlyIds' => true]]
     *
     * Known option names are parsed as options. Unknown string keys are parsed
     * as association aliases. If an association alias collides with an option
     * name, use dot notation or the explicit `associated` option.
     *
     * @param array<int|string, mixed>|string $associations The array of included associations.
     * @return array<string, mixed>
     */
    protected function normalizeAssociations(array|string $associations): array
    {
        $result = [];
        foreach ((array)$associations as $collection => $options) {
            $pointer = &$result;

            if (is_int($collection)) {
                $collection = $options;
                $options = [];
            }

            if (is_array($options) && !isset($options['associated'])) {
                [$nestedAssociations, $actualOptions] = $this->extractAssociations($options);
                if ($nestedAssociations) {
                    $actualOptions['associated'] = $this->normalizeAssociations($nestedAssociations);
                }

                $options = $actualOptions;
            }

            if (!str_contains((string)$collection, '.')) {
                $result[$collection] = $options;
                continue;
            }

            $path = explode('.', (string)$collection);
            $collection = array_pop($path);
            $first = array_shift($path);
            assert(is_string($first));

            $pointer += [$first => []];
            $pointer = &$pointer[$first];
            $pointer += ['associated' => []];

            foreach ($path as $t) {
                $pointer += ['associated' => []];
                $pointer['associated'] += [$t => []];
                $pointer['associated'][$t] += ['associated' => []];
                $pointer = &$pointer['associated'][$t];
            }

            $pointer['associated'] += [$collection => []];
            $pointer['associated'][$collection] = $options + $pointer['associated'][$collection];
        }

        return $result['associated'] ?? $result;
    }

    /**
     * Extracts association names from an options array, separating them from
     * actual options.
     *
     * This allows a contain-style nested array format in the shared `associated`
     * option:
     *
     * - ['Users', 'Comments'] contains nested associations
     * - ['Users' => [...], 'Comments'] contains nested associations
     * - ['onlyIds' => true, 'validate' => false] contains options
     * - ['Users', 'onlyIds' => true] contains associations and options
     *
     * @param array<int|string, mixed> $options The options array that may contain nested associations.
     * @return array{0: array<int|string, mixed>, 1: array<string, mixed>}
     */
    protected function extractAssociations(array $options): array
    {
        $associations = [];
        $actualOptions = [];

        foreach ($options as $key => $value) {
            if (is_int($key)) {
                $associations[] = $value;
                continue;
            }

            if (isset($this->associationOptions[$key])) {
                $actualOptions[$key] = $value;
                continue;
            }

            $associations[$key] = $value;
        }

        return [$associations, $actualOptions];
    }
}
