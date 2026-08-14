<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseMigration;

class CreateArticles extends BaseMigration
{
    public function up(): void
    {
        $this->collection('articles')
            ->addField('title', 'string')
            ->addField('author_id', 'objectid')
            ->create();
    }

    public function down(): void
    {
        $this->collection('articles')->drop()->create();
    }
}
