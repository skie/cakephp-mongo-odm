<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Schema;

/**
 * Test enum for the Field value object.
 */
enum FieldTestStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
