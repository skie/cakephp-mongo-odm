<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use BackedEnum;
use Cake\Utility\Text;
use Crustum\Mongo\Database\Driver\MongoDriver;
use InvalidArgumentException;
use ReflectionEnum;
use ReflectionException;
use ReflectionNamedType;
use TypeError;
use ValueError;

/**
 * Enum type converter
 *
 * Use to convert backed enum instances to their scalar values and back.
 * Mirrors `Cake\Database\Type\EnumType`.
 */
class EnumType extends BaseType
{
    /**
     * The backing type of the enum, either `int` or `string`
     *
     * @var string
     */
    protected string $backingType;

    /**
     * The enum class associated to the type instance
     *
     * @var class-string<\BackedEnum>
     */
    protected string $enumClassName;

    /**
     * Constructor
     *
     * @param string $name The name identifying this type
     * @param class-string<\BackedEnum> $enumClassName The associated enum class name
     * @throws \InvalidArgumentException When the enum is not a backed enum
     */
    public function __construct(string $name, string $enumClassName)
    {
        parent::__construct($name);

        try {
            $reflectionEnum = new ReflectionEnum($enumClassName);
        } catch (ReflectionException $reflectionException) {
            throw new InvalidArgumentException(
                sprintf('Unable to use `%s` for type `%s`. %s', $enumClassName, $name, $reflectionException->getMessage()),
                0,
                $reflectionException,
            );
        }

        $namedType = $reflectionEnum->getBackingType();
        if (!$namedType instanceof ReflectionNamedType) {
            throw new InvalidArgumentException(
                sprintf('Unable to use enum `%s` for type `%s`, must be a backed enum.', $enumClassName, $name),
            );
        }

        $this->backingType = (string)$namedType;
        $this->enumClassName = $enumClassName;
    }

    /**
     * Convert enum instances into the database format
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return string|int|null
     * @throws \InvalidArgumentException When the given value is not a valid value for the associated enum
     */
    public function toDatabase(mixed $value, MongoDriver $driver): string|int|null
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof $this->enumClassName) {
            return $value->value;
        }

        if ($this->backingType === 'int' && is_string($value)) {
            $intVal = filter_var($value, FILTER_VALIDATE_INT);
            if ($intVal !== false) {
                $value = $intVal;
            }
        }

        try {
            return $this->enumClassName::from($value)->value;
        } catch (ValueError | TypeError $exception) {
            if ($exception instanceof TypeError) {
                throw new InvalidArgumentException(sprintf(
                    'Given value `%s` of type `%s` does not match associated `%s` backed enum in `%s`',
                    print_r($value, true),
                    get_debug_type($value),
                    $this->backingType,
                    $this->enumClassName,
                ), $exception->getCode(), $exception);
            }

            throw new InvalidArgumentException(sprintf('`%s` is not a valid value for `%s`', $value, $this->enumClassName), $exception->getCode(), $exception);
        }
    }

    /**
     * Convert database values to backed enum instances
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return \BackedEnum|null
     */
    public function toPHP(mixed $value, MongoDriver $driver): ?BackedEnum
    {
        if ($value === null) {
            return null;
        }

        if ($this->backingType === 'int' && is_string($value)) {
            $intVal = filter_var($value, FILTER_VALIDATE_INT);
            if ($intVal !== false) {
                $value = $intVal;
            }
        }

        return $this->enumClassName::from($value);
    }

    /**
     * Marshals request data
     *
     * @param mixed $value The value to convert
     * @return \BackedEnum|null Converted value
     */
    public function marshal(mixed $value): ?BackedEnum
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof $this->enumClassName) {
            return $value;
        }

        if ($this->backingType === 'int') {
            if ($value === '') {
                return null;
            }

            if (is_numeric($value)) {
                $value = (int)$value;
            }
        }

        try {
            return $this->enumClassName::from($value);
        } catch (ValueError | TypeError) {
            return null;
        }
    }

    /**
     * Creates an `EnumType` paired with the provided `$enumClassName` and
     * registers it in the type factory.
     *
     * @param class-string<\BackedEnum> $enumClassName The enum class name
     * @return string The generated type name
     */
    public static function from(string $enumClassName): string
    {
        $typeName = 'enum-' . strtolower(Text::slug($enumClassName));
        $instance = new EnumType($typeName, $enumClassName);
        TypeFactory::set($typeName, $instance);

        return $typeName;
    }

    /**
     * Returns the enum class name associated to this type
     *
     * @return class-string<\BackedEnum>
     */
    public function getEnumClassName(): string
    {
        return $this->enumClassName;
    }
}
