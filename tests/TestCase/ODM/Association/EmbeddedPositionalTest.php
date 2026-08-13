<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Association;

use Cake\Validation\Validator;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use TestApp\Model\Collection\UsersEmbeddedCollection;
use TestApp\Model\Document\Address;

/**
 * Tests the P3-deferred embedded persistence features:
 * - child `$doc->save()` transparency (positional writes via the parent)
 * - deleteChild / clearEmbedded
 * - embedded-child validation
 */
class EmbeddedPositionalTest extends TestCase
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
     * Tests that saving an embedded child via the parent collection routes to
     * the positional write (append when the child has no _id).
     *
     * @return void
     */
    public function testSaveChildAppendsNew(): void
    {
        $users = $this->users();
        $parent = $users->get('000000000000000000000002');

        $address = new Address(['city' => 'Chicago', 'zip' => '60601']);
        $address->setEmbeddedParent($parent, $users->getAssociation('Addresses'));

        $saved = $users->save($address);
        $this->assertNotFalse($saved);

        $loaded = $users->get('000000000000000000000002');
        $this->assertCount(2, $loaded->get('addresses'));
        $this->assertSame('Chicago', $loaded->get('addresses')[1]['city']);
    }

    /**
     * Tests that saving an embedded child with an _id updates that element.
     *
     * @return void
     */
    public function testSaveChildUpdatesById(): void
    {
        $users = $this->users();
        $parent = $users->get('000000000000000000000001');

        $address = new Address([
            '_id' => '000000000000000000000001',
            'city' => 'Chicago',
            'zip' => '60601',
        ]);
        $address->setEmbeddedParent($parent, $users->getAssociation('Addresses'));

        $saved = $users->save($address);
        $this->assertNotFalse($saved);

        $loaded = $users->get('000000000000000000000001');
        $this->assertCount(2, $loaded->get('addresses'));
        $this->assertSame('Chicago', $loaded->get('addresses')[0]['city']);
    }

    /**
     * Tests that deleteChild removes an embedded child by _id.
     *
     * @return void
     */
    public function testDeleteChildPulls(): void
    {
        $users = $this->users();
        $parent = $users->get('000000000000000000000001');
        $association = $users->getAssociation('Addresses');

        $address = new Address(['_id' => '000000000000000000000002', 'city' => 'LA', 'zip' => '90001']);
        $this->assertTrue($association->deleteChild($parent, $address));

        $loaded = $users->get('000000000000000000000001');
        $this->assertCount(1, $loaded->get('addresses'));
        $this->assertSame('NYC', $loaded->get('addresses')[0]['city']);
    }

    /**
     * Tests that clearEmbedded empties the array (EmbedMany) / unsets (EmbedOne).
     *
     * @return void
     */
    public function testClearEmbedded(): void
    {
        $users = $this->users();
        $parent = $users->get('000000000000000000000001');
        $association = $users->getAssociation('Addresses');

        $this->assertTrue($association->clearEmbedded($parent));

        $loaded = $users->get('000000000000000000000001');
        $this->assertSame([], $loaded->get('addresses'));
    }

    /**
     * Tests that an embedded validator rejects invalid children on child save.
     *
     * @return void
     */
    public function testEmbeddedValidatorOnChildSave(): void
    {
        $users = $this->users();
        $association = $users->getAssociation('Addresses');
        $validator = new Validator();
        $validator->add('city', 'notBlank', ['rule' => 'notBlank']);
        $association->setEmbeddedValidator($validator);

        $parent = $users->get('000000000000000000000002');
        $address = new Address(['city' => '', 'zip' => '60601']);
        $address->setEmbeddedParent($parent, $association);

        $result = $users->save($address);
        $this->assertFalse($result);
        $this->assertNotEmpty($address->getErrors());
    }

    /**
     * Tests that the parent validator wires embedded children via addNestedMany.
     *
     * @return void
     */
    public function testEmbeddedValidationInParentValidator(): void
    {
        $users = $this->users();
        $association = $users->getAssociation('Addresses');
        $validator = new Validator();
        $validator->add('city', 'notBlank', ['rule' => 'notBlank']);
        $association->setEmbeddedValidator($validator);

        $parentValidator = $users->getValidator();
        $errors = $parentValidator->validate([
            'username' => 'x',
            'addresses' => [
                ['city' => '', 'zip' => '60601'],
            ],
        ]);

        $this->assertArrayHasKey('addresses', $errors);
        $this->assertNotEmpty($errors['addresses']);
    }
}
