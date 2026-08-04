<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

/**
 * Int-backed test enum
 */
enum Priority: int
{
    case Low = 1;
    case High = 2;
}
