<?php
declare(strict_types=1);

namespace TestApp\Model\Document;

use Crustum\Mongo\ODM\Document;

class Article extends Document
{
    public function isRequired(): bool
    {
        return true;
    }
}
