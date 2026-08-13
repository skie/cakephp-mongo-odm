<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Association;

use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use TestApp\Model\Collection\UsersEmbeddedCollection;
use TestApp\Model\Document\Address;

/**
 * Tests the P1 embedded-association hydration for `embedOne`: `contain('profile')`
 * hydrates a single Document (or null), zero extra queries.
 */
class EmbedOneTest extends TestCase
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
     * Tests that contain('profile') hydrates the single embedded doc.
     *
     * @return void
     */
    public function testContainHydratesSingleEmbedded(): void
    {
        $users = $this->getCollectionLocator()->get('Users', [
            'className' => UsersEmbeddedCollection::class,
        ]);
        $user = $users->find()
            ->contain(['Profile'])
            ->where(['_id' => '000000000000000000000001'])
            ->first();

        $this->assertInstanceOf(Document::class, $user);
        $this->assertInstanceOf(Address::class, $user->get('profile'));
        $this->assertSame('NYC', $user->get('profile')->get('city'));
        $this->assertSame($user, $user->get('profile')->getEmbeddedParent()['parent']);
    }

    /**
     * Tests that contain('profile') yields null when the field is null.
     *
     * @return void
     */
    public function testContainNullEmbedded(): void
    {
        $users = $this->getCollectionLocator()->get('Users', [
            'className' => UsersEmbeddedCollection::class,
        ]);
        $user = $users->find()
            ->contain(['Profile'])
            ->where(['_id' => '000000000000000000000002'])
            ->first();

        $this->assertNull($user->get('profile'));
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
        $association = $users->getAssociation('Profile');
        $this->assertSame('profile', $association->getProperty());

        $association->setProperty('mainProfile');
        $this->assertSame('mainProfile', $association->getProperty());
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
        $association = $users->getAssociation('Profile');

        $this->assertSame('profile', $association->getLocalKey());
        $association->setLocalKey('mainProfile');
        $this->assertSame('mainProfile', $association->getLocalKey());
    }
}
