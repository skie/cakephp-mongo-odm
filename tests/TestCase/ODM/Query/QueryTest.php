<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Query;

use Cake\Datasource\ConnectionManager;
use Cake\Datasource\RepositoryInterface;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\ODM\Query\DeleteQuery;
use Crustum\Mongo\ODM\Query\InsertQuery;
use Crustum\Mongo\ODM\Query\QueryFactory;
use Crustum\Mongo\ODM\Query\SelectQuery;
use Crustum\Mongo\ODM\Query\UnhydratedSelectQuery;
use Crustum\Mongo\ODM\Query\UpdateQuery;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

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

    public function testInsertConvertsTypedValues(): void
    {
        $query = $this->typedQuery(InsertQuery::class);
        $query->values(['name' => 'Ada', 'created' => '2024-01-02 03:04:05']);

        $document = $query->getValues()[0];
        self::assertSame('Ada', $document['name']);
        self::assertInstanceOf(UTCDateTime::class, $document['created']);
    }

    public function testInsertBackfillsGeneratedId(): void
    {
        $document = new Document(['name' => 'Ada']);
        $query = $this->typedQuery(InsertQuery::class);
        $query->values($document);

        $id = $document->get('_id');
        self::assertInstanceOf(ObjectId::class, $id);
        self::assertSame($id, $query->getValues()[0]['_id']);
    }

    public function testInsertManyBackfillsGeneratedIds(): void
    {
        $first = new Document(['name' => 'Ada']);
        $second = new Document(['name' => 'Grace']);
        $query = $this->typedQuery(InsertQuery::class);
        $query->valuesMany([$first, $second]);

        $documents = $query->getValues();
        self::assertCount(2, $documents);
        self::assertInstanceOf(ObjectId::class, $documents[0]['_id']);
        self::assertInstanceOf(ObjectId::class, $documents[1]['_id']);
        self::assertSame($first->get('_id'), $documents[0]['_id']);
        self::assertSame($second->get('_id'), $documents[1]['_id']);
    }

    public function testUpdateSetConvertsTypedValues(): void
    {
        $query = $this->typedQuery(UpdateQuery::class);
        $query->set(['name' => 'Ada', 'created' => '2024-01-02 03:04:05']);

        $update = $query->getUpdate();
        self::assertSame('Ada', $update['$set']['name']);
        self::assertInstanceOf(UTCDateTime::class, $update['$set']['created']);
    }

    public function testUpdateSetAcceptsDocument(): void
    {
        $query = $this->typedQuery(UpdateQuery::class);
        $query->set(new Document(['name' => 'Ada', 'created' => '2024-01-02 03:04:05']));

        $update = $query->getUpdate();
        self::assertSame('Ada', $update['$set']['name']);
        self::assertInstanceOf(UTCDateTime::class, $update['$set']['created']);
    }

    public function testUnhydratedPreservesAllClauses(): void
    {
        $query = (new QueryFactory())->select($this->repository());
        $query
            ->where(['active' => true])
            ->select(['name'])
            ->orderBy(['name' => 'asc'])
            ->groupBy(['name'])
            ->having(['total' => 5])
            ->limit(10)
            ->skip(2)
            ->pipeline([['$set' => ['seen' => true]]]);

        $unhydrated = $query->unhydrated();
        $builder = $unhydrated->getBuilder();

        self::assertSame(['active' => true], $builder->getFilter());
        self::assertSame(['name' => 1], $builder->getProjection());
        self::assertSame(['name' => 1], $builder->getSort());
        self::assertSame(['name'], $builder->getGroup());
        self::assertSame(['total' => 5], $builder->getHaving());
        self::assertSame(10, $builder->getLimit());
        self::assertSame(2, $builder->getSkip());
        self::assertSame([['$set' => ['seen' => true]]], $builder->getPipeline());
    }

    /** @return \Cake\Datasource\RepositoryInterface */
    private function repository(): RepositoryInterface
    {
        return new RepositoryStub();
    }

    /**
     * Builds a query bound to a repository with typed schema fields and a driver.
     *
     * @template T of \Crustum\Mongo\ODM\Query\InsertQuery|\Crustum\Mongo\ODM\Query\UpdateQuery|\Crustum\Mongo\ODM\Query\DeleteQuery|\Crustum\Mongo\ODM\Query\SelectQuery|\Crustum\Mongo\ODM\Query\UnhydratedSelectQuery
     * @param class-string<T> $class The query class.
     * @return T
     */
    private function typedQuery(string $class): mixed
    {
        $repository = new RepositoryStub('users', 'Users', [
            '_id' => 'objectid',
            'created' => 'datetime',
            'name' => 'string',
        ], ConnectionManager::get('test_mongo'));

        return new $class(ConnectionManager::get('test_mongo'), 'users', $repository);
    }
}
