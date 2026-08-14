<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseMigration;

class ShouldExecuteMigration extends BaseMigration
{
    public function shouldExecute(): bool
    {
        return true;
    }

    public function change(): void
    {
        $this->collection('should_execute_info')->addField('name', 'string')->create();
    }
}
