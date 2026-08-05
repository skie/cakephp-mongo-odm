<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use Cake\Event\Event;
use Cake\Event\EventInterface;
use Cake\Validation\Validator;
use Crustum\Mongo\ODM\Association;
use Crustum\Mongo\ODM\BaseCollection;

final class MarshallerCollection extends BaseCollection
{
    /**
     * @var array<int, string>
     */
    public array $events = [];

    public ?Validator $validator = null;

    public ?Association $association = null;

    public function getValidator(?string $name = null): Validator
    {
        return $this->validator ?? new Validator();
    }

    public function getAssociation(string $name): ?Association
    {
        return $this->association;
    }

    public function dispatchEvent(string $name, array $data = [], ?object $subject = null): EventInterface
    {
        $this->events[] = $name;

        return new Event($name, $subject ?? $this, $data);
    }
}
