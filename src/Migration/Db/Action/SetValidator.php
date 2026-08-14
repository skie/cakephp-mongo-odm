<?php
declare(strict_types=1);

namespace Crustum\Mongo\Migration\Db\Action;

/**
 * Sets (or clears) the `$jsonSchema` validator of a collection.
 */
class SetValidator extends Action
{
    /**
     * Constructor.
     *
     * @param string $collection Collection name
     * @param array<string, mixed>|null $validator The `$jsonSchema` rules or null
     */
    public function __construct(
        protected string $collection,
        protected ?array $validator,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'setValidator';
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
     * Returns the validator.
     *
     * @return array<string, mixed>|null
     */
    public function getValidator(): ?array
    {
        return $this->validator;
    }
}
