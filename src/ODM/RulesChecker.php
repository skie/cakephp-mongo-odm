<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use Cake\Datasource\RepositoryInterface;
use Cake\Datasource\RuleInvoker;
use Cake\Datasource\RulesChecker as BaseRulesChecker;
use Crustum\Mongo\ODM\Rule\ExistsIn;
use Crustum\Mongo\ODM\Rule\ExistsInNullable;
use Crustum\Mongo\ODM\Rule\IsUnique;
use Crustum\Mongo\ODM\Rule\ValidCount;

/**
 * MongoDB rules checker.
 *
 * Adds MongoDB-specific integrity rules to the datasource rules checker.
 *
 * @see cake60/src/ORM/RulesChecker.php
 */
final class RulesChecker extends BaseRulesChecker
{
    /**
     * Returns a rule for checking field uniqueness.
     *
     * @param array<string> $fields The fields to check.
     * @param array<string, mixed>|string|null $message The error message or rule options.
     * @return \Cake\Datasource\RuleInvoker
     */
    public function isUnique(array $fields, array|string|null $message = null): RuleInvoker
    {
        [$message, $options] = $this->ruleOptions($message, 'This value is already in use');

        return $this->_addError(
            new IsUnique($fields, $options),
            '_isUnique',
            ['errorField' => $fields[0] ?? null, 'message' => $message],
        );
    }

    /**
     * Returns a rule for checking referenced document existence.
     *
     * @param array<string>|string $field The local field or fields to check.
     * @param \Cake\Datasource\RepositoryInterface|string $repository The target repository or alias.
     * @param array<string, mixed>|string|null $message The error message or rule options.
     * @return \Cake\Datasource\RuleInvoker
     */
    public function existsIn(
        array|string $field,
        RepositoryInterface|string $repository,
        array|string|null $message = null,
    ): RuleInvoker {
        [$message, $options] = $this->ruleOptions($message, 'This value does not exist');

        return $this->_addError(
            new ExistsIn($field, $repository, $options),
            '_existsIn',
            ['errorField' => is_string($field) ? $field : ($field[0] ?? null), 'message' => $message],
        );
    }

    /**
     * Returns an ExistsIn rule accepting nullable foreign keys.
     *
     * @param array<string>|string $field The local field or fields to check.
     * @param \Cake\Datasource\RepositoryInterface|string $repository The target repository or alias.
     * @param array<string, mixed>|string|null $message The error message or rule options.
     * @return \Cake\Datasource\RuleInvoker
     */
    public function existsInNullable(
        array|string $field,
        RepositoryInterface|string $repository,
        array|string|null $message = null,
    ): RuleInvoker {
        [$message, $options] = $this->ruleOptions($message, 'This value does not exist');

        return $this->_addError(
            new ExistsInNullable($field, $repository, $options),
            '_existsIn',
            ['errorField' => is_string($field) ? $field : ($field[0] ?? null), 'message' => $message],
        );
    }

    /**
     * Returns a rule for checking the size of an embedded list.
     *
     * @param string $field The field containing the embedded list.
     * @param int $count The expected count.
     * @param string $operator The comparison operator.
     * @param string|null $message The error message.
     * @return \Cake\Datasource\RuleInvoker
     */
    public function validCount(
        string $field,
        int $count = 0,
        string $operator = '>',
        ?string $message = null,
    ): RuleInvoker {
        $message ??= sprintf('The count does not match %s%d', $operator, $count);

        return $this->_addError(
            new ValidCount($field),
            '_validCount',
            ['count' => $count, 'operator' => $operator, 'message' => $message] + ['errorField' => $field],
        );
    }

    /**
     * Normalizes rule message and options.
     *
     * @param array<string, mixed>|string|null $message The error message or rule options.
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function ruleOptions(array|string|null $message, string $default): array
    {
        $options = is_array($message) ? $message : ['message' => $message];
        $message = $options['message'] ?? null;
        unset($options['message']);

        return [$message ?: $default, $options];
    }
}
