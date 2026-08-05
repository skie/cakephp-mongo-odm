<?php
declare(strict_types=1);

namespace TestApp\Model\Document;

use Crustum\Mongo\ODM\Document;

class ProtectedUser extends Document
{
    protected array $_hidden = ['password'];
}
