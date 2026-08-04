<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association;

use Cake\Datasource\QueryInterface;
use Cake\Utility\Inflector;
use Crustum\Mongo\ODM\Association\Loader\LookupLoader;
use Crustum\Mongo\ODM\Association\Loader\SelectLoader;

/**
 * Represents a many-to-many relationship through a join collection.
 *
 * @see cake60/src/ORM/Association/BelongsToMany.php
 */
class BelongsToMany extends Association
{
    /** Valid loading strategies for this association. */
    /**
     * @var array<string>
     */
    protected array $validStrategies = [self::STRATEGY_SELECT, self::STRATEGY_LOOKUP];

    /**
     * Join collection alias.
     */
    protected ?string $through = null;

    /**
     * Foreign key from the join collection to the source.
     */
    protected ?string $joinForeignKey = null;

    /**
     * Foreign key from the join collection to the target.
     */
    protected ?string $targetForeignKey = null;

    /**
     * Constructor.
     *
     * @param string $alias Association alias.
     * @param array<string, mixed> $options Association configuration.
     */
    public function __construct(string $alias, array $options = [])
    {
        parent::__construct($alias, $options);
        $this->through = $options['through'] ?? null;
        $this->joinForeignKey = $options['joinForeignKey'] ?? null;
        $this->targetForeignKey = $options['targetForeignKey'] ?? null;
    }

    /** @return string */
    public function type(): string
    {
        return self::MANY_TO_MANY;
    }

    /**
     * Gets the default loading strategy.
     *
     * @return string
     */
    protected function defaultStrategy(): string
    {
        return self::STRATEGY_SELECT;
    }

    /**
     * Gets the join collection foreign key for the source.
     *
     * @return array<string>|string|null
     */
    public function getForeignKey(): string|array|null
    {
        return $this->foreignKey ??= $this->_modelKey($this->repositoryAlias($this->getSource()));
    }

    /**
     * Gets the target entity property name.
     *
     * @return string
     */
    public function getProperty(): string
    {
        return $this->propertyName ??= Inflector::underscore($this->name);
    }

    /** @return string|null */
    public function getThrough(): ?string
    {
        return $this->through;
    }

    /**
     * @param string $through Join collection alias.
     * @return $this
     */
    public function setThrough(string $through): static
    {
        $this->through = $through;

        return $this;
    }

    /** @return string|null */
    public function getJoinForeignKey(): ?string
    {
        return $this->joinForeignKey;
    }

    /**
     * @param string $key Join collection source key.
     * @return $this
     */
    public function setJoinForeignKey(string $key): static
    {
        $this->joinForeignKey = $key;

        return $this;
    }

    /** @return string|null */
    public function getTargetForeignKey(): ?string
    {
        return $this->targetForeignKey ??= $this->_modelKey($this->repositoryAlias($this->getTarget()));
    }

    /**
     * @param string $key Join collection target key.
     * @return $this
     */
    public function setTargetForeignKey(string $key): static
    {
        $this->targetForeignKey = $key;

        return $this;
    }

    /**
     * Builds the many-to-many eager-loader callable.
     *
     * @param array<string, mixed> $options Loader options.
     * @return callable
     */
    public function eagerLoad(array $options): callable
    {
        $loaderOptions = [
            'finder' => fn(): QueryInterface => $this->getTarget()->find(),
            'foreignKey' => $this->getForeignKey(),
            'bindingKey' => $this->getBindingKey(),
            'nestKey' => $this->getProperty(),
            'associationType' => $this->type(),
            'strategy' => $this->getStrategy(),
            'conditions' => $this->getConditions(),
        ];
        if ($this->getStrategy() === self::STRATEGY_LOOKUP) {
            return (new LookupLoader(['association' => $this]))->buildEagerLoader($options + $loaderOptions);
        }

        return (new SelectLoader($loaderOptions))->buildEagerLoader($options);
    }

    /**
     * Builds lookup stages through the join collection.
     *
     * @param array<string, mixed> $options Pipeline options.
     * @return array<int, array<string, mixed>>
     */
    public function buildPipeline(array $options = []): array
    {
        if ($this->through === null || $this->joinForeignKey === null || $this->targetForeignKey === null) {
            return [];
        }

        $builder = $this->buildAggregation();
        $join = '_join_' . $this->getProperty();
        $builder
            ->lookup($this->through)
            ->localField($this->fieldName($this->getBindingKey()))
            ->foreignField($this->joinForeignKey)
            ->alias($join);
        $builder
            ->lookup($this->getTarget()->getAlias())
            ->localField($join . '.' . $this->getTargetForeignKey())
            ->foreignField('_id')
            ->alias($this->getProperty());

        return $builder->getPipeline();
    }
}
