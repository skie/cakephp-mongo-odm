<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventDispatcherInterface;

/**
 * Provides rules checking for ODM collections.
 *
 * Migrated from Cake core `Cake\Datasource\RulesAwareTrait`; the model events
 * use the `Collection.*` prefix instead of `Model.*`.
 *
 * @see cake60/src/Datasource/RulesAwareTrait.php
 */
trait RulesAwareTrait
{
    /**
     * The domain rules to be applied to documents saved by this collection.
     *
     * @var \Crustum\Mongo\ODM\RulesChecker|null
     */
    protected ?RulesChecker $rulesChecker = null;

    /**
     * Returns whether the passed document complies with all the rules stored in
     * the rules checker.
     *
     * @param \Cake\Datasource\EntityInterface $document The document to check for validity.
     * @param string $operation The operation being run. Either 'create', 'update' or 'delete'.
     * @param \ArrayObject<string, mixed>|array<string, mixed>|null $options The options to be passed to the rules.
     * @return bool
     */
    public function checkRules(
        EntityInterface $document,
        string $operation = RulesChecker::CREATE,
        ArrayObject|array|null $options = null,
    ): bool {
        $rules = $this->rulesChecker();
        $options = is_array($options) ? new ArrayObject($options) : ($options ?: new ArrayObject());
        $hasEvents = ($this instanceof EventDispatcherInterface);
        if ($hasEvents) {
            $event = $this->dispatchEvent('Collection.beforeRules', ['document' => $document, 'options' => $options, 'operation' => $operation]);
            if ($event->isStopped()) {
                return (bool)$event->getResult();
            }
        }

        $result = $rules->check($document, $operation, $options->getArrayCopy());

        if ($hasEvents) {
            $event = $this->dispatchEvent('Collection.afterRules', ['document' => $document, 'options' => $options, 'result' => $result, 'operation' => $operation]);
            if ($event->isStopped()) {
                return (bool)$event->getResult();
            }
        }

        return $result;
    }

    /**
     * Returns the RulesChecker for this instance.
     *
     * @see \Crustum\Mongo\ODM\RulesChecker
     * @return \Crustum\Mongo\ODM\RulesChecker
     */
    public function rulesChecker(): RulesChecker
    {
        if ($this->rulesChecker !== null) {
            return $this->rulesChecker;
        }

        /** @var class-string<\Crustum\Mongo\ODM\RulesChecker> $class */
        $class = defined('static::RULES_CLASS') ? static::RULES_CLASS : RulesChecker::class;
        $this->rulesChecker = $this->buildRules(new $class(['repository' => $this]));
        $this->dispatchEvent('Collection.buildRules', ['rules' => $this->rulesChecker]);

        return $this->rulesChecker;
    }

    /**
     * Returns a RulesChecker object after modifying the one that was supplied.
     *
     * @param \Crustum\Mongo\ODM\RulesChecker $rules The rules object to be modified.
     * @return \Crustum\Mongo\ODM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        return $rules;
    }
}
