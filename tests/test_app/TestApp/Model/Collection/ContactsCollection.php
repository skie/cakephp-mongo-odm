<?php
declare(strict_types=1);

namespace TestApp\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;

class ContactsCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->setSchemaFromArray([
            'id' => ['type' => 'integer', 'null' => false, 'default' => '', 'length' => 8],
            'name' => ['type' => 'string', 'null' => true, 'default' => '', 'length' => 255],
            'email' => ['type' => 'string', 'null' => true, 'default' => '', 'length' => 255],
            'phone' => ['type' => 'string', 'null' => true, 'default' => '', 'length' => 255],
            'password' => ['type' => 'string', 'null' => true, 'default' => '', 'length' => 255],
            'published' => ['type' => 'date', 'null' => true, 'default' => null],
            'created' => ['type' => 'date', 'null' => true, 'default' => ''],
            'updated' => ['type' => 'timestamp', 'null' => true, 'default' => ''],
            'age' => ['type' => 'integer', 'null' => true, 'default' => ''],
        ]);
    }
}
