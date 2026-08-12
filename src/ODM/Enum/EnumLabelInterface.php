<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/cakephp EnumLabelInterface (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\ODM\Enum;

/**
 * An interface used to clarify that an enum has a label() method instead of
 * having to use the `name` property.
 */
interface EnumLabelInterface
{
    /**
     * Label to return as string.
     *
     * @return string
     */
    public function label(): string;
}
