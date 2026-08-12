<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration\Config;

use Closure;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Migrations configuration class.
 */
class Config implements ConfigInterface
{
    /**
     * The value that identifies a version order by creation time.
     */
    public const VERSION_ORDER_CREATION_TIME = 'creation';

    /**
     * The value that identifies a version order by execution time.
     */
    public const VERSION_ORDER_EXECUTION_TIME = 'execution';

    /**
     * @var array<string, mixed>
     */
    protected array $values = [];

    /**
     * @param array<string, mixed> $configArray Config array
     */
    public function __construct(array $configArray)
    {
        $this->values = $configArray;
    }

    /**
     * @inheritDoc
     */
    public function getEnvironment(): ?array
    {
        if (empty($this->values['environment'])) {
            return null;
        }

        $config = (array)$this->values['environment'];
        $config['version_order'] = $this->getVersionOrder();

        return $config;
    }

    /**
     * @inheritDoc
     */
    public function getMigrationPath(): string
    {
        if (!isset($this->values['paths']['migrations'])) {
            throw new UnexpectedValueException('Migrations path missing from config file');
        }

        if (is_array($this->values['paths']['migrations']) && isset($this->values['paths']['migrations'][0])) {
            return (string)$this->values['paths']['migrations'][0];
        }

        return (string)$this->values['paths']['migrations'];
    }

    /**
     * @inheritDoc
     */
    public function getSeedPath(): string
    {
        if (!isset($this->values['paths']['seeds'])) {
            throw new UnexpectedValueException('Seeds path missing from config file');
        }

        if (is_array($this->values['paths']['seeds']) && isset($this->values['paths']['seeds'][0])) {
            return (string)$this->values['paths']['seeds'][0];
        }

        return (string)$this->values['paths']['seeds'];
    }

    /**
     * @inheritDoc
     */
    public function getConnection(): string|false
    {
        return $this->values['environment']['connection'] ?? false;
    }

    /**
     * @inheritDoc
     */
    public function getVersionOrder(): string
    {
        if (!isset($this->values['version_order'])) {
            return self::VERSION_ORDER_CREATION_TIME;
        }

        return (string)$this->values['version_order'];
    }

    /**
     * @inheritDoc
     */
    public function isVersionOrderCreationTime(): bool
    {
        return $this->getVersionOrder() === self::VERSION_ORDER_CREATION_TIME;
    }

    /**
     * @inheritDoc
     */
    public function isDryRun(): bool
    {
        return (bool)($this->values['environment']['dryrun'] ?? false);
    }

    /**
     * @param mixed $offset Identifier
     * @param mixed $value Value
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->values[$offset] = $value;
    }

    /**
     * @param mixed $offset Identifier
     * @throws \InvalidArgumentException
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed
    {
        if (!array_key_exists($offset, $this->values)) {
            throw new InvalidArgumentException(sprintf('Identifier "%s" is not defined.', $offset));
        }

        $value = $this->values[$offset];

        return $value instanceof Closure ? $value($this) : $value;
    }

    /**
     * @param mixed $offset Identifier
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->values[$offset]);
    }

    /**
     * @param mixed $offset Identifier
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->values[$offset]);
    }
}
