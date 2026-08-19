<?php
declare(strict_types=1);

namespace TestApp\Model\MongoBehavior;

use Cake\Event\EventInterface;
use Cake\Utility\Text;
use Crustum\Mongo\ODM\Behavior;
use Crustum\Mongo\ODM\Query\SelectQuery;

class SluggableBehavior extends Behavior
{
    public function beforeFind(EventInterface $event, SelectQuery $query, array $options = []): SelectQuery
    {
        $query->where(['slug' => 'test']);

        return $query;
    }

    public function findNoSlug(SelectQuery $query, array $options = []): SelectQuery
    {
        $query->where(['slug IS' => null]);

        return $query;
    }

    public function slugify(string $value): string
    {
        return Text::slug($value);
    }
}
