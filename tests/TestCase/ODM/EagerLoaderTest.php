<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use Cake\Database\Expression\IdentifierExpression;
use Cake\Database\Expression\QueryExpression;
use Cake\Database\TypeMap;
use Cake\Datasource\ConnectionManager;
use Crustum\Mongo\ODM\EagerLoader;
use Crustum\Mongo\ODM\Query\SelectQuery;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests EagerLoader
 */
#[CoversClass(EagerLoader::class)]
class EagerLoaderTest extends TestCase
{
    /**
     * @var \Cake\Datasource\ConnectionInterface
     */
    protected $connection;

    /**
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected $collection;

    /**
     * @var \Cake\Database\TypeMap
     */
    protected $clientsTypeMap;

    /**
     * @var \Cake\Database\TypeMap
     */
    protected $ordersTypeMap;

    /**
     * @var \Cake\Database\TypeMap
     */
    protected $orderTypesTypeMap;

    /**
     * @var \Cake\Database\TypeMap
     */
    protected $stuffTypeMap;

    /**
     * @var \Cake\Database\TypeMap
     */
    protected $stuffTypesTypeMap;

    /**
     * @var \Cake\Database\TypeMap
     */
    protected $companiesTypeMap;

    /**
     * @var \Cake\Database\TypeMap
     */
    protected $categoriesTypeMap;

