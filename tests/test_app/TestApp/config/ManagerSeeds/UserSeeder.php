<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseSeed;

class UserSeeder extends BaseSeed
{
    public function run(): void
    {
        $this->insert('seed_users', [
            'name' => 'foo',
            'created' => date('Y-m-d H:i:s'),
        ]);
        $this->insert('seed_users', [
            'name' => 'bar',
            'created' => date('Y-m-d H:i:s'),
        ]);
    }
}
