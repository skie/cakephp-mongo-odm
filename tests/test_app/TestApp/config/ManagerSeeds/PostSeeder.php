<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseSeed;

class PostSeeder extends BaseSeed
{
    public function run(): void
    {
        $this->insert('mig_seed_posts', [
            'body' => 'foo',
            'created' => date('Y-m-d H:i:s'),
        ]);
    }

    public function getDependencies(): array
    {
        return [
            'UserSeeder',
            'GSeeder',
        ];
    }
}