    /**
     * setUp method
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = ConnectionManager::get('test_mongo');
        $schema = [
            'id' => ['type' => 'integer'],
            '_constraints' => [
                'primary' => ['type' => 'primary', 'columns' => ['id']],
            ],
        ];
        $schema1 = [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'phone' => ['type' => 'string'],
            '_constraints' => [
                'primary' => ['type' => 'primary', 'columns' => ['id']],
            ],
        ];
        $schema2 = [
            'id' => ['type' => 'integer'],
            'total' => ['type' => 'string'],
            'placed' => ['type' => 'datetime'],
            '_constraints' => [
                'primary' => ['type' => 'primary', 'columns' => ['id']],
            ],
        ];
        $this->collection = $this->getCollectionLocator()->get('foo', ['schema' => $schema]);
        $collection = $this->collection;

        $clients = $this->getCollectionLocator()->get('clients', ['schema' => $schema1]);
        $orders = $this->getCollectionLocator()->get('orders', ['schema' => $schema2]);
        $companies = $this->getCollectionLocator()->get('companies', ['schema' => $schema, 'collection' => 'organizations']);
        $this->getCollectionLocator()->get('orderTypes', ['schema' => $schema]);
        $stuff = $this->getCollectionLocator()->get('stuff', ['schema' => $schema, 'collection' => 'things']);
        $this->getCollectionLocator()->get('stuffTypes', ['schema' => $schema]);
        $this->getCollectionLocator()->get('categories', ['schema' => $schema]);

        $collection->belongsTo('clients');
        $clients->hasOne('orders');
        $clients->belongsTo('companies');

        $orders->belongsTo('orderTypes');
        $orders->hasOne('stuff');

        $stuff->belongsTo('stuffTypes');
        $companies->belongsTo('categories');

        $this->clientsTypeMap = new TypeMap([
            'clients.id' => 'integer',
            'id' => 'integer',
            'clients.name' => 'string',
            'name' => 'string',
            'clients.phone' => 'string',
            'phone' => 'string',
            'clients__id' => 'integer',
            'clients__name' => 'string',
            'clients__phone' => 'string',
        ]);
        $this->ordersTypeMap = new TypeMap([
            'orders.id' => 'integer',
            'id' => 'integer',
            'orders.total' => 'string',
            'total' => 'string',
            'orders.placed' => 'datetime',
            'placed' => 'datetime',
            'orders__id' => 'integer',
            'orders__total' => 'string',
            'orders__placed' => 'datetime',
        ]);
        $this->orderTypesTypeMap = new TypeMap([
            'orderTypes.id' => 'integer',
            'id' => 'integer',
            'orderTypes__id' => 'integer',
        ]);
        $this->stuffTypeMap = new TypeMap([
            'stuff.id' => 'integer',
            'id' => 'integer',
            'stuff__id' => 'integer',
        ]);
        $this->stuffTypesTypeMap = new TypeMap([
            'stuffTypes.id' => 'integer',
            'id' => 'integer',
            'stuffTypes__id' => 'integer',
        ]);
        $this->companiesTypeMap = new TypeMap([
            'companies.id' => 'integer',
            'id' => 'integer',
            'companies__id' => 'integer',
        ]);
        $this->categoriesTypeMap = new TypeMap([
            'categories.id' => 'integer',
            'id' => 'integer',
            'categories__id' => 'integer',
        ]);
    }

    /**
     * Tests that fully defined belongsTo and hasOne relationships are joined correctly
     */
    public function testContainToJoinsOneLevel(): void
    {
        $this->markTestSkipped('ODM has no SQL joins; join/select-clause white-box is SQL-only (F25).');
        $contains = [
            'clients' => [
                'orders' => [
                    'orderTypes',
                    'stuff' => ['stuffTypes'],
                ],
                'companies' => [
                    'foreignKey' => 'organization_id',
                    'categories',
                ],
            ],
        ];

        $query = Mockery::mock(SelectQuery::class . '[join]', [$this->collection]);
        $query->setTypeMap($this->clientsTypeMap);

        $expectedTables = [
            ['clients' => [
                'collection' => 'clients',
                'type' => 'LEFT',
                'conditions' => new QueryExpression([
                    ['clients.id' => new IdentifierExpression('foo.client_id')],
                ], new TypeMap($this->clientsTypeMap->getDefaults())),
            ]],
            ['orders' => [
                'collection' => 'orders',
                'type' => 'LEFT',
                'conditions' => new QueryExpression([
                    ['clients.id' => new IdentifierExpression('orders.client_id')],
                ], $this->ordersTypeMap),
            ]],
            ['orderTypes' => [
                'collection' => 'order_types',
                'type' => 'LEFT',
                'conditions' => new QueryExpression([
                    ['orderTypes.id' => new IdentifierExpression('orders.order_type_id')],
                ], $this->orderTypesTypeMap),
            ]],
            ['stuff' => [
                'collection' => 'things',
                'type' => 'LEFT',
                'conditions' => new QueryExpression([
                    ['orders.id' => new IdentifierExpression('stuff.order_id')],
                ], $this->stuffTypeMap),
            ]],
            ['stuffTypes' => [
                'collection' => 'stuff_types',
                'type' => 'LEFT',
                'conditions' => new QueryExpression([
                    ['stuffTypes.id' => new IdentifierExpression('stuff.stuff_type_id')],
                ], $this->stuffTypesTypeMap),
            ]],
            ['companies' => [
                'collection' => 'organizations',
                'type' => 'LEFT',
                'conditions' => new QueryExpression([
                    ['companies.id' => new IdentifierExpression('clients.organization_id')],
                ], $this->companiesTypeMap),
            ]],
            ['categories' => [
                'collection' => 'categories',
                'type' => 'LEFT',
                'conditions' => new QueryExpression([
                    ['categories.id' => new IdentifierExpression('companies.category_id')],
                ], $this->categoriesTypeMap),
            ]],
        ];

        foreach ($expectedTables as $collection) {
            $query->shouldReceive('join')
                ->with($collection)
                ->andReturn($query)
                ->once();
        }

        $loader = new EagerLoader();
        $loader->contain($contains);
        $query->select('foo.id')->setEagerLoader($loader)->sql();
    }

