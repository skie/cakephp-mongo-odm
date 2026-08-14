<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseMigration;

class CreateInfo extends BaseMigration
{
    public function change(): void
    {
        $this->collection('info')
            ->addField('title', 'string')
            ->addIndex(['title'])
            ->create();
    }
}
