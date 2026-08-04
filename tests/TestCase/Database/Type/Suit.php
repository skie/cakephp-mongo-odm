<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

/**
 * String-backed test enum
 */
enum Suit: string
{
    case Hearts = 'hearts';
    case Spades = 'spades';
}
