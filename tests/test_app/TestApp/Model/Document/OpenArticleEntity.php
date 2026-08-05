<?php
declare(strict_types=1);

namespace TestApp\Model\Document;

use Crustum\Mongo\ODM\Document;

class OpenArticleEntity extends Document
{
    protected array $_accessible = [
        '*' => true,
    ];
}
