<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Exception;

use Cake\Core\Exception\CakeException;
use Cake\Datasource\EntityInterface;
use Cake\Utility\Hash;
use Throwable;

/**
 * Used when a strict save or delete fails.
 *
 * @ported-from \Cake\ORM\Exception\PersistenceFailedException
 */
class PersistenceFailedException extends CakeException
{
    /**
     * @inheritDoc
     */
    protected string $_messageTemplate = 'Document %s failure.';

    /**
     * Constructor.
     *
     * @param \Cake\Datasource\EntityInterface $document The document on which the persistence operation failed.
     * @param array<string>|string $message Either the string of the error message, or an array of attributes
     *   that are made available in the view, and sprintf()'d into `$messageTemplate`.
     * @param int|null $code The code of the error.
     * @param \Throwable|null $previous The previous exception.
     */
    public function __construct(
        protected EntityInterface $document,
        array|string $message,
        ?int $code = null,
        ?Throwable $previous = null,
    ) {
        if (is_array($message)) {
            $errors = [];
            foreach (Hash::flatten($this->document->getErrors()) as $field => $error) {
                $errors[] = $field . ': "' . $error . '"';
            }

            if ($errors !== []) {
                $message[] = implode(', ', $errors);
                $this->_messageTemplate = 'Document %s failure. Found the following errors (%s).';
            }
        }

        parent::__construct($message, $code, $previous);
    }

    /**
     * Gets the passed in document.
     *
     * @return \Cake\Datasource\EntityInterface
     */
    public function getDocument(): EntityInterface
    {
        return $this->document;
    }
}
