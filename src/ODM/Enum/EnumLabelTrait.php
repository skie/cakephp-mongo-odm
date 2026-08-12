<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/cakephp EnumLabelTrait (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\ODM\Enum;

use Cake\Utility\Inflector;

/**
 * Provides a `label()` method for backed enums used by the Mongo ODM.
 *
 * Returns a humanized version of the enum case name.
 */
trait EnumLabelTrait
{
    /**
     * Returns the label for the enum case.
     *
     * @return string
     */
    public function label(): string
    {
        return Inflector::humanize(Inflector::underscore($this->name));
    }
}
