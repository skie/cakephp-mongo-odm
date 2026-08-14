<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Attribute;

use Attribute;
use Cake\Utility\Inflector;
use ReflectionClass;

/**
 * Declares that a Document class is embedded inside a parent document.
 *
 * Like `#[Document]`/`#[Field]`, this is class-level sugar: the embedded
 * document stores values through the `EntityInterface` field map, so the
 * attribute sits next to the `#[Field]` declarations and describes the
 * embedded shape (one vs many) and the parent field that holds it.
 *
 * ```php
 * #[Embedded(many: true, key: 'addresses')]
 * #[Field(name: 'street', type: 'string')]
 * #[Field(name: 'city', type: 'string')]
 * class Address extends Document
 * {
 * }
 * ```
 *
 * `Collection::embedOne()`/`embedMany()` derive `many`/`key`/`localKey` from
 * this attribute when the target class carries it.
 *
 * @see docs/reference/36-embedded-bake-plan.md
 * @see docs/reference/34-embedded-associations-complete-plan.md
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Embedded
{
    /**
     * @param bool               $many          `false` = `EmbedOne` shape, `true` = `EmbedMany`.
     * @param string|null        $key           Parent field holding the embedded data (defaults to tableized class basename).
     * @param string|null        $localKey      Override of `key` (same meaning; kept for symmetry with the association option).
     * @param class-string|null  $documentClass Embedded document class (defaults to the class the attribute is on).
     * @param string|null        $foreignKey    Optional foreign key hint (embedded data usually has none).
     */
    public function __construct(
        protected bool $many = false,
        protected ?string $key = null,
        protected ?string $localKey = null,
        protected ?string $documentClass = null,
        protected ?string $foreignKey = null,
    ) {
    }

    /**
     * Whether the embedded shape is many (`EmbedMany`).
     *
     * @return bool
     */
    public function many(): bool
    {
        return $this->many;
    }

    /**
     * The parent field holding the embedded data.
     *
     * @return string|null
     */
    public function key(): ?string
    {
        return $this->localKey ?? $this->key;
    }

    /**
     * The embedded document class.
     *
     * @return class-string|null
     */
    public function documentClass(): ?string
    {
        return $this->documentClass;
    }

    /**
     * Optional foreign key hint.
     *
     * @return string|null
     */
    public function foreignKey(): ?string
    {
        return $this->foreignKey;
    }

    /**
     * Reads the first `#[Embedded]` attribute on a Document class.
     *
     * Returns `null` when the class carries no `#[Embedded]` attribute.
     *
     * @param class-string $documentClass The Document class to inspect.
     * @return array{embedded: bool, many: bool, key: string}|null
     */
    public static function read(string $documentClass): ?array
    {
        static $cache = [];

        if (array_key_exists($documentClass, $cache)) {
            return $cache[$documentClass];
        }

        $reflection = new ReflectionClass($documentClass);
        $attributes = $reflection->getAttributes(self::class);
        if ($attributes === []) {
            return $cache[$documentClass] = null;
        }

        /** @var \Crustum\Mongo\ODM\Attribute\Embedded $embedded */
        $embedded = $attributes[0]->newInstance();

        $basename = substr($documentClass, (int)strrpos($documentClass, '\\') + 1);

        return $cache[$documentClass] = [
            'embedded' => true,
            'many' => $embedded->many(),
            'key' => $embedded->key() ?? Inflector::tableize($basename),
        ];
    }
}
