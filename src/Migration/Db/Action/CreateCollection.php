<?php
declare(strict_types=1);

namespace Crustum\Mongo\Migration\Db\Action;

/**
 * Creates a new collection with optional validator/options.
 */
class CreateCollection extends Action
{
    /**
     * Constructor.
     *
     * @param string $name Collection name
     * @param array<string, mixed> $options Collection creation options (validator, capped, …)
     */
    public function __construct(
        protected string $name,
        protected array $options = [],
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'createCollection';
    }

    /**
     * Returns the collection name.
     *
     * @return string
     */
    public function getCollectionName(): string
    {
        return $this->name;
    }

    /**
     * Returns the creation options.
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}
