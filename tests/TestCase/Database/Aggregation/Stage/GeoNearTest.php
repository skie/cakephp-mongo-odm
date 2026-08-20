<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\GeoNear;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for GeoNear aggregation stage
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Tests\Aggregation\Stage\GeoNearTest
 */
#[CoversClass(GeoNear::class)]
class GeoNearTest extends TestCase
{
    /**
     * Test basic geoNear stage
     */
    public function testBasicGeoNear(): void
    {
        $builder = new AggregationBuilder();
        $builder->geoNear([40.7128, -74.0060], 'distance');

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$geoNear', $pipeline[0]);

        $geoNearExpr = $pipeline[0]['$geoNear'];
        $this->assertEquals([40.7128, -74.0060], $geoNearExpr['near']);
        $this->assertEquals('distance', $geoNearExpr['distanceField']);
    }

    /**
     * Test geoNear with spherical
     */
    public function testGeoNearWithSpherical(): void
    {
        $builder = new AggregationBuilder();
        $builder->geoNear([40.7128, -74.0060], 'distance')
            ->spherical(true);

        $pipeline = $builder->getPipeline();
        $geoNearExpr = $pipeline[0]['$geoNear'];
        $this->assertTrue($geoNearExpr['spherical']);
    }

    /**
     * Test geoNear with maxDistance
     */
    public function testGeoNearWithMaxDistance(): void
    {
        $builder = new AggregationBuilder();
        $builder->geoNear([40.7128, -74.0060], 'distance')
            ->maxDistance(5000);

        $pipeline = $builder->getPipeline();
        $geoNearExpr = $pipeline[0]['$geoNear'];
        $this->assertEquals(5000, $geoNearExpr['maxDistance']);
    }

    /**
     * Test geoNear with query
     */
    public function testGeoNearWithQuery(): void
    {
        $builder = new AggregationBuilder();
        $builder->geoNear([40.7128, -74.0060], 'distance')
            ->query(['status' => 'active']);

        $pipeline = $builder->getPipeline();
        $geoNearExpr = $pipeline[0]['$geoNear'];
        $this->assertArrayHasKey('query', $geoNearExpr);
        $this->assertEquals(['status' => 'active'], $geoNearExpr['query']);
    }

    /**
     * Test geoNear stage directly
     */
    public function testGeoNearStageDirect(): void
    {
        $builder = new AggregationBuilder();
        $stage = new GeoNear($builder, [40.7128, -74.0060], 'distance');
        $stage->spherical(true)
            ->maxDistance(5000)
            ->minDistance(100);

        $expression = $stage->getExpression();
        $this->assertArrayHasKey('$geoNear', $expression);
        $geoNearExpr = $expression['$geoNear'];
        $this->assertTrue($geoNearExpr['spherical']);
        $this->assertEquals(5000, $geoNearExpr['maxDistance']);
        $this->assertEquals(100, $geoNearExpr['minDistance']);
    }
}
