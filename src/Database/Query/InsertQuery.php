<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Query;

/**
 * Insert query for MongoDB insertOne/insertMany operations.
 *
 * `execute()` returns the inserted identifiers as strings.
 *
 * @see cake50/src/Database/Query/InsertQuery.php
 */
class InsertQuery extends Query
{
    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    protected array $documents = [];

    /**
     * Sets the values for a single document to insert.
     *
     * @param array<string, mixed> $values The document.
     * @param bool $overwrite Whether to replace any previously set values.
     * @return $this
     */
    public function values(array $values, bool $overwrite = false): static
    {
        if ($overwrite) {
            $this->documents = [$values];
        } else {
            $this->documents[] = $values;
        }

        return $this;
    }

    /**
     * Sets multiple documents to insert.
     *
     * @param list<array<string, mixed>> $values The documents.
     * @return $this
     */
    public function valuesMany(array $values): static
    {
        $this->documents = array_merge($this->documents, $values);

        return $this;
    }

    /**
     * Returns the documents queued for insert.
     *
     * @return list<array<string, mixed>>
     */
    public function getValues(): array
    {
        return $this->documents;
    }

    /**
     * @inheritDoc
     */
    public function compile(): array
    {
        return [
            'type' => self::TYPE_INSERT,
            'collection' => $this->collection,
            'documents' => $this->documents,
            'options' => [],
        ];
    }
}
