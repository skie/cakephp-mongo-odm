<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Schema;

use RuntimeException;

/**
 * Index value object.
 *
 * Ported from `Cake\Database\Schema\Index` and adapted for MongoDB: instead of
 * a plain column list, the Mongo index key is a map of `field => direction`
 * (e.g. `['author_id' => 1]`), where the value may be a sort direction or a
 * special index type such as `'text'` or `'2dsphere'`.
 *
 * The remaining attributes map onto `MongoDB\Collection::createIndex()` options.
 *
 * @inspired-by \Cake\Database\Schema\Index
 */
class Index
{
    /**
     * @var string
     */
    public const INDEX = 'index';

    /**
     * @var string
     */
    public const UNIQUE = 'unique';

    /**
     * @var string
     */
    public const TEXT = 'text';

    /**
     * @var string
     */
    public const GEO = 'geo';

    /**
     * @var string
     */
    public const GEO_2D = '2d';

    /**
     * @var string
     */
    public const GEO_2DSPHERE = '2dsphere';

    /**
     * @var string
     */
    public const HASHED = 'hashed';

    /**
     * @var string
     */
    public const VECTOR = 'vector';

    /**
     * Constructor.
     *
     * @param string $name The name of the index.
     * @param array<string, int|string> $key The index key map (`field => direction`).
     * @param string $type The type of index, e.g. 'index', 'unique', 'text', 'geo', 'vector'.
     * @param bool $unique Whether the index enforces uniqueness.
     * @param bool $sparse Whether the index is sparse.
     * @param int|null $expireAfterSeconds TTL for expiring documents (used by TTL indexes).
     * @param array<string, mixed>|null $partialFilterExpression Filter for partial indexes.
     * @param array<string, mixed>|null $collation Collation options.
     * @param array<string, mixed> $options Additional createIndex options.
     */
    public function __construct(
        protected string $name,
        protected array $key,
        protected string $type = self::INDEX,
        protected bool $unique = false,
        protected bool $sparse = false,
        protected ?int $expireAfterSeconds = null,
        protected ?array $partialFilterExpression = null,
        protected ?array $collation = null,
        protected array $options = [],
    ) {
    }

    /**
     * Sets the index key map.
     *
     * @param array<string, int|string> $key Key map
     * @return $this
     */
    public function setKey(array $key): static
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Gets the index key map.
     *
     * @return array<string, int|string>
     */
    public function getKey(): array
    {
        return $this->key;
    }

    /**
     * Sets the index type.
     *
     * @param string $type Type
     * @return $this
     */
    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Gets the index type.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Sets the index name.
     *
     * @param string $name Name
     * @return $this
     */
    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Gets the index name.
     *
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Sets whether the index enforces uniqueness.
     *
     * @param bool $unique Unique
     * @return $this
     */
    public function setUnique(bool $unique): static
    {
        $this->unique = $unique;

        return $this;
    }

    /**
     * Gets whether the index enforces uniqueness.
     *
     * @return bool
     */
    public function getUnique(): bool
    {
        return $this->unique;
    }

    /**
     * Sets whether the index is sparse.
     *
     * @param bool $sparse Sparse
     * @return $this
     */
    public function setSparse(bool $sparse): static
    {
        $this->sparse = $sparse;

        return $this;
    }

    /**
     * Gets whether the index is sparse.
     *
     * @return bool
     */
    public function getSparse(): bool
    {
        return $this->sparse;
    }

    /**
     * Sets the TTL value for expiring documents.
     *
     * @param int|null $expireAfterSeconds TTL in seconds
     * @return $this
     */
    public function setExpireAfterSeconds(?int $expireAfterSeconds): static
    {
        $this->expireAfterSeconds = $expireAfterSeconds;

        return $this;
    }

    /**
     * Gets the TTL value for expiring documents.
     *
     * @return int|null
     */
    public function getExpireAfterSeconds(): ?int
    {
        return $this->expireAfterSeconds;
    }

    /**
     * Sets the filter for a partial index.
     *
     * @param array<string, mixed>|null $partialFilterExpression Filter expression
     * @return $this
     */
    public function setPartialFilterExpression(?array $partialFilterExpression): static
    {
        $this->partialFilterExpression = $partialFilterExpression;

        return $this;
    }

