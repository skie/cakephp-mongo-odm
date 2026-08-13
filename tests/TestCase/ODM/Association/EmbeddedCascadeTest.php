<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Association;

use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use TestApp\Model\Collection\UsersCollection;

/**
 * Tests the P4 embedded cascade: embedded data lives inside the parent, so
 * deleting the parent removes it structurally; `dependent` on an embedded
 * association is a no-op that must not break `delete()`.
 */
class EmbeddedCascadeTest extends TestCase
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
            'className' => UsersCollection::class,
        ]);
    }

    /**
     * Tests that deleting a parent removes its embedded data (structural).
     *
     * @return void
     */
    public function testDeleteParentRemovesEmbedded(): void
    {
        $users = $this->users();

        $user = $users->get('000000000000000000000001');
        $this->assertTrue($users->delete($user));

        $this->assertFalse($users->exists(['_id' => '000000000000000000000001']));
    }

    /**
     * Tests that an embedded association with dependent=true does not break
     * parent delete (embedded cascade is structural no-op).
     *
     * @return void
     */
    public function testDependentEmbeddedDoesNotBreakDelete(): void
    {
        $users = $this->users();
        $assoc = $users->getAssociation('Addresses');
        $assoc->setDependent(true);

        $user = $users->get('000000000000000000000002');
        $this->assertTrue($users->delete($user));

        $this->assertFalse($users->exists(['_id' => '000000000000000000000002']));
    }
}
