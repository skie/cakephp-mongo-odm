<?php
declare(strict_types=1);

namespace Crustum\Mongo\Exception;

use Cake\Core\Exception\CakeException;

/**
 * Exception raised when a Collection class cannot be found.
 */
class MissingCollectionException extends CakeException
{
    /**
     * Cake 5.4 template property; renamed in CakePHP 6 (no underscore).
     *
     * @var string
     */
    protected string $_messageTemplate = 'Collection class %s could not be found.';
}
