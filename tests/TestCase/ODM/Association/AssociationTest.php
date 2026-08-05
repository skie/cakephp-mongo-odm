<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Association;

use Crustum\Mongo\ODM\Association;
use Crustum\Mongo\ODM\Association\EmbedMany;
use Crustum\Mongo\ODM\Association\EmbedOne;
use Crustum\Mongo\ODM\Association\HasMany;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use InvalidArgumentException;

class AssociationTest extends TestCase
{
    public function testEmbeddedOneHydratesWithoutAQuery(): void
    {
        $association = new EmbedOne('Profile', new BaseCollection(), ['documentClass' => Document::class]);
        $entity = new Document(['profile' => ['name' => 'Ada']]);
        $result = ($association->eagerLoader([]))([$entity]);

        $this->assertSame('Ada', $result[0]->get('profile')->get('name'));
        $this->assertSame(Association::STRATEGY_EMBED, $association->getStrategy());
    }

    public function testEmbeddedManyHydratesListAndKeepsEmptyValue(): void
    {
        $association = new EmbedMany('Addresses', new BaseCollection(), ['documentClass' => Document::class]);
        $entity = new Document(['addresses' => [['city' => 'Paris'], ['city' => 'Tokyo']]]);
        $result = ($association->eagerLoader([]))([$entity]);

        $this->assertCount(2, $result[0]->get('addresses'));
        $this->assertSame('Tokyo', $result[0]->get('addresses')[1]->get('city'));
    }

    public function testReferenceStrategiesAreValidated(): void
    {
        $association = new HasMany('Orders', new BaseCollection());

        $this->assertSame(Association::STRATEGY_SELECT, $association->getStrategy());
        $association->setStrategy(Association::STRATEGY_LOOKUP);
        $this->assertSame(Association::STRATEGY_LOOKUP, $association->getStrategy());
        $this->expectException(InvalidArgumentException::class);
        $association->setStrategy(Association::STRATEGY_EMBED);
    }
}
