<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseMigration;

class AddEmailIndex extends BaseMigration
{
    public function up(): void
    {
        $this->collection('mig_articles')
            ->addIndex(['title'], ['name' => 'title_index'])
            ->update();
    }

    public function down(): void
    {
        $this->dropIndex('mig_articles', 'title_index');
    }
}
