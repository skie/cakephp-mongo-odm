<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association;

use Cake\Datasource\EntityInterface;
use Cake\Datasource\QueryInterface;
use Cake\Utility\Inflector;
use Closure;
use Crustum\Mongo\ODM\Association;
use Crustum\Mongo\ODM\Association\Loader\LookupLoader;
use Crustum\Mongo\ODM\Association\Loader\SelectLoader;

/**
 * Represents a one-to-one relationship from the source document.
 *
 * @see cake60/src/ORM/Association/HasOne.php
 */
class HasOne extends Association
{
    /** Valid loading strategies for this association. */
    /**
     * @var array<string>
     */
    protected array $validStrategies = [self::STRATEGY_SELECT, self::STRATEGY_LOOKUP];

    /**
     * Gets the relationship type.
     *
     * @return string
     */
    public function type(): string
    {
        return self::ONE_TO_ONE;
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
     * The source document owns the foreign key.
     *
     * @return bool
     */
    public function isOwningSide(): bool
    {
        return true;
    }

    /**
     * Saves the associated target document and back-fills the foreign key.
     *
     * @param \Cake\Datasource\EntityInterface $entity The source document.
     * @param array<string, mixed> $options Save options.
     * @return \Cake\Datasource\EntityInterface|false
     */
    public function saveAssociated(EntityInterface $entity, array $options = []): EntityInterface|false
    {
        $targetEntity = $entity->get($this->getProperty());
        if (!$targetEntity instanceof EntityInterface) {
            return $entity;
        }

        $foreignKeys = array_values(array_filter(
            (array)$this->getForeignKey(),
            is_string(...),
        ));
        $properties = array_combine(
            $foreignKeys,
            $entity->extract((array)$this->getBindingKey()),
        );
        $targetEntity->patch($properties, ['guard' => false]);

        if (!$this->getTarget()->save($targetEntity, $options)) {
            $targetEntity->unset(array_keys($properties));

            return false;
        }

        return $entity;
    }

    /**
     * Gets the target foreign key.
     *
     * @return array<string>|string|null
     */
    public function getForeignKey(): string|array|false|null
    {
        return $this->foreignKey ??= $this->_modelKey($this->repositoryAlias($this->getSource()));
    }

    /**
     * Gets the target property name.
     *
     * @return string
     */
    public function getProperty(): string
    {
        return $this->propertyName ??= Inflector::underscore(Inflector::singularize($this->name));
    }

    /**
     * Builds the has-one eager-loader callable.
     *
     * @param array<string, mixed> $options Loader options.
     * @return \Closure
     */
    public function eagerLoader(array $options): Closure
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
     * Builds a lookup pipeline for the association.
     *
     * @param array<string, mixed> $options Pipeline options.
     * @return array<int, array<string, mixed>>
     */
    public function buildPipeline(array $options = []): array
    {
        $builder = $this->buildAggregation();
        $builder
            ->lookup($this->getTarget()->getCollection())
            ->localField($this->fieldName($this->getBindingKey()))
            ->foreignField($this->fieldName($this->getForeignKey()))
            ->alias($this->getProperty());
        $builder->unwind('$' . $this->getProperty(), ['preserveNullAndEmptyArrays' => true]);
        $this->applyPipelineOptions($builder, $options);

        return $builder->getPipeline();
    }

    /**
     * @inheritDoc
     */
    public function cascadeDelete(EntityInterface $entity, array $options = []): bool
    {
        return (new DependentDeleteHelper())->cascadeDelete($this, $entity, $options);
    }
}
