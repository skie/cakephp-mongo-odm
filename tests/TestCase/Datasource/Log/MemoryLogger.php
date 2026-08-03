<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Datasource\Log;

use Psr\Log\AbstractLogger;

/**
 * In-memory PSR-3 logger used by the Datasource tests.
 *
 * @internal
 */
class MemoryLogger extends AbstractLogger
{
    /**
     * Captured records as `[level, message, context]`.
     *
     * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [$level, $message, $context];
    }
}
