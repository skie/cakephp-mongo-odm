<?php
declare(strict_types=1);

namespace Crustum\Mongo\Exception;

use Cake\Core\Exception\CakeException;

/**
 * Exception raised when a Document class cannot be found.
 */
class MissingDocumentException extends CakeException
{
    /**
     * Cake 5.4 template property; renamed in CakePHP 6 (no underscore).
     *
     * @var string
     */
    protected string $_messageTemplate = 'Document class %s could not be found.';
}
