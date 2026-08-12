<?php
declare(strict_types=1);

namespace Crustum\Mongo\Validation;

use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\InvalidArgumentException;

/**
 * Validation rules shared by the Mongo ODM.
 *
 * Used as a validation provider from baked `validationDefault()` methods:
 *
 * ```php
 * $validator->setProvider('mongo', MongoValidation::class);
 * $validator->add('author_id', 'validId', ['rule' => 'validId', 'provider' => 'mongo']);
 * ```
 */
class MongoValidation
{
    /**
     * Checks that the value is a valid BSON ObjectId (24 hex chars).
     *
     * @param mixed $check The value to validate.
     * @return bool
     */
    public static function validId(mixed $check): bool
    {
        if ($check === null || $check === '') {
            return false;
        }

        if ($check instanceof ObjectId) {
            return true;
        }

        if (!is_string($check)) {
            return false;
        }

        try {
            new ObjectId($check);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
