<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Rule;

use Cake\Datasource\RepositoryInterface;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\ODM\RulesChecker;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;

final class RulesCheckerTest extends TestCase
{
    public function testUniqueNewAndCompoundValues(): void
    {
        $repository = $this->createMock(RepositoryInterface::class);
        $repository->expects($this->once())
            ->method('exists')
            ->with(['tenant_id' => 't1', 'slug' => 'same'])
            ->willReturn(false);

        $checker = new RulesChecker(['repository' => $repository]);
        $checker->add($checker->isUnique(['tenant_id', 'slug']), 'unique');
        $entity = new Document(['tenant_id' => 't1', 'slug' => 'same']);

        self::assertTrue($checker->checkCreate($entity));
    }

    public function testUniqueUpdateExcludesCurrentDocument(): void
    {
        $repository = $this->createMock(RepositoryInterface::class);
        $repository->expects($this->once())
            ->method('exists')
            ->with([
                'email' => 'user@example.com',
                '_id' => ['$ne' => $entityId = '507f1f77bcf86cd799439011'],
            ])
            ->willReturn(false);

        $checker = new RulesChecker(['repository' => $repository]);
        $checker->add($checker->isUnique(['email']), 'unique');
        $entity = new Document(['_id' => $entityId, 'email' => 'user@example.com']);
        $entity->setNew(false);

        self::assertTrue($checker->checkUpdate($entity));
    }

    public function testUniqueFailurePropagatesToEntity(): void
    {
        $repository = $this->createMock(RepositoryInterface::class);
        $repository->method('exists')->willReturn(true);

        $checker = new RulesChecker(['repository' => $repository]);
        $checker->add($checker->isUnique(['email'], 'Email is already used'), 'unique');
        $entity = new Document(['email' => 'user@example.com']);

        self::assertFalse($checker->checkCreate($entity));
        self::assertSame(['unique' => 'Email is already used'], $entity->getError('email'));
    }

    public function testExistsInAndNullable(): void
    {
        $repository = $this->createMock(RepositoryInterface::class);
        $repository->expects($this->once())
            ->method('exists')
            ->with(['_id' => '507f1f77bcf86cd799439011'])
            ->willReturn(true);

        $checker = new RulesChecker();
        $checker->add($checker->existsIn('author_id', $repository), 'exists');
        self::assertTrue($checker->checkCreate(new Document(['author_id' => '507f1f77bcf86cd799439011'])));

        $nullable = new RulesChecker();
        $nullable->add($nullable->existsInNullable('author_id', $repository), 'exists');
        self::assertTrue($nullable->checkCreate(new Document()));
    }

    public function testValidCountUsesMongoEmbeddedArraySize(): void
    {
        $checker = new RulesChecker();
        $entity = new Document(['items' => ['one', 'two']]);

        $checker->add($checker->validCount('items', 2, '=='), 'count');
        self::assertTrue($checker->checkCreate($entity));

        $invalid = new RulesChecker();
        $invalid->add($invalid->validCount('items', 3, '=='), 'count');
        self::assertFalse($invalid->checkCreate($entity));
    }
}
