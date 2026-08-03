<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Datasource\Paging;

use Cake\Datasource\Paging\Exception\PageOutOfBoundsException;
use Cake\Datasource\Paging\NumericPaginator;
use Cake\Datasource\Paging\PaginatorInterface;
use Cake\Datasource\QueryInterface;
use Cake\Datasource\RepositoryInterface;
use Cake\Datasource\ResultSetInterface;
use Crustum\Mongo\Datasource\Paging\MongoPaginator;
use PHPUnit\Framework\TestCase;

class MongoPaginatorTest extends TestCase
{
    /**
     * Builds the pagination doubles wired together.
     *
     * @param int $items count returned by the result set
     * @param int $total total count returned by the query
     * @return array{repository: \Cake\Datasource\RepositoryInterface, query: \Cake\Datasource\QueryInterface}
     */
    private function buildDoubles(int $items, int $total): array
    {
        $resultSet = $this->createStub(ResultSetInterface::class);
        $resultSet->method('count')->willReturn($items);

        $query = $this->createStub(QueryInterface::class);
        $query->method('count')->willReturn($total);
        $query->method('all')->willReturn($resultSet);
        $query->method('applyOptions')->willReturnSelf();

        $repository = $this->createStub(RepositoryInterface::class);
        $repository->method('getAlias')->willReturn('Articles');
        $repository->method('hasField')->willReturn(true);
        $repository->method('find')->willReturn($query);

        $query->method('getRepository')->willReturn($repository);

        return ['repository' => $repository, 'query' => $query];
    }

    public function testIsNumericPaginator(): void
    {
        $paginator = new MongoPaginator();

        $this->assertInstanceOf(NumericPaginator::class, $paginator);
        $this->assertInstanceOf(PaginatorInterface::class, $paginator);
    }

    public function testPaginateQuery(): void
    {
        $paginator = new MongoPaginator();
        $doubles = $this->buildDoubles(items: 10, total: 25);

        $paginated = $paginator->paginate($doubles['query'], ['page' => 1, 'limit' => 10]);

        $this->assertSame(10, $paginated->count());
        $this->assertSame(25, $paginated->totalCount());
        $this->assertSame(1, $paginated->currentPage());
        $this->assertSame(3, $paginated->pageCount());
        $this->assertFalse($paginated->hasPrevPage());
        $this->assertTrue($paginated->hasNextPage());
    }

    public function testPaginateRepository(): void
    {
        $paginator = new MongoPaginator();
        $doubles = $this->buildDoubles(items: 0, total: 0);

        $paginated = $paginator->paginate($doubles['repository'], ['page' => 1, 'limit' => 20]);

        $this->assertSame(0, $paginated->count());
        $this->assertSame(0, $paginated->totalCount());
    }

    public function testPaginateThrowsOnPageOutOfBounds(): void
    {
        $paginator = new MongoPaginator();
        $doubles = $this->buildDoubles(items: 10, total: 25);

        $this->expectException(PageOutOfBoundsException::class);

        $paginator->paginate($doubles['query'], ['page' => 99, 'limit' => 10]);
    }
}
