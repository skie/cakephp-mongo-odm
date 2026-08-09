<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Rule;

use Cake\Datasource\EntityInterface;
use Crustum\Mongo\ODM\Association;
use Crustum\Mongo\ODM\Association\BelongsTo;
use Crustum\Mongo\ODM\Association\BelongsToMany;
use Crustum\Mongo\ODM\Association\HasMany;
use Crustum\Mongo\ODM\Association\HasOne;
use Crustum\Mongo\ODM\BaseCollection;
use InvalidArgumentException;

/**
 * Checks whether links to a given association exist / do not exist.
 *
 * @see cake60/src/ORM/Rule/LinkConstraint.php
 */
class LinkConstraint
{
    public const string STATUS_LINKED = 'linked';
    public const string STATUS_NOT_LINKED = 'notLinked';

    /**
     * The link status that is required to be present in order for the check to succeed.
     *
     * @var string
     */
    protected string $requiredLinkState;

    /**
     * Constructor.
     *
     * @param \Crustum\Mongo\ODM\Association|string $association The alias of the association that should be checked.
     * @param string $requiredLinkStatus The link status that is required to be present in order for the check to
     *  succeed.
     */
    public function __construct(protected Association|string $association, string $requiredLinkStatus)
    {
        if (!in_array($requiredLinkStatus, [static::STATUS_LINKED, static::STATUS_NOT_LINKED], true)) {
            throw new InvalidArgumentException(
                'Argument 2 is expected to match one of the `\Crustum\Mongo\ODM\Rule\LinkConstraint::STATUS_*` constants.',
            );
        }
        $this->requiredLinkState = $requiredLinkStatus;
    }

    /**
     * Callable handler.
     *
     * Performs the actual link check.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity involved in the operation.
     * @param array<string, mixed> $options Options passed from the rules checker.
     * @return bool Whether the check was successful.
     */
    public function __invoke(EntityInterface $entity, array $options): bool
    {
        $collection = $options['repository'] ?? null;
        if (!$collection instanceof BaseCollection) {
            throw new InvalidArgumentException(
                'Argument 2 is expected to have a `repository` key that holds an instance of `\Crustum\Mongo\ODM\BaseCollection`.',
            );
        }

        $association = $this->association;
        if (!$association instanceof Association) {
            $association = $collection->getAssociation($association);
        }

        $count = $this->countLinks($association, $entity, $collection);

        if (
            (
                $this->requiredLinkState === static::STATUS_LINKED &&
                $count < 1
            ) ||
            (
                $this->requiredLinkState === static::STATUS_NOT_LINKED &&
                $count !== 0
            )
        ) {
            return false;
        }

        return true;
    }

    /**
     * Count links.
     *
     * @param \Crustum\Mongo\ODM\Association $association The association for which to count links.
     * @param \Cake\Datasource\EntityInterface $entity The entity involved in the operation.
     * @param \Crustum\Mongo\ODM\BaseCollection $collection The source collection.
     * @return int The number of links.
     */
    protected function countLinks(Association $association, EntityInterface $entity, BaseCollection $collection): int
    {
        if ($association instanceof BelongsToMany) {
            return $this->countBelongsToManyLinks($association, $entity);
        }

        $source = $association->getSource();
        $target = $association->getTarget();

        $foreignKey = array_values(array_filter((array)$association->getForeignKey(), 'is_string'));
        $bindingKey = (array)$association->getBindingKey();

        if (!$entity->has($bindingKey)) {
            throw new InvalidArgumentException(sprintf(
                'LinkConstraint rule on `%s` requires all primary key values for building the counting conditions.',
                $source->getAlias(),
            ));
        }

        $conditions = array_combine($foreignKey, $entity->extract($bindingKey));

        return $target->find()->where($conditions)->count();
    }

    /**
     * Counts the junction links for a BelongsToMany association.
     *
     * @param \Crustum\Mongo\ODM\Association\BelongsToMany $association The association.
     * @param \Cake\Datasource\EntityInterface $entity The source entity.
     * @return int
     */
    protected function countBelongsToManyLinks(BelongsToMany $association, EntityInterface $entity): int
    {
        $source = $association->getSource();
        $junction = $association->junction();
        $target = $association->getTarget();

        $foreignKey = array_values(array_filter((array)$association->getForeignKey(), 'is_string'));
        $bindingKey = (array)$association->getBindingKey();
        $targetForeignKey = (array)$association->getTargetForeignKey();
        $targetBindingKey = (array)$association->getBindingKey();

        $sourceKeys = array_combine($foreignKey, $entity->extract($bindingKey));

        $belongsTo = $junction->getAssociation($target->getAlias());
        $assocForeignKey = (array)$belongsTo->getForeignKey();

        $links = $junction->find()
            ->where($sourceKeys)
            ->toArray();

        if ($links === []) {
            return 0;
        }

        $targetKeys = [];
        foreach ($links as $link) {
            $targetKeys[] = $link->extract($assocForeignKey);
        }

        $targetConditions = [];
        foreach ($targetKeys as $key) {
            $targetConditions[] = array_combine($targetForeignKey, array_values((array)$key));
        }

        return $target->find()->where(['OR' => $targetConditions])->count();
    }
}
