<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Enum;

/**
 * Capability set supported by a Mongo driver/server.
 *
 * Named without an `Enum` suffix per the Cake-6 convention (`Enum\` subnamespace
 * conveys it). Consumed via `DriverInterface::supports(DriverFeature $feature)`.
 */
enum DriverFeature: string
{
    case Transactions = 'transactions';
    case Sessions = 'sessions';
    case Aggregation = 'aggregation';
    case SearchIndex = 'searchIndex';
    case ChangeStreams = 'changeStreams';
    case VectorSearch = 'vectorSearch';
    case Window = 'window';
}
