<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use Cake\Datasource\RepositoryInterface;
use Cake\Datasource\RuleInvoker;
use Cake\Datasource\RulesChecker as BaseRulesChecker;
use Cake\Utility\Inflector;
use Crustum\Mongo\ODM\Rule\ExistsIn;
use Crustum\Mongo\ODM\Rule\ExistsInNullable;
use Crustum\Mongo\ODM\Rule\IsUnique;
use Crustum\Mongo\ODM\Rule\LinkConstraint;
use Crustum\Mongo\ODM\Rule\ValidCount;

/**
 * MongoDB rules checker.
 *
 * Adds MongoDB-specific integrity rules to the datasource rules checker.
 *
 * @inspired-by \Cake\ORM\RulesChecker
 */
class RulesChecker extends BaseRulesChecker
{
    /**
     * Whether default error messages should be translated.
     *
     * @var bool
     */
    protected bool $useI18n = false;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $options Rules checker options.
     */
    public function __construct(array $options = [])
    {
        parent::__construct($options);
        $this->useI18n = function_exists('\Cake\I18n\__d');
    }

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
            'isUnique',
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
            'existsIn',
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
            'validCount',
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

    /**
     * Validates whether links to the given association exist.
     *
     * @param \Crustum\Mongo\ODM\Association|string $association The association to check for links.
     * @param string|null $field The name of the association property. When supplied, this is the name used to set
     *   possible errors. When absent, the name is inferred from `$association`.
     * @param string|null $message The error message to show in case the rule does not pass.
     * @return \Cake\Datasource\RuleInvoker
     */
    public function isLinkedTo(
        Association|string $association,
        ?string $field = null,
        ?string $message = null,
    ): RuleInvoker {
        return $this->addLinkConstraintRule(
            $association,
            $field,
            $message,
            LinkConstraint::STATUS_LINKED,
            'isLinkedTo',
        );
    }

    /**
     * Validates whether links to the given association do not exist.
     *
     * @param \Crustum\Mongo\ODM\Association|string $association The association to check for links.
     * @param string|null $field The name of the association property. When supplied, this is the name used to set
     *   possible errors. When absent, the name is inferred from `$association`.
     * @param string|null $message The error message to show in case the rule does not pass.
     * @return \Cake\Datasource\RuleInvoker
     */
    public function isNotLinkedTo(
        Association|string $association,
        ?string $field = null,
        ?string $message = null,
    ): RuleInvoker {
        return $this->addLinkConstraintRule(
            $association,
            $field,
            $message,
            LinkConstraint::STATUS_NOT_LINKED,
            'isNotLinkedTo',
        );
    }

    /**
     * Adds a link constraint rule.
     *
     * @param \Crustum\Mongo\ODM\Association|string $association The association to check for links.
     * @param string|null $errorField The name of the property to use for setting possible errors. When absent,
     *   the name is inferred from `$association`.
     * @param string|null $message The error message to show in case the rule does not pass.
     * @param string $linkStatus The link status required for the check to pass.
     * @param string $ruleName The alias/name of the rule.
     * @return \Cake\Datasource\RuleInvoker
     * @throws \InvalidArgumentException In case the `$association` argument is of an invalid type.
     */
    protected function addLinkConstraintRule(
        Association|string $association,
        ?string $errorField,
        ?string $message,
        string $linkStatus,
        string $ruleName,
    ): RuleInvoker {
        if ($association instanceof Association) {
            $associationAlias = $association->getName();
            $errorField ??= $association->getProperty();
        } else {
            $associationAlias = $association;

            if ($errorField === null) {
                $repository = $this->_options['repository'] ?? null;
                if ($repository instanceof BaseCollection) {
                    $association = $repository->getAssociation($association);
                    $errorField = $association->getProperty();
                } else {
                    $errorField = Inflector::underscore($association);
                }
            }
        }

        $message ??= $this->useI18n
            ? __d(
                'cake',
                'Cannot modify row: a constraint for the `{0}` association fails.',
                $associationAlias,
            )
            : sprintf(
                'Cannot modify row: a constraint for the `%s` association fails.',
                $associationAlias,
            );

        $rule = new LinkConstraint(
            $association,
            $linkStatus,
        );

        return $this->_addError($rule, $ruleName, ['errorField' => $errorField, 'message' => $message]);
    }
}
