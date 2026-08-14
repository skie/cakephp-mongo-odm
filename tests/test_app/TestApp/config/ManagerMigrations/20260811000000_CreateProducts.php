<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseMigration;

class CreateProducts extends BaseMigration
{
    public function up(): void
    {
        $this->collection('products')
            ->addField('name', 'string')
            ->addField('price', 'decimal128')
            ->addIndex(['name'], ['unique' => true])
            ->create();
    }

    public function down(): void
    {
        $this->collection('products')->drop()->create();
    }
}
