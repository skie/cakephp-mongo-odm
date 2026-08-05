<?php
declare(strict_types=1);

namespace TestApp\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Query\SelectQuery;

class GreedyCommentsCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->setCollection('comments');
        $this->setAlias('Comments');
    }

    public function find(string $type = 'all', mixed ...$args): SelectQuery
    {
        $options = &$args[0];
        if (!is_array($options)) {
            $options = [];
        }
        if (empty($options['conditions'])) {
            $options['conditions'] = [];
        }
        $options['conditions'] = array_merge($options['conditions'], ['Comments.published' => 'Y']);

        return parent::find($type, ...$options);
    }
}
