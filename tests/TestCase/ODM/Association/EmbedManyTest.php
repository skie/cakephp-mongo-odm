<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Association;

use Crustum\Mongo\ODM\Association\EmbedMany;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use TestApp\Model\Collection\UsersEmbeddedCollection;
use TestApp\Model\Document\Address;

/**
 * Tests the P1 embedded-association hydration: `contain('addresses')`
 * returns hydrated Documents (with parent back-pointer), zero extra queries.
 */
#[CoversClass(EmbedMany::class)]
class EmbedManyTest extends TestCase
{
    /**
     * Mongo fixtures.
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.Crustum/Mongo.UsersEmbedded',
    ];

    /**
     * Tests that contain('addresses') hydrates embedded docs as Documents.
     *
     * @return void
     */
    public function testContainHydratesEmbeddedDocuments(): void
    {
        $users = $this->getCollectionLocator()->get('Users', [
            'className' => UsersEmbeddedCollection::class,
        ]);
        $user = $users->find()
            ->contain(['Addresses'])
            ->where(['_id' => '000000000000000000000001'])
            ->first();

        $this->assertInstanceOf(Document::class, $user);
        $this->assertIsArray($user->get('addresses'));
        $this->assertCount(2, $user->get('addresses'));
        $this->assertContainsOnlyInstancesOf(Address::class, $user->get('addresses'));
        $this->assertSame('NYC', $user->get('addresses')[0]->get('city'));
        $this->assertSame('LA', $user->get('addresses')[1]->get('city'));
    }

    /**
     * Tests that each embedded child carries the parent back-pointer.
     *
     * @return void
     */
    public function testEmbeddedChildrenCarryParentMarker(): void
    {
        $users = $this->getCollectionLocator()->get('Users', [
            'className' => UsersEmbeddedCollection::class,
        ]);
        $user = $users->find()
            ->contain(['Addresses'])
            ->where(['_id' => '000000000000000000000001'])
            ->first();

        $first = $user->get('addresses')[0];
        $marker = $first->getEmbeddedParent();
        $this->assertNotNull($marker);
        $this->assertSame($user, $marker['parent']);
    }

    /**
     * Tests that a user without addresses gets an empty array.
     *
     * @return void
     */
    public function testContainEmptyEmbedded(): void
    {
        $users = $this->getCollectionLocator()->get('Users', [
            'className' => UsersEmbeddedCollection::class,
        ]);
        $user = $users->find()
            ->contain(['Addresses'])
            ->where(['_id' => '000000000000000000000002'])
            ->first();

        $this->assertCount(1, $user->get('addresses'));
        $this->assertSame('SF', $user->get('addresses')[0]->get('city'));
    }

    /**
     * Tests that a custom propertyName option is honoured.
     *
     * @return void
     */
    public function testPropertyOption(): void
    {
        $users = $this->getCollectionLocator()->get('Users', [
            'className' => UsersEmbeddedCollection::class,
        ]);
        $association = $users->getAssociation('Addresses');
        $this->assertSame('addresses', $association->getProperty());

        $association->setProperty('locations');
        $this->assertSame('locations', $association->getProperty());
    }

    /**
     * Tests that setLocalKey overrides the parent field holding the data.
     *
     * @return void
     */
    public function testSetLocalKey(): void
    {
        $users = $this->getCollectionLocator()->get('Users', [
            'className' => UsersEmbeddedCollection::class,
        ]);
        $association = $users->getAssociation('Addresses');

        $this->assertSame('addresses', $association->getLocalKey());
        $association->setLocalKey('locations');
        $this->assertSame('locations', $association->getLocalKey());
    }

    /**
     * Tests that a non-embed strategy is rejected.
     *
     * @return void
     */
    public function testStrategyFailure(): void
    {
        $users = $this->getCollectionLocator()->get('Users', [
            'className' => UsersEmbeddedCollection::class,
        ]);
        $association = $users->getAssociation('Addresses');

        $this->assertSame('embed', $association->getStrategy());
        $this->expectException(InvalidArgumentException::class);
        $association->setStrategy('select');
    }

    /**
     * Tests that eagerLoader() hydrates via the association directly.
     *
     * @return void
     */
    public function testEagerLoader(): void
    {
        $users = $this->getCollectionLocator()->get('Users', [
            'className' => UsersEmbeddedCollection::class,
        ]);
        $association = $users->getAssociation('Addresses');

        $loader = $association->eagerLoader([]);
        $user = $users->newEmptyDocument();
        $user->set('addresses', [
            ['city' => 'NYC', 'zip' => '10001'],
            ['city' => 'LA', 'zip' => '90001'],
        ]);

        $result = iterator_to_array($loader([$user]));
        $this->assertSame($user, $result[0]);
        $this->assertContainsOnlyInstancesOf(Address::class, $user->get('addresses'));
    }
}
