<?php
declare(strict_types=1);

namespace Crustum\Mongo\Migration\Db\Action;

/**
 * Declares a field to be added to a collection's validator.
 */
class AddField extends Action
{
    /**
     * Constructor.
     *
     * @param string $collection Collection name
     * @param string $name Field name
     * @param string $type Canonical Mongo type
     * @param array<string, mixed> $options Field options
     */
    public function __construct(
        protected string $collection,
        protected string $name,
        protected string $type,
        protected array $options = [],
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'addField';
    }

    /**
     * Returns the collection name.
     *
     * @return string
     */
    public function getCollectionName(): string
    {
        return $this->collection;
    }

    /**
     * Returns the field name.
     *
     * @return string
     */
    public function getFieldName(): string
    {
        return $this->name;
    }

    /**
     * Returns the canonical Mongo type.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Returns the field options.
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}
