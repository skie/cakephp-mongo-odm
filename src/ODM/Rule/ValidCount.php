<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Rule;

use Cake\Datasource\EntityInterface;
use Cake\Validation\Validation;
use Countable;

/**
 * Validates the size of an embedded array or countable value.
 *
 * @see cake60/src/ORM/Rule/ValidCount.php
 */
final class ValidCount
{
    /**
     * Constructor.
     *
     * @param string $field The field to check.
     */
    public function __construct(private string $field)
    {
    }

    /**
     * Performs the count check.
     *
     * @param \Cake\Datasource\EntityInterface $entity The document being checked.
     * @param array<string, mixed> $options Options containing `operator` and `count`.
     * @return bool
     */
    public function __invoke(EntityInterface $entity, array $options): bool
    {
        $value = $entity->get($this->field);
        if (!is_array($value) && !$value instanceof Countable) {
            return false;
        }

        return Validation::comparison(count($value), $options['operator'], $options['count']);
    }
}
