<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use Crustum\Mongo\ODM\Association\EmbedOne;
use Crustum\Mongo\ODM\AssociationCollection;
use PHPUnit\Framework\TestCase;

class AssociationCollectionTest extends TestCase
{
    public function testCollectionIndexesByAliasAndProperty(): void
    {
        $collection = new AssociationCollection();
        $association = new EmbedOne('Profile');
        $collection->add('Profile', $association);

        $this->assertTrue($collection->has('Profile'));
        $this->assertSame($association, $collection->getByProperty('profile'));
        $this->assertSame([$association], $collection->getByType('EmbedOne'));
    }
}
