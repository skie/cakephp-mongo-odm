<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Attribute;

use Attribute;

/**
 * Declares the document-to-collection mapping on a concrete Document class.
 *
 * This attribute is optional sugar. A `Collection` subclass + explicit
 * configuration is the canonical CakePHP way; `#[Document]` only helps
 * locator auto-wiring and enables attribute-based schema derivation.
 *
 * ```php
 * #[Document(collection: 'users', primaryKey: '_id')]
 * #[Field(name: '_id', type: 'objectId', primaryKey: true)]
 * #[Field(name: 'username', type: 'string')]
 * final class User extends Document
 * {
 * }
 * ```
 *
 * @see docs/reference/11-entity-type-sugar.md
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Document
{
    /**
     * @param string|null $collection    Collection name; defaults to tableized class basename
     * @param string      $primaryKey    Primary key field name
     * @param string|null $documentClass Document class this mapping applies to
     */
    public function __construct(
        protected ?string $collection = null,
        protected string $primaryKey = '_id',
        protected ?string $documentClass = null,
    ) {
    }

    /**
     * Collection name.
     *
     * @return string|null
     */
    public function collection(): ?string
    {
        return $this->collection;
    }

    /**
     * Primary key field name.
     *
     * @return string
     */
    public function primaryKey(): string
    {
        return $this->primaryKey;
    }

    /**
     * Document class this mapping applies to.
     *
     * @return string|null
     */
    public function documentClass(): ?string
    {
        return $this->documentClass;
    }
}
