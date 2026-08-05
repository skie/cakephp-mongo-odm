<?php
declare(strict_types=1);

namespace TestPlugin\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;

/**
 * TestPluginCommentsCollection
 */
class TestPluginCommentsCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->setCollection('test_plugin_comments');
    }
}
