<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Log;

use Cake\Database\Log\LoggedQuery;
use Cake\Log\Engine\BaseLog;
use Cake\Log\Log;
use Stringable;

/**
 * Bridge that writes {@see LoggedQuery} objects into Cake's Log.
 *
 * Mirrors `Cake\Database\Log\QueryLogger`, with scopes for the Mongo
 * database layer: `queriesLog` and `mongo.database.queries`.
 *
 * @see \Cake\Database\Log\QueryLogger
 * @internal
 */
class QueryLogger extends BaseLog
{
    /**
     * Default log scopes for Mongo query logging.
     *
     * @var list<string>
     */
    public const SCOPES = ['mongoQueriesLog', 'mongo.database.queries'];

    /**
     * Constructor.
     *
     * @param array<string, mixed> $config Configuration array.
     */
    public function __construct(array $config = [])
    {
        $this->_defaultConfig['scopes'] = self::SCOPES;
        $this->_defaultConfig['connection'] = '';

        parent::__construct($config);
    }

    /**
     * @inheritDoc
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $context += [
            'scope' => $this->scopes() ?: self::SCOPES,
            'connection' => $this->getConfig('connection'),
            'query' => null,
        ];

        if ($context['query'] instanceof LoggedQuery) {
            $queryContext = $context['query']->getContext();
            $context = $queryContext + $context;

            $connection = (string)($context['connection'] ?? '');
            if ($connection === '') {
                $connection = (string)$this->getConfig('connection');
            }

            $role = (string)($context['role'] ?? '');
            if ($role === '') {
                $role = 'mongo';
            }

            $message = sprintf(
                'connection=%s role=%s duration=%s rows=%s %s',
                $connection,
                $role,
                $context['took'] ?? 0,
                $context['numRows'] ?? 0,
                (string)$message,
            );

            $context['connection'] = $connection;
            $context['role'] = $role;
        }

        Log::write('debug', (string)$message, $context);
    }
}
