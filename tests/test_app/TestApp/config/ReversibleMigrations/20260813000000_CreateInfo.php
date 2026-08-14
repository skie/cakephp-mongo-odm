<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseMigration;

class CreateInfo extends BaseMigration
{
    public function change(): void
    {
        $this->collection('mig_info')
            ->addField('title', 'string')
            ->addIndex(['title'])
            ->create();
    }
}
