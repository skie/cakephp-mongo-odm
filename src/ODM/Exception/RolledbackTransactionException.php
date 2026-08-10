<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Exception;

use Cake\Core\Exception\CakeException;

/**
 * Used when a transaction was rolled back from a callback event.
 *
 * @see cake60/src/ORM/Exception/RolledbackTransactionException.php
 */
class RolledbackTransactionException extends CakeException
{
    /**
     * @var string
     */
    protected string $_messageTemplate = 'The afterSave event in `%s` is aborting the transaction'
        . ' before the save process is done.';
}
