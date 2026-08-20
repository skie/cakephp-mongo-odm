<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association;

use Cake\Datasource\EntityInterface;
use Cake\Datasource\QueryInterface;
use Cake\Utility\Inflector;
use Closure;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\ODM\Association;
use Crustum\Mongo\ODM\Association\Loader\LookupLoader;
use Crustum\Mongo\ODM\Association\Loader\SelectLoader;
use Override;

/**
 * Represents a many-to-one relationship from the source document.
 *
 * @inspired-by \Cake\ORM\Association\BelongsTo
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
    #[Override]
    protected function defaultStrategy(): string
    {
        return self::STRATEGY_LOOKUP;
    }

    /**
     * The target document is the owning side of a belongs-to association.
     *
     * Matches cake60, where `BelongsTo::isOwningSide($source)` is `false`, so
     * the default binding key resolves to the target's primary key.
     *
     * @return bool
     */
    #[Override]
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
    #[Override]
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
    #[Override]
    public function getForeignKey(): string|array|false|null
    {
        return $this->foreignKey ??= $this->_modelKey($this->repositoryAlias($this->getTarget()));
    }

    /**
     * Gets the target property name.
     *
     * @return string
     */
    #[Override]
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
        if ($this->usesLookup($options)) {
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
    #[Override]
    public function cascadeDelete(EntityInterface $document, array $options = []): bool
    {
        return true;
    }

    /**
     * Builds a lookup pipeline for the association.
     *
     * @param array<string, mixed> $options Pipeline options.
     * @return list<array<string, mixed>>
     */
    public function buildPipeline(array $options = []): array
    {
        $builder = $this->buildAggregation();
        $property = $this->getProperty();
        $lookupAlias = $this->containedLookupAlias($property, $options);
        $pipelineOptions = $this->mergePipelineConditions($options);
        $disableForeignKey = ($options['foreignKey'] ?? $this->getForeignKey()) === false;
        $negateMatch = !empty($options['negateMatch']);
        $matching = !empty($options['matching']);

        $lookup = $builder->lookup($this->getTarget()->getCollection())->alias($lookupAlias);

        if ($disableForeignKey) {
            $lookup->pipeline(function (AggregationBuilder $sub) use ($pipelineOptions): void {
                $this->applyLookupSubPipeline($sub, $pipelineOptions, true);
            });
        } else {
            $foreignKey = $options['foreignKey'] ?? $this->getForeignKey();
            $this->assertJoinKeyCounts($foreignKey, $this->getBindingKey());
            $usedPipeline = $this->attachLookupKeys(
                $lookup,
                $this->prefixLookupFields($this->fieldNames($foreignKey), $options),
                $this->fieldNames($this->getBindingKey()),
                $pipelineOptions,
                true,
            );

            if (!$usedPipeline) {
                if ($negateMatch && !empty($pipelineOptions['conditions'])) {
                    $lookup->pipeline(function (AggregationBuilder $sub) use ($pipelineOptions): void {
                        $sub->match($this->normalizePipelineConditions($pipelineOptions['conditions']));
                    });
                } elseif (!empty($options['targetPipeline'])) {
                    $lookup->pipeline($options['targetPipeline']);
                } elseif (!$matching && $this->needsLookupTargetSubPipeline($pipelineOptions)) {
                    $lookup->pipeline(function (AggregationBuilder $sub) use ($pipelineOptions): void {
                        $this->applyLookupSubPipeline($sub, $pipelineOptions, true);
                    });
                }
            }
        }

        $builder->unwind('$' . $lookupAlias, [
            'preserveNullAndEmptyArrays' => $this->unwindPreservesNull($options),
        ]);
        $this->nestContainedLookup($builder, $property, $options, $lookupAlias);

        if ($negateMatch && empty($options['deferNegateMatch'])) {
            $builder->match([$property => null]);
        } elseif ($matching) {
            $postOptions = $pipelineOptions;
            if (!empty($pipelineOptions['conditions'])) {
                $postOptions['conditions'] = $this->prefixMatchConditions($pipelineOptions['conditions'], $property);
            }

            $postOptions['matching'] = true;
            $this->applyPipelineOptions($builder, $postOptions);
        }

        return $builder->getPipeline();
    }

    /**
     * Whether target-side containment options belong inside the `$lookup` pipeline.
     *
     * @param array<string, mixed> $options Pipeline options.
     * @return bool
     */
    protected function needsLookupTargetSubPipeline(array $options): bool
    {
        return !empty($options['conditions'])
            || !empty($options['fields'])
            || !empty($options['sort'])
            || !empty($options['skip'])
            || !empty($options['limit']);
    }
}