    /**
     * Gets the filter for a partial index.
     *
     * @return array<string, mixed>|null
     */
    public function getPartialFilterExpression(): ?array
    {
        return $this->partialFilterExpression;
    }

    /**
     * Sets the collation options.
     *
     * @param array<string, mixed>|null $collation Collation options
     * @return $this
     */
    public function setCollation(?array $collation): static
    {
        $this->collation = $collation;

        return $this;
    }

    /**
     * Gets the collation options.
     *
     * @return array<string, mixed>|null
     */
    public function getCollation(): ?array
    {
        return $this->collation;
    }

    /**
     * Sets additional createIndex options.
     *
     * @param array<string, mixed> $options Options
     * @return $this
     */
    public function setOptions(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Gets additional createIndex options.
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Utility method that maps an array of index options to this object's methods.
     *
     * @param array<string, mixed> $attributes Attributes to set.
     * @throws \RuntimeException
     * @return $this
     */
    public function setAttributes(array $attributes): static
    {
        $validOptions = [
            'key',
            'type',
            'name',
            'unique',
            'sparse',
            'expireAfterSeconds',
            'partialFilterExpression',
            'collation',
            'options',
        ];

        foreach ($attributes as $attr => $value) {
            if (!in_array($attr, $validOptions, true)) {
                throw new RuntimeException(sprintf('"%s" is not a valid index option.', $attr));
            }

            $method = 'set' . ucfirst($attr);
            $this->$method($value);
        }

        return $this;
    }

    /**
     * Convert this index into an array that is compatible with the Index constructor.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->getName(),
            'key' => $this->getKey(),
            'type' => $this->getType(),
            'unique' => $this->getUnique(),
            'sparse' => $this->getSparse(),
            'expireAfterSeconds' => $this->getExpireAfterSeconds(),
            'partialFilterExpression' => $this->getPartialFilterExpression(),
            'collation' => $this->getCollation(),
            'options' => $this->getOptions(),
        ];
    }

    /**
     * Builds the `createIndex()` option array for `MongoDB\Collection`.
     *
     * `name`, `unique`, `sparse`, `expireAfterSeconds`, `partialFilterExpression`
     * and `collation` are first-class; any remaining options are merged on top.
     *
     * @return array<string, mixed>
     */
    public function createIndexOptions(): array
    {
        $options = [
            'name' => $this->getName(),
            'unique' => $this->getUnique(),
            'sparse' => $this->getSparse(),
        ];

        if ($this->expireAfterSeconds !== null) {
            $options['expireAfterSeconds'] = $this->expireAfterSeconds;
        }

        if ($this->partialFilterExpression !== null) {
            $options['partialFilterExpression'] = $this->partialFilterExpression;
        }

        if ($this->collation !== null) {
            $options['collation'] = $this->collation;
        }

        return $options + $this->options;
    }

    /**
     * Builds an Index from an attribute array (e.g. from a schema definition map).
     *
     * Accepts either the Mongo shape (`key` map, `options`) or the Cake shape
     * (`columns` list) and normalizes to the Mongo key map.
     *
     * @param string $name Index name
     * @param array<string, mixed> $attrs Index attributes
     * @return static
     */
    public static function fromAttributes(string $name, array $attrs): static
    {
        if (!isset($attrs['key']) && isset($attrs['columns'])) {
            $attrs['key'] = array_fill_keys((array)$attrs['columns'], 1);
        }

        if (empty($attrs['key']) || !is_array($attrs['key'])) {
            throw new RuntimeException(sprintf('Index "%s" must define a non-empty key map.', $name));
        }

        $options = $attrs['options'] ?? [];
        $unique = (bool)($attrs['unique'] ?? $options['unique'] ?? false);
        $sparse = (bool)($attrs['sparse'] ?? $options['sparse'] ?? false);

        $type = $attrs['type'] ?? self::INDEX;
        if ($unique && $type === self::INDEX) {
            $type = self::UNIQUE;
        }

        return new static(
            $name,
            $attrs['key'],
            $type,
            $unique,
            $sparse,
            isset($attrs['expireAfterSeconds']) ? (int)$attrs['expireAfterSeconds'] : null,
            $attrs['partialFilterExpression'] ?? null,
            $attrs['collation'] ?? null,
            $options,
        );
    }
}
