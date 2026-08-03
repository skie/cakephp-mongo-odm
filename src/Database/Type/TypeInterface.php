<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Crustum\Mongo\Database\Driver\MongoDriver;

/**
 * Type interface for MongoDB type conversion
 *
 * Encapsulates all conversion functions for values coming from MongoDB into PHP
 * and going from PHP into MongoDB BSON format.
 */
interface TypeInterface
{
    /**
     * Casts given value from a PHP type to one acceptable by MongoDB
     *
     * @param mixed $value Value to be converted to a MongoDB equivalent
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver Driver instance
     * @return mixed Given PHP type casted to one acceptable by MongoDB
     */
    public function toDatabase(mixed $value, MongoDriver $driver): mixed;

    /**
     * Casts given value from a MongoDB type to a PHP equivalent
     *
     * @param mixed $value Value to be converted to PHP equivalent
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver Driver instance
     * @return mixed Given value casted from MongoDB to a PHP equivalent
     */
    public function toPHP(mixed $value, MongoDriver $driver): mixed;

    /**
     * Marshals flat data into PHP objects
     *
     * Most useful for converting request data into PHP objects
     * that make sense for the rest of the ORM/Database layers
     *
     * @param mixed $value The value to convert
     * @return mixed Converted value
     */
    public function marshal(mixed $value): mixed;

    /**
     * Returns the base type name that this class is inheriting
     *
     * This is useful when extending base type for adding extra functionality,
     * but still want the rest of the framework to use the same assumptions it would
     * do about the base type it inherits from
     *
     * @return string|null The base type name that this class is inheriting
     */
    public function getBaseType(): ?string;

    /**
     * Returns type identifier name for this object
     *
     * @return string|null The type identifier name for this object
     */
    public function getName(): ?string;

    /**
     * Generates a new value of the type's native format.
     *
     * Useful for identifier generation; defaults to `null` unless overridden
     * (e.g. `ObjectIdType::newId()` returns a fresh `ObjectId`).
     *
     * @return mixed
     */
    public function newId(): mixed;
}
