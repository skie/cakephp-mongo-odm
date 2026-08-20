<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Crustum\Mongo\Database\Driver\MongoDriver;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Override;

/**
 * Time type converter
 *
 * Use to convert time-of-day values between PHP and MongoDB. MongoDB has no
 * dedicated time-only BSON type, so values are stored as `H:i:s` strings.
 *
 * @inspired-by \Cake\Database\Type\TimeType
 */
class TimeType extends BaseType implements BatchCastingInterface
{
    /**
     * The PHP time format used when converting to string
     *
     * @var string
     */
    protected string $format = 'H:i:s';

    /**
     * Whether `marshal()` should use the locale-aware parser.
     *
     * @var bool
     */
    protected bool $useLocaleMarshal = false;

    /**
     * The locale-aware format `marshal()` uses when `useLocaleMarshal` is enabled.
     *
     * @var string|int|null
     */
    protected string|int|null $localeMarshalFormat = null;

    /**
     * Constructor.
     *
     * @param string|null $name The name identifying this type.
     */
    public function __construct(?string $name = null)
    {
        parent::__construct($name);
    }

    /**
     * Convert time data into the database format
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return string|null
     */
    public function toDatabase(mixed $value, MongoDriver $driver): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format($this->format);
        }

        throw new InvalidArgumentException(
            sprintf(
                'Cannot convert value `%s` of type `%s` to time',
                print_r($value, true),
                get_debug_type($value),
            ),
        );
    }

    /**
     * Convert time values to PHP DateTimeInterface
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return \DateTimeInterface|null
     */
    public function toPHP(mixed $value, MongoDriver $driver): ?DateTimeInterface
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        $date = DateTimeImmutable::createFromFormat('!H:i:s', (string)$value);
        if ($date === false) {
            return null;
        }

        return $date;
    }

    /**
     * @inheritDoc
     */
    public function manyToPHP(array $values, array $fields, MongoDriver $driver): array
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $values)) {
                continue;
            }

            $values[$field] = $this->toPHP($values[$field], $driver);
        }

        return $values;
    }

    /**
     * Marshals request data into a PHP DateTimeInterface
     *
     * Accepts a time string, an array of `hour`, `minute`, `second` parts or a
     * date/time instance.
     *
     * @param mixed $value The value to convert
     * @return \DateTimeInterface|null Converted value
     */
    #[Override]
    public function marshal(mixed $value): ?DateTimeInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        if (is_string($value)) {
            if ($this->useLocaleMarshal) {
                return $this->parseLocalTimeValue($value);
            }

            return $this->parseTimeValue($value);
        }

        if (!is_array($value)) {
            return null;
        }

        $value += ['hour' => null, 'minute' => null, 'second' => 0];
        if (
            !is_numeric($value['hour']) ||
            !is_numeric($value['minute']) ||
            !is_numeric($value['second'])
        ) {
            return null;
        }

        if (isset($value['meridian']) && (int)$value['hour'] === 12) {
            $value['hour'] = 0;
        }

        if (isset($value['meridian'])) {
            $value['hour'] = strtolower($value['meridian']) === 'am' ? $value['hour'] : (int)$value['hour'] + 12;
        }

        $format = sprintf(
            '%02d:%02d:%02d',
            (int)$value['hour'],
            (int)$value['minute'],
            (int)$value['second'],
        );

        $date = DateTimeImmutable::createFromFormat('!H:i:s', $format);
        if ($date === false) {
            return null;
        }

        return $date;
    }

    /**
     * Sets whether `marshal()` parses strings using the locale-aware format.
     *
     * @param bool $enable Whether to enable.
     * @return $this
     */
    public function useLocaleParser(bool $enable = true): static
    {
        $this->useLocaleMarshal = $enable;

        return $this;
    }

    /**
     * Sets the locale-aware format used by `marshal()` when locale parsing is enabled.
     *
     * @param string|int|null $format The format to use.
     * @return $this
     */
    public function setLocaleFormat(string|int|null $format): static
    {
        $this->localeMarshalFormat = $format;

        return $this;
    }

    /**
     * Gets the class name used for building objects.
     *
     * @return class-string<\DateTimeImmutable>
     */
    public function getTimeClassName(): string
    {
        return DateTimeImmutable::class;
    }

    /**
     * Converts a string into a time object using the default format.
     *
     * @param string $value The value to parse.
     * @return \DateTimeInterface|null
     */
    protected function parseTimeValue(string $value): ?DateTimeInterface
    {
        $date = DateTimeImmutable::createFromFormat('!H:i:s', $value);

        return $date === false ? null : $date;
    }

    /**
     * Converts a string using the configured locale-aware format.
     *
     * @param string $value The value to parse.
     * @return \DateTimeInterface|null
     */
    protected function parseLocalTimeValue(string $value): ?DateTimeInterface
    {
        if ($this->localeMarshalFormat === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat((string)$this->localeMarshalFormat, $value);

        return $date === false ? null : $date;
    }
}
