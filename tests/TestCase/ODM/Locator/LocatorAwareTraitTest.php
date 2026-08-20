<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Locator;

use Cake\Datasource\Locator\LocatorInterface;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Locator\LocatorAwareTrait;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use TestApp\Model\Collection\PaginatorPostsCollection;
use TestApp\Stub\LocatorAwareStub;
use UnexpectedValueException;

/**
 * LocatorAwareTrait test case
 *
 * @ported-from \Cake\Test\TestCase\ORM\Locator\LocatorAwareTraitTest
 */
#[CoversClass(LocatorAwareTrait::class)]
class LocatorAwareTraitTest extends TestCase
{
    /**
     * @var object|\Crustum\Mongo\ODM\Locator\LocatorAwareTrait
     */
    protected $subject;

    /**
     * setup
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new class {
            use LocatorAwareTrait;
        };
    }

    /**
     * Tests testGetCollectionLocator method
     */
    public function testGetCollectionLocator(): void
    {
        $collectionLocator = $this->subject->getCollectionLocator();
        $this->assertSame($this->getCollectionLocator(), $collectionLocator);
    }

    /**
     * Tests testSetCollectionLocator method
     */
    public function testSetCollectionLocator(): void
    {
        $newLocator = Mockery::mock(LocatorInterface::class);
        $this->subject->setCollectionLocator($newLocator);
        $subjectLocator = $this->subject->getCollectionLocator();
        $this->assertSame($newLocator, $subjectLocator);
    }

    public function testFetchCollection(): void
    {
        $stub = new LocatorAwareStub('Articles');

        $result = $stub->fetchCollection();
        $this->assertInstanceOf(BaseCollection::class, $result);

        $result = $stub->fetchCollection('Comments');
        $this->assertInstanceOf(BaseCollection::class, $result);

        $result = $stub->fetchCollection(PaginatorPostsCollection::class);
        $this->assertInstanceOf(PaginatorPostsCollection::class, $result);
        $this->assertSame('PaginatorPosts', $result->getAlias());
    }

    public function testFetchCollectionException(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'You must provide an `$alias` or set the `$defaultCollection` property to a non empty string.',
        );

        $stub = new LocatorAwareStub();
        $stub->fetchCollection();
    }

    public function testFetchCollectionExceptionForEmptyString(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'You must provide an `$alias` or set the `$defaultCollection` property to a non empty string.',
        );

        $stub = new LocatorAwareStub('');
        $stub->fetchCollection();
    }
}
