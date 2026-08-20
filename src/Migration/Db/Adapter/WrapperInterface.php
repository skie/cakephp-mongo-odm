<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration\Db\Adapter;

/**
 * Wrapper interface for adapters that proxy another adapter.
 *
 * @ported-from \Migrations\Db\Adapter\WrapperInterface
 */
interface WrapperInterface extends AdapterInterface
{
    /**
     * Class constructor, must always wrap another adapter.
     *
     * @param \Crustum\Mongo\Migration\Db\Adapter\AdapterInterface $adapter Adapter
     */
    public function __construct(AdapterInterface $adapter);

    /**
     * Sets the database adapter to proxy commands to.
     *
     * @param \Crustum\Mongo\Migration\Db\Adapter\AdapterInterface $adapter Adapter
     * @return \Crustum\Mongo\Migration\Db\Adapter\AdapterInterface
     */
    public function setAdapter(AdapterInterface $adapter): AdapterInterface;

    /**
     * Gets the database adapter.
     *
     * @return \Crustum\Mongo\Migration\Db\Adapter\AdapterInterface
     */
    public function getAdapter(): AdapterInterface;
}
