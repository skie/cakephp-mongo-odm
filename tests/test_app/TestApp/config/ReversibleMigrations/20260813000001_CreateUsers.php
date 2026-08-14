<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseMigration;

class CreateUsers extends BaseMigration
{
    public function change(): void
    {
        $this->collection('users')
            ->addField('name', 'string')
            ->addField('email', 'string')
            ->create();
    }
}
