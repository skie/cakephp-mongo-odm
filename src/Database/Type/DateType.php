<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Cake\I18n\DateTime as CakeDateTime;
use Crustum\Mongo\Database\Driver\MongoDriver;
use DateTime;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;
use MongoDB\BSON\UTCDateTime;

/**
 * Date type converter
 *
 * Use to convert date data between PHP and MongoDB.
 *
 * @see cake60/src/Database/Type/DateType.php
 */
class DateType extends BaseType implements BatchCastingInterface
{
    /**
     * The formats accepted when parsing string input during `marshal()`.
     *
     * @var array<string>
     */
    protected array $marshalFormats = [
        'Y-m-d H:i:s',
        'Y-m-d',
    ];

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
     * Convert date data into the database format
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return \MongoDB\BSON\UTCDateTime|null
     */
    public function toDatabase(mixed $value, MongoDriver $driver): ?UTCDateTime
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof UTCDateTime) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return new UTCDateTime($value);
        }

        if (is_numeric($value)) {
            return new UTCDateTime((int)$value);
        }

        if (is_string($value)) {
            try {
                $date = new DateTime($value);

                return new UTCDateTime($date);
            } catch (Exception $e) {
                throw new InvalidArgumentException(
                    sprintf('Cannot convert "%s" to date', $value),
                    0,
                    $e,
                );
            }
        }

        throw new InvalidArgumentException(
            sprintf('Cannot convert %s to date', gettype($value)),
        );
    }

    /**
     * Convert date values to PHP Cake DateTime
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return \Cake\I18n\DateTime|null
     */
    public function toPHP(mixed $value, MongoDriver $driver): ?DateTimeInterface
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof UTCDateTime) {
            return new CakeDateTime($value->toDateTime());
        }

        if ($value instanceof DateTimeInterface) {
            return new CakeDateTime($value);
        }

        if (is_numeric($value)) {
            return new CakeDateTime('@' . (int)$value);
        }

        if (is_string($value)) {
            try {
                return new CakeDateTime($value);
            } catch (Exception) {
                return null;
            }
        }

        return null;
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
     * Marshals request data into Cake DateTime
     *
     * @param mixed $value The value to convert
     * @return \Cake\I18n\DateTime|null Converted value
     */
    public function marshal(mixed $value): ?DateTimeInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return new CakeDateTime($value);
        }

        if (is_string($value)) {
            if ($this->useLocaleMarshal) {
                $parsed = $this->parseLocaleValue($value);
                if ($parsed instanceof DateTimeInterface) {
                    return new CakeDateTime($parsed);
                }

                return null;
            }

            $parsed = $this->parseValue($value);
            if ($parsed instanceof DateTimeInterface) {
                return new CakeDateTime($parsed);
            }

            try {
                return new CakeDateTime($value);
            } catch (Exception) {
                return null;
            }
        }

        if (!is_array($value)) {
            return null;
        }

        if (
            !isset($value['year'], $value['month'], $value['day']) ||
            !is_numeric($value['year']) || !is_numeric($value['month']) || !is_numeric($value['day'])
        ) {
            return null;
        }

        $format = sprintf('%d-%02d-%02d', $value['year'], $value['month'], $value['day']);

        if (isset($value['hour']) && is_numeric($value['hour'])) {
            $hour = (int)$value['hour'];
            if (isset($value['meridian']) && strtolower((string)$value['meridian']) === 'pm' && $hour < 12) {
                $hour += 12;
            }

            $format .= sprintf(' %02d', $hour);
            if (isset($value['minute']) && is_numeric($value['minute'])) {
                $format .= sprintf(':%02d', (int)$value['minute']);
            }

            if (isset($value['second']) && is_numeric($value['second'])) {
                $format .= sprintf(':%02d', (int)$value['second']);
            }
        }

        return new CakeDateTime($format);
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
     * @return class-string<\Cake\I18n\DateTime>
     */
    public function getDateClassName(): string
    {
        return CakeDateTime::class;
    }

    /**
     * Converts a string using the configured locale-aware format.
     *
     * @param string $value The value to parse.
     * @return \DateTimeInterface|null
     */
    protected function parseLocaleValue(string $value): ?DateTimeInterface
    {
        if ($this->localeMarshalFormat === null) {
            return null;
        }

        $date = DateTime::createFromFormat((string)$this->localeMarshalFormat, $value);

        return $date === false ? null : $date;
    }

    /**
     * Converts a string using the accepted marshal formats.
     *
     * @param string $value The value to parse.
     * @return \DateTimeInterface|null
     */
    protected function parseValue(string $value): ?DateTimeInterface
    {
        foreach ($this->marshalFormats as $format) {
            $date = DateTime::createFromFormat($format, $value);
            if ($date !== false) {
                return $date;
            }
        }

        return null;
    }
}
