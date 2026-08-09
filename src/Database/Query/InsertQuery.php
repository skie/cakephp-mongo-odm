<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Query;

use InvalidArgumentException;

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
     * The declared insert columns.
     *
     * @var list<string>
     */
    protected array $columns = [];

    /**
     * Records the columns to insert into.
     *
     * When set, later `values()` / `valuesMany()` documents are filtered to
     * these columns only.
     *
     * @param array<string> $columns The columns to insert into.
     * @return $this
     * @throws \InvalidArgumentException When there are 0 columns.
     */
    public function insert(array $columns): static
    {
        if ($columns === []) {
            throw new InvalidArgumentException('At least 1 column is required to perform an insert.');
        }

        $this->columns = array_values($columns);

        return $this;
    }

    /**
     * Sets the target collection to insert into.
     *
     * @param string $collection The collection name.
     * @return $this
     */
    public function into(string $collection): static
    {
        $this->collection = $collection;
        $this->applySchemaTypes();

        return $this;
    }

    /**
     * Sets the values for a single document to insert.
     *
     * @param array<string, mixed> $values The document.
     * @param bool $overwrite Whether to replace any previously set values.
     * @return $this
     */
    public function values(array $values, bool $overwrite = false): static
    {
        $values = $this->filterColumns($values);

        if ($overwrite) {
            $this->documents = [$values];
        } else {
            $this->documents[] = $values;
        }

        $this->dirty();

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
        $this->documents = array_merge($this->documents, array_map($this->filterColumns(...), $values));
        $this->dirty();

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
     * Filters a document down to the declared insert columns.
     *
     * @param array<string, mixed> $values The document.
     * @return array<string, mixed>
     */
    protected function filterColumns(array $values): array
    {
        if ($this->columns === []) {
            return $values;
        }

        return array_intersect_key($values, array_fill_keys($this->columns, null));
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
