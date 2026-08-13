<?php
declare(strict_types=1);

namespace TestApp\Model\Table;

use Cake\ORM\Table;

/**
 * SQL ORM Files table for the cross-boundary bridge tests.
 *
 * Source side of a `DBRef` bridge association: the SQL row holds a Mongo DBRef
 * pointer (`{ $ref, $id }`) in a JSON column.
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §5.5
 */
class FilesTable extends Table
{
    /**
     * @inheritDoc
     */
    public function initialize(array $config): void
    {
        $this->setTable('files');
    }
}
