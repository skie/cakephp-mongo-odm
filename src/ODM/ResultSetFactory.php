<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use MongoDB\Model\BSONDocument;

/**
 * Creates hydrated ODM result sets from Mongo rows.
 *
 * @see cake60/src/ORM/ResultSetFactory.php
 * @template T of array|\Cake\Datasource\EntityInterface
 */
final class ResultSetFactory
{
    /**
     * The entity class used for hydrated rows.
     *
     * @var class-string<\Crustum\Mongo\ODM\Document>
     */
    private readonly string $documentClass;

    /**
     * Constructor.
     *
     * @param class-string<\Crustum\Mongo\ODM\Document> $documentClass Entity class for hydration.
     */
    public function __construct(string $documentClass = Document::class)
    {
        $this->documentClass = $documentClass;
    }

    /**
     * Creates a result set instance.
     *
     * @param iterable<array-key, mixed> $results
     * @param array{hydrate?: bool, source?: string|null} $options
     * @return \Crustum\Mongo\ODM\ResultSet<array-key, mixed>
     */
    public function createResultSet(iterable $results, array $options = []): ResultSet
    {
        $options += ['hydrate' => true, 'source' => null];

        if (!$options['hydrate']) {
            return new ResultSet($results);
        }

        $hydrated = [];
        foreach ($results as $key => $row) {
            $hydrated[$key] = $this->hydrateValue($row, $options['source']);
        }

        return new ResultSet($hydrated);
    }

    /**
     * Hydrates associative embedded rows while retaining scalar and list fields.
     *
     * @param mixed $value The value to hydrate.
     * @param string|null $source The source alias for hydrated documents.
     * @return mixed The hydrated value.
     */
    private function hydrateValue(mixed $value, ?string $source): mixed
    {
        if ($value instanceof Document) {
            return $value;
        }

        if ($value instanceof BSONDocument) {
            $value = $value->getArrayCopy();
        }

        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->hydrateValue($item, $source);
            }

            return $value;
        }

        $data = [];
        foreach ($value as $key => $item) {
            $data[$key] = $this->hydrateValue($item, $source);
        }

        $documentClass = $this->documentClass;

        return new $documentClass($data, [
            'markClean' => true,
            'markNew' => false,
            'source' => $source,
        ]);
    }
}
