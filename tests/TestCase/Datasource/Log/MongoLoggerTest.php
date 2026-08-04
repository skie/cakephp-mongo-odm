<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Datasource\Log;

use Cake\Database\Log\LoggedQuery;
use Crustum\Mongo\Datasource\Log\MongoLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MongoLoggerTest extends TestCase
{
    public function testLogWithoutCommandPassesContextThrough(): void
    {
        $inner = new MemoryLogger();
        $logger = new MongoLogger($inner);

        $logger->log('info', 'message', ['foo' => 'bar']);

        $this->assertCount(1, $inner->records);
        [$level, $message, $context] = $inner->records[0];
        $this->assertSame('info', $level);
        $this->assertSame(['foo' => 'bar'], json_decode($message, true));
        $this->assertSame(['foo' => 'bar'], $context);
    }

    public function testLogFormatsCommandContextAsJsonMessage(): void
    {
        $inner = new MemoryLogger();
        $logger = new MongoLogger($inner);

        $logger->log('debug', 'ignored', [
            'command' => ['find' => 'articles', 'filter' => ['active' => true]],
            'database' => 'test',
            'collection' => 'articles',
        ]);

        [$level, $message] = $inner->records[0];
        $this->assertSame('debug', $level);

        $decoded = json_decode($message, true);
        $this->assertIsArray($decoded);
        $this->assertSame('find', $decoded['operation']);
        $this->assertSame('test', $decoded['database']);
        $this->assertSame('articles', $decoded['collection']);
        $this->assertSame(['active' => true], $decoded['filter']);
    }

    public function testLogBuildsLoggedQueryWhenDurationPresent(): void
    {
        $inner = new MemoryLogger();
        $logger = new MongoLogger($inner);

        $logger->log('info', 'ignored', [
            'command' => ['find' => 'articles'],
            'database' => 'test',
            'collection' => 'articles',
            'duration_ms' => 12,
            'numReturn' => 5,
        ]);

        $context = $inner->records[0][2];
        $this->assertArrayHasKey('query', $context);
        $this->assertInstanceOf(LoggedQuery::class, $context['query']);
        $this->assertSame(12.0, $context['query']->getContext()['took']);
        $this->assertSame(5, $context['query']->getContext()['numRows']);
    }

    public function testLogRethrowsExceptionFromContext(): void
    {
        $inner = new MemoryLogger();
        $logger = new MongoLogger($inner);

        $exception = new RuntimeException('boom');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $logger->log('error', 'ignored', ['exception' => $exception]);
    }

    public function testSetLoggerReplacesWrappedLogger(): void
    {
        $first = new MemoryLogger();
        $second = new MemoryLogger();
        $logger = new MongoLogger($first);

        $this->assertSame($first, $logger->getLogger());

        $result = $logger->setLogger($second);
        $this->assertSame($logger, $result);
        $this->assertSame($second, $logger->getLogger());
    }

    /**
     * The LoggedQuery built for a timed command stringifies to the encoded JSON.
     *
     * @return void
     */
    public function testLoggedQueryStringConversion(): void
    {
        $inner = new MemoryLogger();
        $logger = new MongoLogger($inner);

        $logger->log('info', 'ignored', [
            'command' => ['find' => 'articles', 'filter' => ['active' => true]],
            'database' => 'test',
            'collection' => 'articles',
            'duration_ms' => 12,
        ]);

        $query = $inner->records[0][2]['query'];
        $decoded = json_decode((string)$query, true);
        $this->assertSame('find', $decoded['operation']);
        $this->assertSame('test', $decoded['database']);
    }

    /**
     * The LoggedQuery context carries query, numRows and took.
     *
     * @return void
     */
    public function testLoggedQueryContext(): void
    {
        $inner = new MemoryLogger();
        $logger = new MongoLogger($inner);

        $logger->log('info', 'ignored', [
            'command' => ['insert' => 'articles'],
            'database' => 'test',
            'collection' => 'articles',
            'duration_ms' => 3,
            'numReturn' => 1,
        ]);

        $context = $inner->records[0][2]['query']->getContext();
        $this->assertSame('insert', json_decode($context['query'], true)['operation']);
        $this->assertSame(1, $context['numRows']);
        $this->assertSame(3.0, $context['took']);
    }

    /**
     * The LoggedQuery JSON-serializes without losing the encoded operation.
     *
     * @return void
     */
    public function testLoggedQueryJsonSerialize(): void
    {
        $inner = new MemoryLogger();
        $logger = new MongoLogger($inner);

        $logger->log('info', 'ignored', [
            'command' => ['find' => 'articles'],
            'database' => 'test',
            'collection' => 'articles',
            'duration_ms' => 12,
            'numReturn' => 5,
        ]);

        $query = $inner->records[0][2]['query'];
        $serialized = json_decode(json_encode($query), true);
        $this->assertSame('find', json_decode($serialized['query'], true)['operation']);
        $this->assertSame(5, $serialized['numRows']);
        $this->assertSame(12, $serialized['took']);
    }

    /**
     * A registered redactor scrubs the encoded query before exposure.
     *
     * @return void
     */
    public function testLoggedQueryRedactorApplied(): void
    {
        LoggedQuery::setRedactor(static fn(string $query, array $params): array => [str_replace('SECRET-KEY', '«REDACTED»', $query), $params]);

        try {
            $inner = new MemoryLogger();
            $logger = new MongoLogger($inner);

            $logger->log('info', 'ignored', [
                'command' => ['find' => 'articles', 'filter' => ['token' => 'SECRET-KEY']],
                'database' => 'test',
                'collection' => 'articles',
                'duration_ms' => 12,
            ]);

            $query = $inner->records[0][2]['query'];
            $this->assertStringNotContainsString('SECRET-KEY', (string)$query);
            $this->assertStringContainsString('«REDACTED»', (string)$query);
        } finally {
            LoggedQuery::setRedactor(null);
        }
    }
}
