<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\ODM\Marshaller;

final class MarshallerTest extends TestCase
{
    public function testOnePreservesIdAndAppliesFieldList(): void
    {
        $id = '507f1f77bcf86cd799439011';
        $collection = new MarshallerCollection();
        $entity = (new Marshaller($collection))->one([
            '_id' => $id,
            'name' => 'one',
            'ignored' => true,
        ], ['validate' => false, 'fieldList' => ['_id', 'name']]);

        $this->assertSame($id, $entity->getId());
        $this->assertArrayNotHasKey('id', $entity->toArray());
        $this->assertSame(['_id' => $id, 'name' => 'one'], $entity->toArray());
    }

    public function testManySkipsNonArrayRecords(): void
    {
        $entities = (new Marshaller(new MarshallerCollection()))->many([
            ['name' => 'one'],
            'invalid',
            ['name' => 'two'],
        ], ['validate' => false]);

        $this->assertCount(2, $entities);
    }

    public function testMergeAndMergeManyMatchByIdAndCreateNewRecords(): void
    {
        $marshaller = new Marshaller(new MarshallerCollection());
        $existing = $marshaller->one(['_id' => 'one', 'name' => 'old'], ['validate' => false]);
        $merged = $marshaller->mergeMany([$existing], [
            ['_id' => 'one', 'name' => 'new'],
            ['name' => 'new record'],
        ], ['validate' => false]);

        $this->assertSame($existing, $merged[0]);
        $this->assertSame('new', $merged[0]->get('name'));
        $this->assertCount(2, $merged);
        $this->assertTrue($merged[1]->isNew());
    }

    public function testValidationErrorsAreStoredAndInvalidFieldIsNotPatched(): void
    {
        $collection = new MarshallerCollection();
        $collection->validator = new class {
            /** @return array<string, array<int, string>> */
            public function validate(array $data, bool $isNew, array $context = []): array
            {
                return ['name' => ['required']];
            }
        };
        $entity = (new Marshaller($collection))->one(
            ['name' => 'not accepted', 'other' => 'kept'],
        );

        $this->assertSame(['required'], $entity->getErrors()['name']);
        $this->assertFalse($entity->has('name'));
        $this->assertSame('kept', $entity->get('other'));
    }

    public function testPatchableFieldsAreAppliedWithoutCake6EntityTrait(): void
    {
        $entity = (new Marshaller(new MarshallerCollection()))->one([
            'name' => 'kept',
            'secret' => 'discarded',
        ], [
            'validate' => false,
            'patchableFields' => ['*' => false, 'name' => true],
        ]);

        $this->assertSame('kept', $entity->get('name'));
        $this->assertFalse($entity->has('secret'));
    }

    public function testBeforeAndAfterMarshalEventsAreDispatched(): void
    {
        $collection = new MarshallerCollection();
        $entity = (new Marshaller($collection))->one(['name' => 'one'], ['validate' => false]);

        $this->assertSame(['Model.beforeMarshal', 'Model.afterMarshal'], $collection->events);
        $this->assertInstanceOf(Document::class, $entity);
    }

    public function testEmbeddedAssociationIsMarshaledAndIdListsAreAccepted(): void
    {
        $collection = new MarshallerCollection();
        $collection->association = new class {
            public string $associationType = 'oneToOne';

            public function getAlias(): string
            {
                return 'Profile';
            }

            public function getTarget(): MarshallerCollection
            {
                return new MarshallerCollection();
            }

            public function type(): string
            {
                return $this->associationType;
            }
        };
        $marshaller = new Marshaller($collection);
        $entity = $marshaller->one([
            'profile' => ['name' => 'embedded'],
        ], ['validate' => false, 'associated' => ['profile']]);

        $this->assertInstanceOf(Document::class, $entity->get('profile'));
        $this->assertSame('embedded', $entity->get('profile')->get('name'));

        $collection->association->associationType = 'oneToMany';
        $entity = $marshaller->one([
            'profiles' => ['_ids' => ['one', 'two']],
        ], ['validate' => false, 'associated' => ['profiles']]);
        $this->assertSame(['one', 'two'], $entity->get('profiles'));
    }
}
