<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Query;

use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use TestApp\Model\Collection\UsersEmbeddedCollection;

/**
 * Tests the P2 server-side filtering of embedded documents via the standard
 * Cake `where()` surface: dotted paths, operators in keys, matching.
 */
class EmbeddedFilterTest extends TestCase
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
     * Resolves the Users collection with embedded associations registered.
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
     * Tests that a dotted embedded path filters parent documents server-side.
     *
     * @return void
     */
    public function testDottedPathFiltersParents(): void
    {
        $users = $this->users();
        $result = $users->find()
            ->where(['addresses.city' => 'NYC'])
            ->toArray();

        $this->assertCount(1, $result);
        $this->assertSame('mariano', $result[0]->get('username'));
    }

    /**
     * Tests that an operator in the key works on an embedded field.
     *
     * @return void
     */
    public function testOpInKeyOnEmbeddedField(): void
    {
        $users = $this->users();
        $result = $users->find()
            ->where(['addresses.zip >' => '20000'])
            ->toArray();

        $this->assertCount(2, $result);
    }

    /**
     * Tests that an array value on the embedded field matches via $elemMatch.
     *
     * @return void
     */
    public function testElemMatchOnEmbeddedArray(): void
    {
        $users = $this->users();
        $result = $users->find()
            ->where(['addresses' => ['city' => 'LA']])
            ->toArray();

        $this->assertCount(1, $result);
        $this->assertSame('mariano', $result[0]->get('username'));
    }

    /**
     * Tests matching('Addresses') returns only parents with embedded docs.
     *
     * @return void
     */
    public function testMatchingEmbedded(): void
    {
        $users = $this->users();
        $result = $users->find()
            ->matching('Addresses')
            ->toArray();

        $this->assertCount(2, $result);
    }

    /**
     * Tests that a closure expression filters on a dotted embedded field.
     *
     * @return void
     */
    public function testClosureExpressionOnEmbedded(): void
    {
        $users = $this->users();
        $result = $users->find()
            ->where(fn($exp) => $exp->eq('addresses.city', 'SF'))
            ->toArray();

        $this->assertCount(1, $result);
        $this->assertSame('nate', $result[0]->get('username'));
    }

    /**
     * Tests a dotted path into an EmbedOne field (deeper single-doc path).
     *
     * @return void
     */
    public function testDottedPathIntoEmbedOne(): void
    {
        $users = $this->users();
        $result = $users->find()
            ->where(['profile.city' => 'NYC'])
            ->toArray();

        $this->assertCount(1, $result);
        $this->assertSame('mariano', $result[0]->get('username'));
    }

    /**
     * Tests that notMatching('Addresses') returns parents without matches.
     *
     * @return void
     */
    public function testNotMatchingEmbedded(): void
    {
        $users = $this->users();
        $result = $users->find()
            ->notMatching('Addresses')
            ->toArray();

        $this->assertCount(0, $result);
    }
}
