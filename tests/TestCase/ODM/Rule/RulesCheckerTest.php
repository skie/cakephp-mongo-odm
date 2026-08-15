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

        $document = new Document(['tenant_id' => 't1', 'slug' => 'same']);

        self::assertTrue($checker->checkCreate($document));
    }

    public function testUniqueUpdateExcludesCurrentDocument(): void
    {
        $repository = $this->createMock(RepositoryInterface::class);
        $repository->expects($this->once())
            ->method('exists')
            ->with([
                'email' => 'user@example.com',
                '_id' => ['$ne' => $documentId = '507f1f77bcf86cd799439011'],
            ])
            ->willReturn(false);

        $checker = new RulesChecker(['repository' => $repository]);
        $checker->add($checker->isUnique(['email']), 'unique');

        $document = new Document(['_id' => $documentId, 'email' => 'user@example.com']);
        $document->setNew(false);

        self::assertTrue($checker->checkUpdate($document));
    }

    public function testUniqueFailurePropagatesToEntity(): void
    {
        $repository = $this->createStub(RepositoryInterface::class);
        $repository->method('exists')->willReturn(true);

        $checker = new RulesChecker(['repository' => $repository]);
        $checker->add($checker->isUnique(['email'], 'Email is already used'), 'unique');

        $document = new Document(['email' => 'user@example.com']);

        self::assertFalse($checker->checkCreate($document));
        self::assertSame(['unique' => 'Email is already used'], $document->getError('email'));
    }

    public function testExistsInAndNullable(): void
    {
        $repository = $this->createMock(RepositoryInterface::class);
        $repository->method('aliasField')->willReturnCallback(fn(string $field): string => $field);
        $repository->expects($this->once())
            ->method('exists')
            ->with(['_id IS' => '507f1f77bcf86cd799439011'])
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
        $document = new Document(['items' => ['one', 'two']]);

        $checker->add($checker->validCount('items', 2, '=='), 'count');
        self::assertTrue($checker->checkCreate($document));

        $invalid = new RulesChecker();
        $invalid->add($invalid->validCount('items', 3, '=='), 'count');
        self::assertFalse($invalid->checkCreate($document));
    }
}