    /**
     * Tests setting containments using dot notation, additionally proves that options
     * are not overwritten when combining dot notation and array notation
     */
    public function testContainDotNotation(): void
    {
        $loader = new EagerLoader();
        $loader->contain([
            'clients.orders.stuff',
            'clients.companies.categories' => ['conditions' => ['a >' => 1]],
        ]);
        $expected = [
            'clients' => [
                'orders' => [
                    'stuff' => [],
                ],
                'companies' => [
                    'categories' => [
                        'conditions' => ['a >' => 1],
                    ],
                ],
            ],
        ];
        $this->assertEquals($expected, $loader->getContain());
        $loader->contain([
            'clients.orders' => ['fields' => ['a', 'b']],
            'clients' => ['sort' => ['a' => 'desc']],
        ]);

        $expected['clients']['orders'] += ['fields' => ['a', 'b']];
        $expected['clients'] += ['sort' => ['a' => 'desc']];
        $this->assertEquals($expected, $loader->getContain());
    }

    /**
     * Tests setting containments using direct key value pairs works just as with key array.
     */
    public function testContainKeyValueNotation(): void
    {
        $loader = new EagerLoader();
        $loader->contain([
            'clients',
            'companies' => 'categories',
        ]);
        $expected = [
            'clients' => [],
            'companies' => [
                'categories' => [],
            ],
        ];
        $this->assertEquals($expected, $loader->getContain());
    }

    /**
     * Tests that it is possible to pass a function as the array value for contain
     */
    public function testContainClosure(): void
    {
        $builder = function ($query): void {
        };
        $loader = new EagerLoader();
        $loader->contain([
            'clients.orders.stuff' => ['fields' => ['a']],
            'clients' => $builder,
        ]);

        $expected = [
            'clients' => [
                'orders' => [
                    'stuff' => ['fields' => ['a']],
                ],
                'queryBuilder' => $builder,
            ],
        ];
        $this->assertEquals($expected, $loader->getContain());

        $loader = new EagerLoader();
        $loader->contain([
            'clients.orders.stuff' => ['fields' => ['a']],
            'clients' => ['queryBuilder' => $builder],
        ]);
        $this->assertEquals($expected, $loader->getContain());
    }

    /**
     * Tests using the same signature as matching with contain
     */
    public function testContainSecondSignature(): void
    {
        $builder = function ($query): void {
        };
        $loader = new EagerLoader();
        $loader->contain('clients', $builder);

        $expected = [
            'clients' => [
                'queryBuilder' => $builder,
            ],
        ];
        $this->assertEquals($expected, $loader->getContain());
    }

    /**
     * Tests passing an array of associations with a query builder
     */
    public function testContainSecondSignatureInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $builder = function ($query): void {
        };
        $loader = new EagerLoader();
        $loader->contain(['clients'], $builder);

