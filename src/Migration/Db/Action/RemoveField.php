<?php
declare(strict_types=1);

namespace Crustum\Mongo\Migration\Db\Action;

/**
 * Removes a field from a collection's validator.
 */
class RemoveField extends Action
{
    /**
     * Constructor.
     *
     * @param string $collection Collection name
     * @param string $name Field name
     */
    public function __construct(
        protected string $collection,
        protected string $name,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'removeField';
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
}
