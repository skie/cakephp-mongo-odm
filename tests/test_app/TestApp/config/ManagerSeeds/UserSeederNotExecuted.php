<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseSeed;

class UserSeederNotExecuted extends BaseSeed
{
    public function run(): void
    {
        $this->insert('mig_seed_users', [
            'name' => 'foo',
            'created' => date('Y-m-d H:i:s'),
        ]);
    }

    public function shouldExecute(): bool
    {
        return false;
    }
}
