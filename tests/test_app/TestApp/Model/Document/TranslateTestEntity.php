<?php
declare(strict_types=1);

namespace TestApp\Model\Document;

use Crustum\Mongo\ODM\Behavior\Translate\TranslateTrait;
use Crustum\Mongo\ODM\Document;

class TranslateTestEntity extends Document
{
    use TranslateTrait;
}
