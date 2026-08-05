<?php
declare(strict_types=1);

namespace TestApp\Model\Document;

use Crustum\Mongo\ODM\Document;

class TranslateBakedArticle extends Document
{
    protected array $_accessible = [
        'title' => true,
        'body' => true,
    ];
}
