<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseMigration;

class Migrator extends BaseMigration
{
    public function up(): void
    {
        $this->collection('mig_migrator')->addField('name', 'string')->create();
        $this->getAdapter()->getCollection('mig_migrator')->insertOne(['name' => 'migrated']);
    }

    public function down(): void
    {
        $this->collection('mig_migrator')->drop()->create();
    }
}
