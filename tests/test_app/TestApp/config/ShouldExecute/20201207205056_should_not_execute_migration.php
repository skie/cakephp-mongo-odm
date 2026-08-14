<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseMigration;

class ShouldNotExecuteMigration extends BaseMigration
{
    public function shouldExecute(): bool
    {
        return false;
    }

    public function change(): void
    {
        $this->collection('should_execute_info')->addField('name', 'string')->create();
    }
}
