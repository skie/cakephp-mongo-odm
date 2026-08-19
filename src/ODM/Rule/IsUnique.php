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
class IsUnique
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
     * @param \Cake\Datasource\EntityInterface $document The document being checked.
     * @param array<string, mixed> $options Options passed by the rules checker.
     * @return bool
     * @throws \InvalidArgumentException If no repository is supplied.
     */
    public function __invoke(EntityInterface $document, array $options): bool
    {
        if (!$document->extract($this->fields, true)) {
            return true;
        }

        $fields = $document->extract($this->fields);
        if ($this->options['allowMultipleNulls'] && array_any($fields, static fn(mixed $value): bool => $value === null)) {
            return true;
        }

        $repository = $options['repository'] ?? null;
        if (!$repository instanceof RepositoryInterface) {
            throw new InvalidArgumentException('The `repository` option must be a repository instance.');
        }

        $conditions = $fields;
        if (!$document->isNew()) {
            if (method_exists($repository, 'getPrimaryKey')) {
                $keys = (array)$repository->getPrimaryKey();
                $keys = $document->extract($keys);
            } else {
                $keys = $document->extract(['_id']);
            }

            if (array_filter($keys, static fn(mixed $value): bool => $value !== null)) {
                $conditions['_id !='] = $keys['_id'] ?? null;
            }
        }

        return !$repository->exists($conditions);
    }
}
