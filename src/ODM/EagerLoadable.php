<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

/**
 * Represents one level in a normalized contain tree.
 *
 * Each node contains the association instance, loading configuration, and
 * the paths used when nesting the hydrated result.
 *
 * @see cake60/src/ORM/EagerLoadable.php
 * @see src/ODM/EagerLoadable.php
 */
class EagerLoadable
{
    /**
     * Child association nodes.
     *
     * @var array<string, self>
     */
    private array $associations = [];

    /**
     * Association alias.
     *
     * @var string
     */
    private string $name;

    /**
     * Association instance used to load this node.
     *
     * @var \Crustum\Mongo\ODM\Association|null
     */
    private ?Association $instance;

    /**
     * Association loading options.
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * Dotted association path.
     *
     * @var string
     */
    private string $aliasPath;

    /**
     * Dotted document property path.
     *
     * @var string|null
     */
    private ?string $propertyPath;

    /**
     * Whether the association can be loaded in the root pipeline.
     *
     * @var bool
     */
    private bool $canBeJoined;

    /**
     * Whether this node is used for matching.
     *
     * @var bool|null
     */
    private ?bool $forMatching;

    /**
     * Final target property name for result nesting.
     *
     * @var string|null
     */
    private ?string $targetProperty;

    /**
     * Constructor.
     *
     * @param string $name The association name.
     * @param \Crustum\Mongo\ODM\Association|null $instance The association instance.
     * @param array<string, mixed> $config Association loading options.
     * @param string $aliasPath The dotted association path.
     * @param string|null $propertyPath The dotted entity property path.
     * @param bool $canBeJoined Whether the association can be loaded in-pipeline.
     * @param bool|null $forMatching Whether the association is used for matching.
     * @param string|null $targetProperty The final property name.
     */
    public function __construct(
        string $name,
        ?Association $instance = null,
        array $config = [],
        string $aliasPath = '',
        ?string $propertyPath = null,
        bool $canBeJoined = false,
        ?bool $forMatching = null,
        ?string $targetProperty = null,
    ) {
        $this->name = $name;
        $this->instance = $instance;
        $this->config = $config;
        $this->aliasPath = $aliasPath;
        $this->propertyPath = $propertyPath;
        $this->canBeJoined = $canBeJoined;
        $this->forMatching = $forMatching;
        $this->targetProperty = $targetProperty;
    }

    /**
     * Returns the association alias.
     *
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Returns the association instance.
     *
     * @return \Crustum\Mongo\ODM\Association|null
     */
    public function instance(): ?Association
    {
        return $this->instance;
    }

    /**
     * Gets association loading options.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Sets association loading options.
     *
     * @param array<string, mixed> $config Association loading options.
     * @return $this
     */
    public function setConfig(array $config): static
    {
        $this->config = $config;

        return $this;
    }

    /**
     * Returns the dotted association path.
     *
     * @return string
     */
    public function aliasPath(): string
    {
        return $this->aliasPath;
    }

    /**
     * Returns the dotted document property path.
     *
     * @return string|null
     */
    public function propertyPath(): ?string
    {
        return $this->propertyPath;
    }

    /**
     * Returns whether this node can be joined in the root query.
     *
     * @return bool
     */
    public function canBeJoined(): bool
    {
        return $this->canBeJoined;
    }

    /**
     * Sets whether this node can be joined in the root query.
     *
     * @param bool $canBeJoined Whether the node can be joined.
     * @return $this
     */
    public function setCanBeJoined(bool $canBeJoined): static
    {
        $this->canBeJoined = $canBeJoined;

        return $this;
    }

    /**
     * Returns the matching marker, when present.
     *
     * @return bool|null
     */
    public function forMatching(): ?bool
    {
        return $this->forMatching;
    }

    /**
     * Returns the final target property name.
     *
     * @return string|null
     */
    public function targetProperty(): ?string
    {
        return $this->targetProperty;
    }

    /**
     * Replaces child association nodes.
     *
     * @param array<string, self> $associations Child nodes.
     * @return $this
     */
    public function setAssociations(array $associations): static
    {
        $this->associations = $associations;

        return $this;
    }

    /**
     * Gets child association nodes.
     *
     * @return array<string, self>
     */
    public function associations(): array
    {
        return $this->associations;
    }

    /**
     * Adds a child association node.
     *
     * @param string $name The association name.
     * @param self $association The child node.
     * @return $this
     */
    public function addAssociation(string $name, self $association): static
    {
        $this->associations[$name] = $association;

        return $this;
    }

    /**
     * Returns a Cake-style contain representation.
     *
     * @return array<string, mixed>
     */
    public function asContainArray(): array
    {
        $associations = [];
        foreach ($this->associations as $association) {
            $associations += $association->asContainArray();
        }

        return [$this->name => ['associations' => $associations, 'config' => $this->config]];
    }

    /**
     * Clones descendant nodes without sharing mutable tree state.
     *
     * @return void
     */
    public function __clone(): void
    {
        foreach ($this->associations as $name => $association) {
            $this->associations[$name] = clone $association;
        }
    }
}
