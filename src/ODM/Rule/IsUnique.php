<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Rule;

use Cake\Datasource\EntityInterface;
use Cake\Datasource\RepositoryInterface;
use InvalidArgumentException;

/**
 * Checks that a list of document fields is unique in a repository.
 *
 * MongoDB updates exclude the current document by `_id`.
 *
 * @see cake60/src/ORM/Rule/IsUnique.php
 * @see src/ODM/Rule/IsUnique.php
 */
final class IsUnique
{
    /**
     * Fields participating in the uniqueness condition.
     *
     * @var array<string>
     */
    private array $fields;

    /**
     * Uniqueness rule options.
     *
     * @var array<string, mixed>
     */
    private array $options;

    /**
     * Constructor.
     *
     * @param array<string> $fields The fields to check.
     * @param array<string, mixed> $options Rule options.
     */
    public function __construct(array $fields, array $options = [])
    {
        $this->fields = $fields;
        $this->options = $options + ['allowMultipleNulls' => true];
    }

    /**
     * Performs the uniqueness check.
     *
     * @param \Cake\Datasource\EntityInterface $entity The document being checked.
     * @param array<string, mixed> $options Options passed by the rules checker.
     * @return bool
     * @throws \InvalidArgumentException If no repository is supplied.
     */
    public function __invoke(EntityInterface $entity, array $options): bool
    {
        $values = $entity->extract($this->fields);
        if (!$entity->extract($this->fields, true)) {
            return true;
        }
        if ($this->options['allowMultipleNulls'] && array_any($values, static fn(mixed $value): bool => $value === null)) {
            return true;
        }

        $conditions = $values;
        if (!$entity->isNew() && $entity->get('_id') !== null) {
            $conditions['_id'] = ['$ne' => $entity->get('_id')];
        }

        $repository = $options['repository'] ?? null;
        if (!$repository instanceof RepositoryInterface) {
            throw new InvalidArgumentException('The `repository` option must be a repository instance.');
        }

        return !$repository->exists($conditions);
    }
}
