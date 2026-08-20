<?php
declare(strict_types=1);

namespace TestApp\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;

class SecondaryPostsCollection extends BaseCollection
{
    #[\Override]
    public static function defaultConnectionName(): string
    {
        return 'secondary';
    }
}
