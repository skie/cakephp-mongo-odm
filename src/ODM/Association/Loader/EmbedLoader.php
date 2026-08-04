<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association\Loader;

use Closure;
use Crustum\Mongo\ODM\Association\Embedded;

/**
 * Hydrates embedded association values without an external query.
 */
class EmbedLoader implements LoaderInterface
{
    /** Embedded association being loaded. */
    public function __construct(protected Embedded $association)
    {
    }

    /**
     * Builds the embedded hydration callable.
     *
     * @param array<string, mixed> $options Runtime loader options.
     * @return \Closure
     */
    public function buildEagerLoader(array $options): Closure
    {
        return $this->association->eagerLoader($options);
    }
}
