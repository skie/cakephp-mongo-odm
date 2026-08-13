<?php
declare(strict_types=1);

namespace TestApp\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\Orm\Bridge\Orm\OrmAssociationsTrait;
use Crustum\Mongo\Orm\Bridge\OrmTableAwareInterface;
use Crustum\Mongo\Orm\Bridge\OrmTableAwareTrait;
use TestApp\Model\Table\FilesTable;

/**
 * Test Mongo collection implementing the Direction-2 bridge surface.
 *
 * Declares `belongsToOrm('Orders')` / `hasManyOrm('Orders')` against the SQL
 * `orders` table.
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §8
 */
class FilesCollection extends BaseCollection implements OrmTableAwareInterface
{
    use OrmAssociationsTrait;
    use OrmTableAwareTrait;

    /**
     * The collection name.
     *
     * @var string
     */
    protected ?string $collection = 'files';

    /**
     * @inheritDoc
     */
    public function initialize(array $config): void
    {
        $this->belongsToOrm('FileRow', [
            'foreignKey' => 'file_ref',
            'property' => 'fileRow',
            'className' => FilesTable::class,
        ]);
        $this->hasManyOrm('FileRows', [
            'foreignKey' => 'file_ref',
            'property' => 'fileRows',
            'className' => FilesTable::class,
        ]);
    }
}
