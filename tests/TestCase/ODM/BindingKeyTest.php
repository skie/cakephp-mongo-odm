<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Integration tests for using the bindingKey in associations
 *
 * @ported-from \Cake\Test\TestCase\ORM\BindingKeyTest
 */
class BindingKeyTest extends TestCase
{
    /**
     * Fixture to be used
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.Crustum/Mongo.AuthUsers',
        'plugin.Crustum/Mongo.SiteAuthors',
        'plugin.Crustum/Mongo.Users',
    ];

    /**
     * Data provider for the two types of strategies BelongsTo and HasOne implements
     *
     * ODM has no SQL join strategy; select/lookup are the Mongo strategies.
     *
     * @return array
     */
    public static function strategiesProviderJoinable(): array
    {
        return [['select'], ['lookup']];
    }

    /**
     * Data provider for the two types of strategies HasMany and BelongsToMany implements
     *
     * ODM has no SQL subquery strategy; select/lookup are the Mongo strategies.
     *
     * @return array
     */
    public static function strategiesProviderExternal(): array
    {
        return [['select'], ['lookup']];
    }

    /**
     * Tests that bindingKey can be used in belongsTo associations
     */
    #[DataProvider('strategiesProviderJoinable')]
    public function testBelongsto(string $strategy): void
    {
        $users = $this->getCollectionLocator()->get('Users');
        $users->belongsTo('AuthUsers', [
            'bindingKey' => 'username',
            'foreignKey' => 'username',
            'strategy' => $strategy,
        ]);

        $result = $users->find()
            ->contain(['AuthUsers']);

        $expected = ['mariano', 'nate', 'larry', 'garrett'];
        $expected = array_combine($expected, $expected);
        $this->assertEquals(
            $expected,
            $result->all()->combine('username', 'auth_user.username')->toArray(),
        );

        $expected = [
            '000000000000000000000001' => '000000000000000000000001',
            '000000000000000000000002' => '000000000000000000000005',
            '000000000000000000000003' => '000000000000000000000002',
            '000000000000000000000004' => '000000000000000000000004',
        ];
        $this->assertEquals(
            $expected,
            $result->all()->combine('_id', 'auth_user._id')->toArray(),
        );
    }

    /**
     * Tests that bindingKey can be used in hasOne associations
     */
    #[DataProvider('strategiesProviderJoinable')]
    public function testHasOne(string $strategy): void
    {
        $users = $this->getCollectionLocator()->get('Users');
        $users->hasOne('SiteAuthors', [
            'bindingKey' => 'username',
            'foreignKey' => 'name',
            'strategy' => $strategy,
        ]);

        $users->updateAll(['username' => 'jose'], ['username' => 'garrett']);

        $result = $users->find()
            ->contain(['SiteAuthors'])
            ->where(['username' => 'jose'])
            ->first();

        $this->assertSame('000000000000000000000003', $result->site_author->getId());
    }

    /**
     * Tests that bindingKey can be used in hasOne associations
     */
    #[DataProvider('strategiesProviderExternal')]
    public function testHasMany(string $strategy): void
    {
        $users = $this->getCollectionLocator()->get('Users');
        $authors = $users->hasMany('SiteAuthors', [
            'bindingKey' => 'username',
            'foreignKey' => 'name',
            'strategy' => $strategy,
        ]);

        $authors->updateAll(
            ['name' => 'garrett'],
            ['_id IN' => ['000000000000000000000003', '000000000000000000000004']],
        );
        $result = $users->find()
            ->contain(['SiteAuthors'])
            ->where(['username' => 'garrett']);

        $expected = ['000000000000000000000003', '000000000000000000000004'];
        $result = $result->all()->extract('site_authors.{*}._id')->toArray();
        $this->assertEquals($expected, $result);
    }
}
