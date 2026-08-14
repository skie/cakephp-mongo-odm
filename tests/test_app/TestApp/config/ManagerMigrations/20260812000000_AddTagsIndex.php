<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseMigration;

class AddTagsIndex extends BaseMigration
{
    public function up(): void
    {
        $this->collection('products')
            ->addIndex(['tags'], ['name' => 'tags_index'])
            ->update();
    }

    public function down(): void
    {
        $this->dropIndex('products', 'tags_index');
    }
}
