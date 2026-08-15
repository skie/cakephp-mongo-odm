<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Association;

use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use TestApp\Model\Collection\UsersEmbeddedCollection;
use TestApp\Model\Document\Address;

/**
 * Tests the P3 embedded persistence: embedded children are part of the parent
 * document and are saved whole-field via `Collection::save()`.
 */
class EmbedManySaveTest extends TestCase
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
     * Resolves the Users collection.
     *
     * @return \Crustum\Mongo\ODM\BaseCollection
     */
    protected function users(): BaseCollection
    {
        return $this->getCollectionLocator()->get('Users', [
            'className' => UsersEmbeddedCollection::class,
        ]);
    }

    /**
     * Tests that saving a new parent persists embedded children whole-field.
     *
     * @return void
     */
    public function testSaveNewParentPersistsEmbedded(): void
    {
        $users = $this->users();

        $user = $users->newEmptyDocument();
        $user->set('username', 'newuser');
        $user->set('addresses', [
            new Address(['city' => 'Chicago', 'zip' => '60601']),
            new Address(['city' => 'Boston', 'zip' => '02108']),
        ]);

        $saved = $users->save($user);
        $this->assertNotFalse($saved);

        $loaded = $users->find()->where(['username' => 'newuser'])->first();
        $this->assertCount(2, $loaded->get('addresses'));
        $this->assertSame('Chicago', $loaded->get('addresses')[0]['city']);
    }

    /**
     * Tests that updating an existing parent overwrites the embedded array.
     *
     * @return void
     */
    public function testUpdateParentReplacesEmbedded(): void
    {
        $users = $this->users();

        $user = $users->get('000000000000000000000001');
        $user->set('addresses', [
            ['city' => 'Chicago', 'zip' => '60601'],
        ]);

        $saved = $users->save($user);
        $this->assertNotFalse($saved);

        $loaded = $users->get('000000000000000000000001');
        $this->assertCount(1, $loaded->get('addresses'));
        $this->assertSame('Chicago', $loaded->get('addresses')[0]['city']);
    }

    /**
     * Tests that removing the embedded field clears it.
     *
     * @return void
     */
    public function testSaveClearsEmbedded(): void
    {
        $users = $this->users();

        $user = $users->get('000000000000000000000001');
        $user->set('addresses', []);
        $user->set('profile');

        $users->save($user);

        $loaded = $users->get('000000000000000000000001');
        $this->assertSame([], $loaded->get('addresses'));
        $this->assertNull($loaded->get('profile'));
    }
}
