<?php
declare(strict_types=1);

namespace TestApp\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;

class ValidateUsersCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->setSchemaFromArray([
            'id' => ['type' => 'integer', 'null' => false, 'default' => '', 'length' => 8],
            'name' => ['type' => 'string', 'null' => true, 'default' => '', 'length' => 255],
            'email' => ['type' => 'string', 'null' => true, 'default' => '', 'length' => 255],
            'balance' => ['type' => 'float', 'null' => false, 'length' => 5],
            'cost_decimal' => ['type' => 'decimal128', 'null' => false, 'length' => 6],
            'null_decimal' => ['type' => 'decimal128', 'null' => false, 'length' => null],
            'ratio' => ['type' => 'decimal128', 'null' => false, 'length' => 10],
            'population' => ['type' => 'decimal128', 'null' => false, 'length' => 15],
            'created' => ['type' => 'date', 'null' => true, 'default' => ''],
            'updated' => ['type' => 'timestamp', 'null' => true, 'default' => ''],
        ]);
    }
}
