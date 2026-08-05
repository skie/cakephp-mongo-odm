<?php
declare(strict_types=1);

namespace TestApp\Model\Document;

use Crustum\Mongo\ODM\Document;

class VirtualUser extends Document
{
    protected array $_virtual = ['bonus'];

    protected function _getBonus(): string
    {
        return 'bonus';
    }
}
