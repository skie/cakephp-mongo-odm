<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association\Loader;

/**
 * Contract for association result loaders.
 */
interface LoaderInterface
{
    /**
     * Builds a callable that applies association results.
     *
     * @param array<string, mixed> $options Loader options.
     * @return callable
     */
    public function buildEagerLoader(array $options): callable;
}
