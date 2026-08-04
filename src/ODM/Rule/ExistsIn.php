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
     * @param array<string>|string $fields The local fields to check.
     * @param \Cake\Datasource\RepositoryInterface|string $repository The target repository or alias.
     * @param array<string, mixed> $options Rule options.
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

        $hasNull = array_any($this->fields, static fn(string $field): bool => $entity->get($field) === null);
        if ($this->options['allowNullableNulls'] && $hasNull) {
            return true;
        }

        $repository = $this->repository;
        if (is_string($repository)) {
            $repository = $options['repository'] ?? null;
        }

        if (!$repository instanceof RepositoryInterface) {
            throw new InvalidArgumentException('The `repository` option must resolve to a repository instance.');
        }

        $targetFields = $this->options['targetFields'] ?? array_fill(0, count($this->fields), '_id');
        $conditions = [];
        foreach (array_values($this->fields) as $index => $field) {
            $conditions[$targetFields[$index] ?? '_id'] = $entity->get($field);
        }

        return $repository->exists($conditions);
    }
}
