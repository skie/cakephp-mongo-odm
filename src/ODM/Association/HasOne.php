<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association;

use Cake\Utility\Inflector;
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

    /** @return string */
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

    /** @return array<string>|string|null */
    public function getForeignKey(): string|array|null
    {
        return $this->foreignKey ??= $this->_modelKey($this->repositoryAlias($this->getSource()));
    }

    /** @return string */
    public function getProperty(): string
    {
        return $this->propertyName ??= Inflector::underscore(Inflector::singularize($this->name));
    }

    /**
     * Builds the has-one eager-loader callable.
     *
     * @param array<string, mixed> $options Loader options.
     * @return callable
     */
    public function eagerLoad(array $options): callable
    {
        $loaderOptions = [
            'finder' => fn() => $this->getTarget()->find(),
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
            ->lookup($this->getTarget()->getAlias())
            ->localField($this->fieldName($this->getBindingKey()))
            ->foreignField($this->fieldName($this->getForeignKey()))
            ->alias($this->getProperty());
        $builder->unwind('$' . $this->getProperty(), ['preserveNullAndEmptyArrays' => true]);

        return $builder->getPipeline();
    }
}
