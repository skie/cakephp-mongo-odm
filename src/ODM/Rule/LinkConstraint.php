<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Rule;

use Cake\Database\Exception\DatabaseException;
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
     * Aliases fields on a collection.
     *
     * Cake parity helper used when building counting conditions so composite
     * primary keys and foreign keys resolve to the correct collection prefix.
     *
     * @param list<string> $fields The fields that should be aliased.
     * @param \Crustum\Mongo\ODM\BaseCollection $collection The collection to use for aliasing.
     * @return list<string> The aliased fields.
     * @see cake60/src/ORM/Rule/LinkConstraint.php::_aliasFields()
     */
    protected function aliasFields(array $fields, BaseCollection $collection): array
    {
        foreach ($fields as $key => $value) {
            $fields[$key] = $collection->aliasField($value);
        }

        return $fields;
    }

    /**
     * Builds a conditions array from parallel field and value lists.
     *
     * Validates that composite key tuples have the same number of fields and
     * values before combining them (cake parity).
     *
     * @param list<string> $fields The condition fields.
     * @param list<mixed> $values The condition values.
     * @return array<string, mixed> A conditions array combined from the passed fields and values.
     * @see cake60/src/ORM/Rule/LinkConstraint.php::_buildConditions()
     */
    protected function buildConditions(array $fields, array $values): array
    {
        if (count($fields) !== count($values)) {
            throw new InvalidArgumentException(sprintf(
                'The number of fields is expected to match the number of values, got %d field(s) and %d value(s).',
                count($fields),
                count($values),
            ));
        }

        return array_combine($fields, $values);
    }

    /**
     * Ensures the source document exposes all primary key parts.
     *
     * Composite primary keys must be fully present on the entity before a link
     * count query is built; otherwise a {@see DatabaseException} is raised with
     * the expected and extracted key parts (cake parity).
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $source The source collection.
     * @param \Cake\Datasource\EntityInterface $document The entity involved in the operation.
     * @return void
     * @throws \Cake\Database\Exception\DatabaseException When a primary key part is missing.
     * @see cake60/src/ORM/Rule/LinkConstraint.php::_countLinks()
     */
    protected function assertPrimaryKeyValues(BaseCollection $source, EntityInterface $document): void
    {
        $primaryKey = (array)$source->getPrimaryKey();

        if (!$document->has($primaryKey)) {
            throw new DatabaseException(sprintf(
                'LinkConstraint rule on `%s` requires all primary key values for building the counting ' .
                'conditions, expected values for `(%s)`, got `(%s)`.',
                $source->getAlias(),
                implode(', ', $primaryKey),
                implode(', ', $document->extract($primaryKey)),
            ));
        }
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
     * `belongsToMany` uses a source `matching()` query keyed by the source
     * primary key (cake parity). Other association types count on the
     * association query so configured conditions and finders are honoured —
     * for example a `hasOne` whose conditions reference parent columns only
     * counts targets that satisfy those filters.
     *
     * Composite keys are supported: source/target key lists are aliased and
     * combined through {@see buildConditions()}. Missing source primary key
     * parts raise {@see DatabaseException} via {@see assertPrimaryKeyValues()}.
     *
     * @param \Crustum\Mongo\ODM\Association $association The association for which to count links.
     * @param \Cake\Datasource\EntityInterface $document The entity involved in the operation.
     * @return int The number of links.
     * @see cake60/src/ORM/Rule/LinkConstraint.php::_countLinks()
     */
    protected function countLinks(Association $association, EntityInterface $document): int
    {
        $source = $association->getSource();
        $this->assertPrimaryKeyValues($source, $document);

        if ($association instanceof BelongsToMany) {
            $primaryKey = (array)$source->getPrimaryKey();
            $aliasedPrimaryKey = $this->aliasFields($primaryKey, $source);
            $conditions = $this->buildConditions(
                $aliasedPrimaryKey,
                $document->extract($primaryKey),
            );

            return $source
                ->find()
                ->matching($association->getName())
                ->where($conditions)
                ->count();
        }

        if ($association instanceof BelongsTo) {
            $sourceKeys = array_values(array_filter((array)$association->getForeignKey(), is_string(...)));
            $targetKeys = (array)$association->getBindingKey();
            $target = $association->getTarget();
        } else {
            $sourceKeys = (array)$association->getBindingKey();
            $targetKeys = array_values(array_filter((array)$association->getForeignKey(), is_string(...)));
            $target = $association->getTarget();
        }

        $sourceValues = $document->extract($sourceKeys);
        if (count(array_filter($sourceValues, static fn(mixed $value): bool => $value !== null)) !== count($sourceKeys)) {
            return 0;
        }

        $aliasedTargetKeys = $this->aliasFields($targetKeys, $target);
        $conditions = $this->buildConditions($aliasedTargetKeys, $sourceValues);

        return $association->find()->where($conditions)->count();
    }
}
