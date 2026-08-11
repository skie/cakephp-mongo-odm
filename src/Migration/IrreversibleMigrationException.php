<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration;

use RuntimeException;

/**
 * Thrown when a `change()` migration records a command that cannot be
 * reversed automatically for the `down` direction.
 */
class IrreversibleMigrationException extends RuntimeException
{
}
