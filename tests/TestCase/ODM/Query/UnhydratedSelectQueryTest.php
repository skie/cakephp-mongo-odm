<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Query;

use Cake\Core\Exception\CakeException;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\Exception\RecordNotFoundException;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Query\QueryFactory;
use Crustum\Mongo\ODM\Query\SelectQuery;
use Crustum\Mongo\ODM\Query\UnhydratedSelectQuery;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the type-safe non-hydrated query path: BaseCollection::unhydratedFind() and
 * the UnhydratedSelectQuery class it returns.
 */
#[CoversClass(UnhydratedSelectQuery::class)]
class UnhydratedSelectQueryTest extends TestCase
{
    /**
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.Crustum/Mongo.Articles',
        'plugin.Crustum/Mongo.Authors',
        'plugin.Crustum/Mongo.CounterCacheUsers',
    ];

    /**
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected BaseCollection $articles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->articles = $this->getCollectionLocator()->get('Articles');
    }

    /**
     * unhydratedFind() is the type-safe entry point for non-hydrated reads.
     * It returns an UnhydratedSelectQuery (not a plain SelectQuery), so consumers know
     * up-front that results will be arrays.
     */
    public function testUnhydratedFindReturnsUnhydratedSelectQuery(): void
    {
        $query = $this->articles->unhydratedFind();

        $this->assertInstanceOf(UnhydratedSelectQuery::class, $query);
        $this->assertInstanceOf(SelectQuery::class, $query);
        $this->assertFalse($query->isHydrationEnabled());
    }

    /**
     * first() on an UnhydratedSelectQuery resolves to an array (or null when empty),
     * matching the runtime hydration setting locked in by the constructor.
     */
    public function testFirstReturnsArrayOrNull(): void
    {
        $row = $this->articles->unhydratedFind()->where(['_id' => '000000000000000000000001'])->first();

        $this->assertIsArray($row);
        $this->assertSame('000000000000000000000001', $row['_id']);

        $missing = $this->articles->unhydratedFind()->where(['_id' => '000000000000000000099999'])->first();
        $this->assertNull($missing);
    }

    /**
     * firstOrFail() returns an array on success and throws the same
     * RecordNotFoundException as the entity path on miss.
     */
    public function testFirstOrFailReturnsArrayOrThrows(): void
    {
        $row = $this->articles->unhydratedFind()->where(['_id' => '000000000000000000000001'])->firstOrFail();
        $this->assertIsArray($row);
        $this->assertSame('000000000000000000000001', $row['_id']);

        $this->expectException(RecordNotFoundException::class);
        $this->articles->unhydratedFind()->where(['_id' => '000000000000000000099999'])->firstOrFail();
    }

    /**
     * all() and iteration both produce array rows — confirms the locked
     * `_hydrate=false` flag flows through the result-set decoration.
     */
    public function testAllAndIterationProduceArrays(): void
    {
        $resultSet = $this->articles->unhydratedFind()->orderBy(['_id' => 'ASC'])->all();
        $rows = $resultSet->toArray();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertIsArray($row);
            $this->assertArrayHasKey('_id', $row);
        }
    }

    /**
     * UnhydratedSelectQuery is fully substitutable for SelectQuery: the ORM
     * may re-enable hydration on it (the eager loader does exactly this when
     * normalizing association queries). It must not fight that — contain()
     * with a hydrated parent has to keep working.
     */
    public function testInteroperatesWithContainEagerLoading(): void
    {
        $this->articles->belongsTo('Authors');

        $rows = $this->articles
            ->unhydratedFind()
            ->contain('Authors')
            ->where(['Articles._id' => '000000000000000000000001'])
            ->toArray();

        $this->assertNotEmpty($rows);
        $this->assertIsArray($rows[0]);
        $this->assertArrayHasKey('author', $rows[0]);
        $this->assertIsArray($rows[0]['author']);
    }

    /**
     * Re-enabling hydration is allowed (it just flips the flag, like any
     * SelectQuery) — the value of this class is the static type, not a
     * runtime lock.
     */
    public function testEnableHydrationIsNotLocked(): void
    {
        $query = $this->articles->unhydratedFind();
        $this->assertFalse($query->isHydrationEnabled());

        $query->enableHydration(true);
        $this->assertTrue($query->isHydrationEnabled());
    }

    /**
     * Custom finders called via unhydratedFind() receive the UnhydratedSelectQuery itself,
     * so finder-applied builder methods (where/orderBy/contain/...) flow
     * through without losing the array shape.
     */
    public function testFinderReceivesUnhydratedSelectQuery(): void
    {
        $users = $this->getCollectionLocator()->get('CounterCacheUsers');
        $query = $users->unhydratedFind('all')->where(['post_count >' => 0]);

        $this->assertInstanceOf(UnhydratedSelectQuery::class, $query);
        $rows = $query->orderBy(['_id' => 'ASC'])->limit(2)->toArray();

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertIsArray($row);
        }
    }

    /**
     * unhydratedFind() must build through the injected QueryFactory (like
     * find() does), not by instantiating UnhydratedSelectQuery directly —
     * otherwise apps with a custom QueryFactory get divergent behavior
     * between find() and unhydratedFind().
     */
    public function testHonorsInjectedQueryFactory(): void
    {
        $factory = new class extends QueryFactory {
            public function unhydratedSelect(BaseCollection $collection): UnhydratedSelectQuery
            {
                return new class ($collection) extends UnhydratedSelectQuery {
                };
            }
        };
        $collection = $this->getCollectionLocator()->get('ArticlesCustomFactory', [
            'className' => BaseCollection::class,
            'collection' => 'articles',
            'queryFactory' => $factory,
        ]);

        $query = $collection->unhydratedFind();

        $this->assertInstanceOf(UnhydratedSelectQuery::class, $query);
        $this->assertNotSame(
            UnhydratedSelectQuery::class,
            $query::class,
            'unhydratedFind() bypassed the injected QueryFactory.',
        );
    }

    /**
     * A finder that discards the passed query and returns a freshly built
     * one cannot preserve the non-hydrating contract. unhydratedFind() must
     * fail loudly with a clear message naming the finder, not return a
     * silently hydrated query or hit a cryptic TypeError.
     */
    public function testFinderReturningFreshQueryThrows(): void
    {
        $collection = new class (['alias' => 'Articles', 'collection' => 'articles', 'connection' => ConnectionManager::get('test_mongo')]) extends BaseCollection {
            /**
             * @param \Crustum\Mongo\ODM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query The passed query.
             * @return \Crustum\Mongo\ODM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array>
             */
            public function findFresh(SelectQuery $query): SelectQuery
            {
                return $this->find();
            }
        };

        $this->expectException(CakeException::class);
        $this->expectExceptionMessage('`fresh` finder must return the query it was given');
        $collection->unhydratedFind('fresh');
    }
}
