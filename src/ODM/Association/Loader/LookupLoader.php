<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association\Loader;

/**
 * Applies an association's aggregation lookup pipeline.
 *
 * @see cake60/src/ORM/Association/Loader/SelectLoader.php
 */
class LookupLoader implements LoaderInterface
{
    /**
     * Loader configuration.
     *
     * @var array<string, mixed>
     */
    protected array $options;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $options Loader configuration.
     */
    public function __construct(array $options)
    {
        $this->options = $options;
    }

    /**
     * Builds a callable that applies lookup stages to a query.
     *
     * @param array<string, mixed> $options Runtime loader options.
     * @return callable
     */
    public function buildEagerLoader(array $options): callable
    {
        return function (iterable $entities) use ($options): iterable {
            $query = $options['query'] ?? null;
            if (is_object($query) && is_callable([$query, 'pipeline'])) {
                $query->pipeline($this->options['association']->buildPipeline($options));
            }

            return $entities;
        };
    }
}