        $expected = [
            'clients' => [
                'queryBuilder' => $builder,
            ],
        ];
        $this->assertEquals($expected, $loader->getContain());
    }

    /**
     * Tests that query builders are stacked
     */
    public function testContainMergeBuilders(): void
    {
        $loader = new EagerLoader();
        $loader->contain([
            'clients' => fn($query) => $query->select(['a']),
        ]);
        $loader->contain([
            'clients' => fn($query) => $query->select(['b']),
        ]);
        $builder = $loader->getContain()['clients']['queryBuilder'];
        $collection = $this->getCollectionLocator()->get('foo');
        $query = new SelectQuery($collection);
        $query = $builder($query);
        $this->assertEquals(['a' => 1, 'b' => 1], $query->clause('select'));
    }

    /**
     * Test that fields for contained models are aliased and added to the select clause
     */
    public function testContainToFieldsPredefined(): void
    {
        $this->markTestSkipped('ODM projection is a Mongo field map, not SQL alias-qualified select (F25).');
        $contains = [
            'clients' => [
                'fields' => ['name', 'company_id', 'clients.telephone'],
                'orders' => [
                    'fields' => ['total', 'placed'],
                ],
            ],
        ];

        $collection = $this->getCollectionLocator()->get('foo');
        $query = new SelectQuery($collection);
        $loader = new EagerLoader();
        $loader->contain($contains);

        $query->select('foo.id');
        $loader->attachAssociations($query, $collection);

        $select = $query->clause('select');
        $expected = [
            'foo.id', 'clients__name' => 'clients.name',
            'clients__company_id' => 'clients.company_id',
            'clients__telephone' => 'clients.telephone',
            'orders__total' => 'orders.total', 'orders__placed' => 'orders.placed',
            // Primary keys are added to ensure proper entity hydration
            'clients__id' => 'clients.id',
            'orders__id' => 'orders.id',
        ];
        $this->assertEquals($expected, $select);
    }

    /**
     * Tests that default fields for associations are added to the select clause when
     * none is specified
     */
    public function testContainToFieldsDefault(): void
    {
        $this->markTestSkipped('ODM projection is a Mongo field map; auto-quoting is SQL-only (F25).');
        $contains = ['clients' => ['orders']];

        $query = new SelectQuery($this->collection);
        $query->select()->contain($contains)->sql();
        $select = $query->clause('select');
        $expected = [
            'foo__id' => 'foo.id', 'clients__name' => 'clients.name',
            'clients__id' => 'clients.id', 'clients__phone' => 'clients.phone',
            'orders__id' => 'orders.id', 'orders__total' => 'orders.total',
            'orders__placed' => 'orders.placed',
        ];
        $expected = $this->quoteArray($expected);
        $this->assertEquals($expected, $select);

        $contains['clients']['fields'] = ['name'];
        $query = new SelectQuery($this->collection);
        $query->select('foo.id')->contain($contains)->sql();
        $select = $query->clause('select');
        $expected = [
            'foo__id' => 'foo.id',
            'clients__name' => 'clients.name',
            // Primary key is now auto-added to ensure proper entity hydration
            'clients__id' => 'clients.id',
        ];
        $expected = $this->quoteArray($expected);
        $this->assertEquals($expected, $select);

        $contains['clients']['fields'] = [];
        $contains['clients']['orders']['fields'] = false;
        $query = new SelectQuery($this->collection);
        $query->select()->contain($contains)->sql();
        $select = $query->clause('select');
        $expected = [
            'foo__id' => 'foo.id',
            'clients__id' => 'clients.id',
            'clients__name' => 'clients.name',
            'clients__phone' => 'clients.phone',
        ];
        $expected = $this->quoteArray($expected);
        $this->assertEquals($expected, $select);
    }

    /**
     * Tests that the path for getting to a deep association is materialized in an
     * array key
     */
    public function testNormalizedPath(): void
    {
        $contains = [
            'clients' => [
                'orders' => [
                    'orderTypes',
                    'stuff' => ['stuffTypes'],
                ],
                'companies' => [
                    'categories',
                ],
            ],
        ];

        $loader = new EagerLoader();
        $loader->contain($contains);

        $normalized = $loader->normalized($this->collection);
        $this->assertSame('clients', $normalized['clients']->aliasPath());
        $this->assertSame('client', $normalized['clients']->propertyPath());

        $assocs = $normalized['clients']->associations();
        $this->assertSame('clients.orders', $assocs['orders']->aliasPath());
        $this->assertSame('client.order', $assocs['orders']->propertyPath());

        $assocs = $assocs['orders']->associations();
        $this->assertSame('clients.orders.orderTypes', $assocs['orderTypes']->aliasPath());
        $this->assertSame('client.order.order_type', $assocs['orderTypes']->propertyPath());
        $this->assertSame('clients.orders.stuff', $assocs['stuff']->aliasPath());
        $this->assertSame('client.order.stuff', $assocs['stuff']->propertyPath());

        $assocs = $assocs['stuff']->associations();
        $this->assertSame(
            'clients.orders.stuff.stuffTypes',
            $assocs['stuffTypes']->aliasPath(),
        );
        $this->assertSame(
            'client.order.stuff.stuff_type',
            $assocs['stuffTypes']->propertyPath(),
        );
    }

    /**
     * Tests that the paths for matching containments point to _matchingData.
     */
    public function testNormalizedMatchingPath(): void
    {
        $loader = new EagerLoader();
        $loader->setMatching('clients');

        $assocs = $loader->attachableAssociations($this->collection);

        $this->assertSame('clients', $assocs['clients']->aliasPath());
        $this->assertSame('_matchingData.clients', $assocs['clients']->propertyPath());
    }

    /**
     * Tests that the paths for deep matching containments point to _matchingData.
     */
    public function testNormalizedDeepMatchingPath(): void
    {
        $loader = new EagerLoader();
        $loader->setMatching('clients.orders');

        $assocs = $loader->attachableAssociations($this->collection);

        $this->assertSame('clients', $assocs['clients']->aliasPath());
        $this->assertSame('_matchingData.clients', $assocs['clients']->propertyPath());

        $assocs = $assocs['clients']->associations();
        $this->assertSame('clients.orders', $assocs['orders']->aliasPath());
        $this->assertSame('_matchingData.orders', $assocs['orders']->propertyPath());
    }

    /**
     * Test clearing containments but not matching joins.
     */
    public function testClearContain(): void
    {
        $contains = [
            'clients' => [
                'orders' => [
                    'orderTypes',
                    'stuff' => ['stuffTypes'],
                ],
                'companies' => [
                    'categories',
                ],
            ],
        ];

        $loader = new EagerLoader();
        $loader->contain($contains);
        $loader->setMatching('clients.addresses');

        $loader->clearContain();

        $result = $loader->normalized($this->collection);
        $this->assertEquals([], $result);
        $this->assertArrayHasKey('clients', $loader->getMatching());
    }

    /**
     * Test for enableAutoFields()
     */
    public function testEnableAutoFields(): void
    {
        $this->markTestSkipped('ODM has no SQL auto-fields projection; EagerLoader::enableAutoFields is SQL-only (F25).');
        $loader = new EagerLoader();
        $this->assertTrue($loader->isAutoFieldsEnabled());
        $this->assertSame($loader, $loader->disableAutoFields());
        $this->assertFalse($loader->isAutoFieldsEnabled());
    }

    /**
     * Helper function sued to quoted both keys and values in an array in case
     * the test suite is running with auto quoting enabled
     *
     * @param array $elements
     * @return array
     */
    protected function quoteArray($elements): array
    {
        if ($this->connection->getDriver()->isAutoQuotingEnabled()) {
            $quoter = (fn($e) => $this->connection->getDriver()->quoteIdentifier($e));

            return array_combine(
                array_map($quoter, array_keys($elements)),
                array_map($quoter, array_values($elements)),
            );
        }

        return $elements;
    }

    /**
     * Asserts that matching('something') and setMatching('something') return consistent type.
     */
    public function testSetMatchingReturnType(): void
    {
        $loader = new EagerLoader();
        $result = $loader->setMatching('clients');
        $this->assertInstanceOf(EagerLoader::class, $result);
        $this->assertArrayHasKey('clients', $loader->getMatching());
    }

    /**
     * Test that calling setMatching without a builder preserves the existing queryBuilder.
     *
     * @see https://github.com/cakephp/cakephp/issues/19285
     */
    public function testSetMatchingPreservesExistingQueryBuilder(): void
    {
        $loader = new EagerLoader();
        $builderCalled = false;
        $loader->setMatching('clients', function ($q) use (&$builderCalled) {
            $builderCalled = true;

            return $q;
        });

        // Second call without builder should not override the first
        $loader->setMatching('clients');

        $matching = $loader->getMatching();
        $this->assertArrayHasKey('clients', $matching);
        $this->assertArrayHasKey('queryBuilder', $matching['clients']);
        $this->assertNotNull($matching['clients']['queryBuilder']);

        // Actually call the builder to verify it's preserved
        $matching['clients']['queryBuilder'](
            Mockery::mock(SelectQuery::class),
        );
        $this->assertTrue($builderCalled, 'Original queryBuilder should be preserved and called');
    }
}
