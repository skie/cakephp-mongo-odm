<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Bake;

use Crustum\Mongo\Bake\MongoAssociationFilter;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use TestApp\Model\Collection\ArticlesCollection;

/**
 * MongoAssociationFilterTest class
 *
 * Port of `Bake\Test\TestCase\Utility\Model\AssociationFilterTest`.
 */
#[CoversClass(MongoAssociationFilter::class)]
class MongoAssociationFilterTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Bake\MongoAssociationFilter
     */
    protected MongoAssociationFilter $associationFilter;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setAppNamespace('TestApp');
        $this->associationFilter = new MongoAssociationFilter();
    }

    /**
     * test extracting aliases and filtering the hasMany aliases correctly based on belongsToMany
     *
     * @return void
     */
    public function testFilterHasManyAssociationsAliases(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles', [
            'className' => ArticlesCollection::class,
        ]);
        $result = $this->associationFilter->filterHasManyAssociationsAliases($collection, ['ArticlesTags']);
        $this->assertSame([], $result);
    }

    /**
     * test filterAssociations drops junction HasMany and keeps BelongsToMany
     *
     * @return void
     */
    public function testFilterAssociations(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles', [
            'className' => ArticlesCollection::class,
        ]);
        $result = $this->associationFilter->filterAssociations($collection);

        $this->assertArrayHasKey('BelongsTo', $result);
        $this->assertArrayHasKey('Authors', $result['BelongsTo']);
        $this->assertArrayHasKey('BelongsToMany', $result);
        $this->assertArrayHasKey('Tags', $result['BelongsToMany']);
        $this->assertArrayNotHasKey('HasMany', $result);
    }
}
