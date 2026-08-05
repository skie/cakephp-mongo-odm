<?php
declare(strict_types=1);

namespace TestApp\Model\Document;

use Crustum\Mongo\ODM\Document;

class Session extends Document
{
    protected array $_accessible = [
        'id' => false,
        '*' => true,
    ];
}
