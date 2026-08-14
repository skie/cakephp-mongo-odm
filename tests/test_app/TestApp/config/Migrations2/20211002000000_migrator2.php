<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseMigration;

class Migrator2 extends BaseMigration
{
    public function up(): void
    {
        $this->collection('mig_migrator2')->addField('name', 'string')->create();
        $this->getAdapter()->getCollection('mig_migrator2')->insertOne(['name' => 'migrated2']);
    }

    public function down(): void
    {
        $this->collection('mig_migrator2')->drop()->create();
    }
}
