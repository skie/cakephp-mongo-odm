<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Association;

use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use TestApp\Model\Collection\UsersCollection;
use TestApp\Model\Document\Address;

/**
 * Tests the P1 embedded-association hydration: `contain('addresses')`
 * returns hydrated Documents (with parent back-pointer), zero extra queries.
 */
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
            'className' => UsersCollection::class,
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
            'className' => UsersCollection::class,
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
            'className' => UsersCollection::class,
        ]);
        $user = $users->find()
            ->contain(['Addresses'])
            ->where(['_id' => '000000000000000000000002'])
            ->first();

        $this->assertCount(1, $user->get('addresses'));
        $this->assertSame('SF', $user->get('addresses')[0]->get('city'));
    }
}
