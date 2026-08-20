<?php
declare(strict_types=1);

namespace Crustum\Mongo\Validation;

use Cake\I18n\Date as I18nDate;
use Cake\Validation\Validation;
use DateTimeInterface;
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

    /**
     * Checks that the value is a valid date.
     *
     * Accepts the ODM's own `Cake\I18n\Date` (a `ChronosDate` that is not a
     * `DateTimeInterface`) plus `DateTimeInterface` instances, and falls back to
     * `Validation::date()` for date strings.
     *
     * @param mixed $check The value to validate.
     * @return bool
     */
    public static function date(mixed $check): bool
    {
        if ($check instanceof I18nDate || $check instanceof DateTimeInterface) {
            return true;
        }

        if (!is_string($check)) {
            return false;
        }

        return Validation::date($check, 'ymd');
    }
}
