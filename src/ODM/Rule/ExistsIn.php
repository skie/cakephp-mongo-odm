<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Rule;

use Cake\Datasource\EntityInterface;
use Cake\Datasource\RepositoryInterface;
use InvalidArgumentException;

/**
 * Checks that referenced MongoDB documents exist.
 *
 * @see cake60/src/ORM/Rule/ExistsIn.php
 */
class ExistsIn
{
    /**
     * Local fields whose values must exist in the target repository.
     *
     * @var array<string>
     */
    protected array $fields;

    /**
     * The target repository or alias.
     *
     * @var \Cake\Datasource\RepositoryInterface|string
     */
    protected RepositoryInterface|string $repository;

    /**
     * Existence rule options.
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
     * @param \Cake\Datasource\RepositoryInterface|string $repository The target repository or alias.
     * @param array<string, mixed> $options Rule options.
     *     Options 'allowNullableNulls' will make the rule pass if given foreign keys are set to `null`.
     */
    public function __construct(array|string $fields, RepositoryInterface|string $repository, array $options = [])
    {
        $this->fields = (array)$fields;
        $this->repository = $repository;
        $this->options = $options + ['allowNullableNulls' => false];
    }

    /**
     * Performs the existence check.
     *
     * @param \Cake\Datasource\EntityInterface $entity The document being checked.
     * @param array<string, mixed> $options Options passed by the rules checker.
     * @return bool
     * @throws \InvalidArgumentException If the repository cannot be resolved.
     */
    public function __invoke(EntityInterface $entity, array $options): bool
    {
        if (!$entity->extract($this->fields, true)) {
            return true;
        }

        $fields = $this->fields;
        $source = $this->repository;
        if (is_string($source)) {
            $source = $options['repository'] ?? null;
        }

        if (!$source instanceof RepositoryInterface) {
            throw new InvalidArgumentException('The `repository` option must resolve to a repository instance.');
        }

        $targetFields = $this->options['targetFields'] ?? array_fill(0, count($this->fields), '_id');

        if ($this->fieldsAreNull($entity, $source)) {
            return true;
        }

        if ($this->options['allowNullableNulls'] && method_exists($source, 'getSchema')) {
            $schema = $source->getSchema();
            foreach ($fields as $i => $field) {
                if ($schema->hasColumn($field) && $schema->isNullable($field) && $entity->get($field) === null) {
                    unset($targetFields[$i], $fields[$i]);
                }
            }
        }

        $conditions = [];
        foreach (array_values($fields) as $index => $field) {
            $conditions[$targetFields[$index] ?? '_id'] = $entity->get($field);
        }

        return $source->exists($conditions);
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
        if (!method_exists($source, 'getSchema')) {
            return false;
        }

        $nulls = 0;
        $schema = $source->getSchema();
        foreach ($this->fields as $field) {
            if ($schema->hasColumn($field) && $schema->isNullable($field) && $entity->get($field) === null) {
                $nulls++;
            }
        }

        return $nulls === count($this->fields);
    }
}
