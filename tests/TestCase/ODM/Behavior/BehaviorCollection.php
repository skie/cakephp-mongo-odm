<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Behavior;

use Crustum\Mongo\ODM\BaseCollection;

final class BehaviorCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->setSchemaFromArray([
            'created' => ['type' => 'datetime'],
            'modified' => ['type' => 'datetime'],
        ]);
    }
}
