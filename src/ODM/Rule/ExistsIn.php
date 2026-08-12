<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Rule;

use Cake\Database\Exception\DatabaseException;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\RepositoryInterface;
use Crustum\Mongo\ODM\Association;

/**
 * Checks that referenced MongoDB documents exist.
 *
 * @see cake60/src/ORM/Rule/ExistsIn.php
 */
class ExistsIn
{
    /**
     * The list of fields to check.
     *
     * @var array<string>
     */
    protected array $fields;

    /**
     * The repository or association where the field will be looked for.
     *
     * @var \Cake\Datasource\RepositoryInterface|\Crustum\Mongo\ODM\Association|string
     */
    protected RepositoryInterface|Association|string $repository;

    /**
     * Options for the rule.
     *
     * @var array<string, mixed>
     */
    protected array $options;

    /**
     * Constructor.
     *
     * Available option for $options is 'allowNullableNulls' flag.
     * Set to true to accept composite foreign keys where one or more nullable columns are null.
     *
     * @param array<string>|string $fields The local fields to check.
     * @param \Cake\Datasource\RepositoryInterface|\Crustum\Mongo\ODM\Association|string $repository The target repository,
     *   association, or alias.
     * @param array<string, mixed> $options The options that modify the rule's behavior.
     *     Options 'allowNullableNulls' will make the rule pass if given foreign keys are set to `null`.
     *     Notice: allowNullableNulls cannot pass by database columns set to `NOT NULL`.
     */
    public function __construct(
        array|string $fields,
        RepositoryInterface|Association|string $repository,
        array $options = [],
    ) {
        $this->options = $options + ['allowNullableNulls' => false];
        $this->fields = (array)$fields;
        $this->repository = $repository;
    }

    /**
     * Performs the existence check.
     *
     * @param \Cake\Datasource\EntityInterface $entity The document from where to extract the fields.
     * @param array<string, mixed> $options Options passed to the check, where the `repository` key is required.
     * @return bool
     * @throws \Cake\Database\Exception\DatabaseException When the rule refers to an undefined association.
     */
    public function __invoke(EntityInterface $entity, array $options): bool
    {
        if (is_string($this->repository)) {
            /** @var \Crustum\Mongo\ODM\BaseCollection $source */
            $source = $options['repository'];

            if (!$source->hasAssociation($this->repository)) {
                throw new DatabaseException(sprintf(
                    'ExistsIn rule for `%s` is invalid. `%s` is not associated with `%s`.',
                    implode(', ', $this->fields),
                    $this->repository,
                    $options['repository']::class,
                ));
            }

            $this->repository = $source->getAssociation($this->repository);
        }

        $fields = $this->fields;
        $target = $this->repository;
        if ($target instanceof Association) {
            $bindingKey = (array)$target->getBindingKey();
            $realTarget = $target->getTarget();
        } else {
            $bindingKey = method_exists($target, 'getPrimaryKey')
                ? (array)$target->getPrimaryKey()
                : array_fill(0, count($fields), '_id');
            $realTarget = $target;
        }

        if (!empty($options['_sourceTable']) && $realTarget === $options['_sourceTable']) {
            return true;
        }

        if (!empty($options['repository'])) {
            /** @var \Crustum\Mongo\ODM\BaseCollection $source */
            $source = $options['repository'];
        } else {
            $source = $this->repository;
        }

        if ($source instanceof Association) {
            $source = $source->getSource();
        }

        if (!$entity->extract($this->fields, true)) {
            return true;
        }

        if ($this->fieldsAreNull($entity, $source)) {
            return true;
        }

        if ($this->options['allowNullableNulls'] && method_exists($source, 'describeSchema')) {
            $schema = $source->describeSchema();
            foreach ($fields as $i => $field) {
                if ($schema->hasColumn($field) && $schema->isNullable($field) && $entity->get($field) === null) {
                    unset($bindingKey[$i], $fields[$i]);
                }
            }
        }

        $primary = array_map(
            fn(string $key): string => method_exists($target, 'aliasField')
                ? $target->aliasField($key) . ' IS'
                : $key . ' IS',
            $bindingKey,
        );
        $conditions = array_combine(
            $primary,
            $entity->extract($fields),
        );

        return $target->exists($conditions);
    }

    /**
     * Checks whether the given document fields are nullable and null.
     *
     * @param \Cake\Datasource\EntityInterface $entity The document to check.
     * @param \Cake\Datasource\RepositoryInterface $source The repository to use schema from.
     * @return bool
     */
    protected function fieldsAreNull(EntityInterface $entity, RepositoryInterface $source): bool
    {
        if (!method_exists($source, 'describeSchema')) {
            return false;
        }

        $nulls = 0;
        $schema = $source->describeSchema();
        foreach ($this->fields as $field) {
            if ($schema->hasColumn($field) && $schema->isNullable($field) && $entity->get($field) === null) {
                $nulls++;
            }
        }

        return $nulls === count($this->fields);
    }
}
