<?php
declare(strict_types=1);

namespace TestApp\Model\Document;

use Crustum\Mongo\ODM\Document;

class ProtectedEntity extends Document
{
    protected array $_accessible = [
        'id' => true,
        'title' => false,
    ];
}
