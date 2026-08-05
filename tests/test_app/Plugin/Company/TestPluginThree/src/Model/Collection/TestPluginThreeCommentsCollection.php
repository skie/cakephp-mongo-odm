<?php
declare(strict_types=1);

namespace Company\TestPluginThree\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;

/**
 * TestPluginThreeCommentsCollection
 */
class TestPluginThreeCommentsCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->setCollection('test_plugin_three_comments');
    }
}
