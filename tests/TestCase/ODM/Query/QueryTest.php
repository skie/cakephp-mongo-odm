<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Query;

use Cake\Datasource\RepositoryInterface;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\ODM\Query\DeleteQuery;
use Crustum\Mongo\ODM\Query\InsertQuery;
use Crustum\Mongo\ODM\Query\QueryFactory;
use Crustum\Mongo\ODM\Query\SelectQuery;
use Crustum\Mongo\ODM\Query\UnhydratedSelectQuery;
use Crustum\Mongo\ODM\Query\UpdateQuery;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;

final class QueryTest extends TestCase
{
    public function testFactoryBindsRepositoryAndCollection(): void
    {
        $repository = $this->repository();
        $factory = new QueryFactory();

        $select = $factory->select($repository);
        $insert = $factory->insert($repository);
        $update = $factory->update($repository);
        $delete = $factory->delete($repository);

        self::assertInstanceOf(SelectQuery::class, $select);
        self::assertInstanceOf(InsertQuery::class, $insert);
        self::assertInstanceOf(UpdateQuery::class, $update);
        self::assertInstanceOf(DeleteQuery::class, $delete);
        self::assertSame('users', $select->getCollection());
        self::assertSame($repository, $select->getRepository());
    }

    public function testUnhydratedFactoryDisablesHydration(): void
    {
        $query = (new QueryFactory())->unhydratedSelect($this->repository());

        self::assertInstanceOf(UnhydratedSelectQuery::class, $query);
        self::assertFalse($query->isHydrationEnabled());
    }

    public function testFinderAndQueryOptionsAreComposable(): void
    {
        $query = (new QueryFactory())->select($this->repository());
        $query
            ->where(['active' => true])
            ->orderByDesc('created')
            ->limit(10)
            ->skip(2)
            ->pipeline([['$set' => ['seen' => true]]])
            ->options(['comment' => 'odm']);

        self::assertSame(['active' => true], $query->getBuilder()->getFilter());
        $compiled = $query->compile();
        $skip = array_find($compiled['pipeline'], static fn(array $stage): bool => array_key_exists('$skip', $stage));
        $limit = array_find($compiled['pipeline'], static fn(array $stage): bool => array_key_exists('$limit', $stage));
        self::assertIsArray($skip);
        self::assertIsArray($limit);
        self::assertSame(2, $skip['$skip']);
        self::assertSame(10, $limit['$limit']);
        self::assertSame('odm', $compiled['options']['comment']);
    }

    public function testInsertConvertsDocumentsToArrays(): void
    {
        $query = (new QueryFactory())->insert($this->repository());
        $query->values(new Document(['name' => 'Ada']));

        self::assertSame('Ada', $query->getValues()[0]['name']);
    }

    /** @return \Cake\Datasource\RepositoryInterface */
    private function repository(): RepositoryInterface
    {
        $repository = $this->createStub(RepositoryInterface::class);
        $repository->method('getAlias')->willReturn('users');
        $repository->method('getRegistryAlias')->willReturn('Users');

        return $repository;
    }
}
