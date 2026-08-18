<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\View\Helper;

use Bake\View\BakeView;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use Crustum\Mongo\View\Helper\MongoBakeHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use TestApp\Model\Collection\ArticlesCollection;

/**
 * MongoBakeHelperTest class
 *
 * Port of `Bake\Test\TestCase\View\Helper\BakeHelperTest` association extraction.
 */
#[CoversClass(MongoBakeHelper::class)]
class MongoBakeHelperTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\View\Helper\MongoBakeHelper
     */
    protected MongoBakeHelper $MongoBakeHelper;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setAppNamespace('TestApp');
        $this->MongoBakeHelper = new MongoBakeHelper(new BakeView(new ServerRequest(), new Response()));
    }

    /**
     * test extracting belongsTo
     *
     * @return void
     */
    public function testAliasExtractorBelongsTo(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles', [
            'className' => ArticlesCollection::class,
        ]);
        $result = $this->MongoBakeHelper->mongoAliasExtractor($collection, 'BelongsTo');
        $this->assertSame(['Authors'], $result);
    }

    /**
     * test extracting belongsToMany
     *
     * @return void
     */
    public function testAliasExtractorBelongsToMany(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles', [
            'className' => ArticlesCollection::class,
        ]);
        $result = $this->MongoBakeHelper->mongoAliasExtractor($collection, 'BelongsToMany');
        $this->assertSame(['Tags'], $result);
    }

    /**
     * test extracting aliases and filtering the hasMany aliases correctly based on belongsToMany
     *
     * @return void
     */
    public function testAliasExtractorFilteredHasMany(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles', [
            'className' => ArticlesCollection::class,
        ]);
        $result = $this->MongoBakeHelper->mongoAliasExtractor($collection, 'HasMany');
        $this->assertSame([], $result);
    }
}
