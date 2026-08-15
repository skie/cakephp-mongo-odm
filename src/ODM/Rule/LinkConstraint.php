<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Rule;

use Cake\Datasource\EntityInterface;
use Crustum\Mongo\ODM\Association;
use Crustum\Mongo\ODM\Association\BelongsTo;
use Crustum\Mongo\ODM\Association\BelongsToMany;
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
                'Argument 2 is expected to match one of the `' . LinkConstraint::class . '::STATUS_*` constants.',
            );
        }

        $this->requiredLinkState = $requiredLinkStatus;
    }

    /**
     * Callable handler.
     *
     * Performs the actual link check.
     *
     * @param \Cake\Datasource\EntityInterface $document The entity involved in the operation.
     * @param array<string, mixed> $options Options passed from the rules checker.
     * @return bool Whether the check was successful.
     */
    public function __invoke(EntityInterface $document, array $options): bool
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

        $count = $this->countLinks($association, $document);

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
     * The number of related target documents is counted on the owning side of
     * the relationship:
     *
     * - `belongsTo`: targets whose binding key equals the source document's
     *   foreign key values.
     * - `hasOne`/`hasMany`: targets whose foreign key equals the source
     *   document's binding key values.
     * - `belongsToMany`: junction links resolved through the join collection.
     *
     * @param \Crustum\Mongo\ODM\Association $association The association for which to count links.
     * @param \Cake\Datasource\EntityInterface $document The entity involved in the operation.
     * @return int The number of links.
     */
    protected function countLinks(Association $association, EntityInterface $document): int
    {
        if ($association instanceof BelongsToMany) {
            $source = $association->getSource();
            $primaryKey = (array)$source->getPrimaryKey();

            if (count(array_filter($document->extract($primaryKey), static fn(mixed $value): bool => $value !== null)) !== count($primaryKey)) {
                return 0;
            }

            $conditions = array_combine($primaryKey, $document->extract($primaryKey));

            return $source
                ->find()
                ->matching($association->getName())
                ->where($conditions)
                ->count();
        }

        if ($association instanceof BelongsTo) {
            $sourceKeys = array_values(array_filter((array)$association->getForeignKey(), is_string(...)));
            $targetKeys = (array)$association->getBindingKey();
        } else {
            $sourceKeys = (array)$association->getBindingKey();
            $targetKeys = array_values(array_filter((array)$association->getForeignKey(), is_string(...)));
        }

        $sourceValues = $document->extract($sourceKeys);
        if (count(array_filter($sourceValues, static fn(mixed $value): bool => $value !== null)) !== count($sourceKeys)) {
            return 0;
        }

        $conditions = array_combine($targetKeys, $sourceValues);

        return $association->find()->where($conditions)->count();
    }
}
