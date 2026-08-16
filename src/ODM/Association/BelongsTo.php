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
 * Represents a many-to-one relationship from the source document.
 *
 * @see cake60/src/ORM/Association/BelongsTo.php
 */
class BelongsTo extends Association
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
        return self::MANY_TO_ONE;
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
     * The target document is the owning side of a belongs-to association.
     *
     * Matches cake60, where `BelongsTo::isOwningSide($source)` is `false`, so
     * the default binding key resolves to the target's primary key.
     *
     * @return bool
     */
    public function isOwningSide(): bool
    {
        return false;
    }

    /**
     * Saves the associated target document and back-fills the foreign key.
     *
     * @param \Cake\Datasource\EntityInterface $document The source document.
     * @param array<string, mixed> $options Save options.
     * @return \Cake\Datasource\EntityInterface|false
     */
    public function saveAssociated(EntityInterface $document, array $options = []): EntityInterface|false
    {
        $targetEntity = $document->get($this->getProperty());
        if (!$targetEntity instanceof EntityInterface) {
            return $document;
        }

        $saved = $this->getTarget()->save($targetEntity, $options);
        if (!$saved instanceof EntityInterface) {
            return false;
        }

        $foreignKey = array_values(array_filter(
            (array)$this->getForeignKey(),
            is_string(...),
        ));
        $reference = $saved->extract((array)$this->getBindingKey());
        $document->patch(array_combine($foreignKey, $reference), ['guard' => false]);

        return $document;
    }

    /**
     * Gets the target foreign key.
     *
     * @return array<string>|string|null
     */
    public function getForeignKey(): string|array|false|null
    {
        return $this->foreignKey ??= $this->_modelKey($this->repositoryAlias($this->getTarget()));
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
     * Builds the belongs-to eager-loader callable.
     *
     * @param array<string, mixed> $options Loader options.
     * @return \Closure
     */
    public function eagerLoader(array $options): Closure
    {
        $finder = $this->getFinder();
        $loaderOptions = [
            'finder' => $options['finder'] ?? fn(): QueryInterface => $this->getTarget()->find($this->extractFinder($finder)[0]),
            'foreignKey' => $this->getForeignKey(),
            'bindingKey' => $this->getBindingKey(),
            'nestKey' => $this->getProperty(),
            'associationType' => $this->type(),
            'strategy' => $this->getStrategy(),
            'conditions' => $this->getConditions(),
        ];
        $isNestedLoad = !empty($options['sourcePath']);
        if ($this->getStrategy() === self::STRATEGY_LOOKUP && !$isNestedLoad) {
            return (new LookupLoader(['association' => $this]))->buildEagerLoader($options + $loaderOptions);
        }

        return (new SelectLoader($loaderOptions))->buildEagerLoader($options);
    }

    /**
     * BelongsTo associations are never cleared in a cascading delete scenario.
     *
     * @param \Cake\Datasource\EntityInterface $document The entity that started the cascaded delete.
     * @param array<string, mixed> $options The options for the original delete.
     * @return bool Success.
     */
    public function cascadeDelete(EntityInterface $document, array $options = []): bool
    {
        return true;
    }

    /**
     * BelongsTo targets load through an in-pipeline `$lookup`.
     *
     * @return bool
     */
    public function canBeJoined(): bool
    {
        return true;
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
        $localKey = $this->fieldName($this->getForeignKey());
        if (!empty($options['lookupPrefix'])) {
            $localKey = $options['lookupPrefix'] . '.' . $localKey;
        }

        $negateMatch = !empty($options['negateMatch']);
        $pipeline = [];
        if ($negateMatch && !empty($options['conditions'])) {
            $pipeline[] = ['$match' => $this->normalizePipelineConditions($options['conditions'])];
        }

        $lookup = $builder
            ->lookup($this->getTarget()->getCollection())
            ->localField($localKey)
            ->foreignField($this->fieldName($this->getBindingKey()))
            ->alias($this->getProperty());
        if ($pipeline !== []) {
            $lookup->pipeline($pipeline);
        }

        $builder->unwind('$' . $this->getProperty(), ['preserveNullAndEmptyArrays' => true]);

        if ($negateMatch) {
            $builder->match([$this->getProperty() => null]);
        } else {
            if (!empty($options['matching']) && !empty($options['conditions'])) {
                $options['conditions'] = $this->prefixMatchConditions($options['conditions'], $this->getProperty());
            }

            $this->applyPipelineOptions($builder, $options);
        }

        return $builder->getPipeline();
    }
}
