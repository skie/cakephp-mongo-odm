<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseMigration;

return new class extends BaseMigration
{
    public function change(): void
    {
        $this->collection('mig_anonymous')
            ->addField('name', 'string')
            ->create();
    }
};
