<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database;

/**
 * Placeholder/parameter registry for Mongo queries.
 *
 * Mongo uses no positional `?` placeholders (filters are associative arrays),
 * so this registry is lightweight: it binds named parameters to values and
 * types so `Expression::sql()` and the query compiler can apply type-aware
 * conversion (e.g. 24-hex strings to `ObjectId`) before execution.
 */
class ValueBinder
{
    /**
     * Bound parameters.
     *
     * @var array<string, array{value: mixed, type: string|null}>
     */
    protected array $params = [];

    /**
     * Binds a value with an optional type name.
     *
     * @param string $placeholder Placeholder name (without leading `:`).
     * @param mixed $value The value to bind.
     * @param string|null $type Type name used for conversion.
     * @return $this
     */
    public function bind(string $placeholder, mixed $value, ?string $type = null): static
    {
        $this->params[$placeholder] = ['value' => $value, 'type' => $type];

        return $this;
    }

    /**
     * Returns the value bound to a placeholder.
     *
     * @param string $placeholder Placeholder name.
     * @return mixed
     */
    public function value(string $placeholder): mixed
    {
        return $this->params[$placeholder]['value'] ?? null;
    }

    /**
     * Returns the type bound to a placeholder, if any.
     *
     * @param string $placeholder Placeholder name.
     * @return string|null
     */
    public function type(string $placeholder): ?string
    {
        return $this->params[$placeholder]['type'] ?? null;
    }

    /**
     * Returns all bound parameters.
     *
     * @return array<string, mixed>
     */
    public function bindings(): array
    {
        return array_map(
            static fn(array $binding): mixed => $binding['value'],
            $this->params,
        );
    }

    /**
     * Returns whether a placeholder is bound.
     *
     * @param string $placeholder Placeholder name.
     * @return bool
     */
    public function has(string $placeholder): bool
    {
        return isset($this->params[$placeholder]);
    }

    /**
     * Clears all bindings.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->params = [];
    }

    /**
     * Returns an array that can be used to describe the internal state of this
     * object.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'bindings' => $this->bindings(),
        ];
    }
}
