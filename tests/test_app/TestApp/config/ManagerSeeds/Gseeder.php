<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseSeed;

class GSeeder extends BaseSeed
{
    public function run(): void
    {
        $this->insert('seed_posts', [
            'body' => 'foo',
            'created' => date('Y-m-d H:i:s'),
        ]);
    }
}
