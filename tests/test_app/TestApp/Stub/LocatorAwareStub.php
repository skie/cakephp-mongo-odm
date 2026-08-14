<?php
declare(strict_types=1);

namespace TestApp\Stub;

use Crustum\Mongo\ODM\Locator\LocatorAwareTrait;

class LocatorAwareStub
{
    use LocatorAwareTrait;

    public function __construct(?string $defaultCollection = null)
    {
        $this->defaultCollection = $defaultCollection;
    }
}
