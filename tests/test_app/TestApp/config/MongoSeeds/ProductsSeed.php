<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseSeed;

class ProductsSeed extends BaseSeed
{
    public function run(): void
    {
        $this->insert('mig_seed_products', [
            'name' => 'widget',
            'qty' => 1,
        ]);
    }
}
