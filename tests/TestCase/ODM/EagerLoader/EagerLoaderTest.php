<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\EagerLoader;

use Crustum\Mongo\Database\Query\SelectQuery;
use Crustum\Mongo\ODM\EagerLoader;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;

final class EagerLoaderTest extends TestCase
{
    public function testContainNormalizesDottedAndNestedPaths(): void
    {
        $loader = new EagerLoader();
        $loader->contain(['Posts.Comments', 'Profile']);

        $normalized = $loader->normalized(new RepositoryStub([
            'Posts' => new AssociationStub(new RepositoryStub(['Comments' => new AssociationStub(new RepositoryStub())])),
            'Profile' => new AssociationStub(new RepositoryStub()),
        ]));

        self::assertSame(['Posts', 'Profile'], array_keys($normalized));
        self::assertSame('Posts.Comments', $normalized['Posts']->associations()['Comments']->aliasPath());
    }

    public function testStrategiesDispatchEmbedAndLookupToPipelineAndSelectExternally(): void
    {
        $embed = new AssociationStub(new RepositoryStub(), 'embed', [['$set' => ['profile.loaded' => true]]]);
        $lookup = new AssociationStub(new RepositoryStub(), 'lookup', [['$lookup' => ['from' => 'profiles']]]);
        $reference = new AssociationStub(new RepositoryStub(), 'select');
        $repository = new RepositoryStub(['Embed' => $embed, 'Lookup' => $lookup, 'Reference' => $reference]);
        $loader = new EagerLoader();
        $loader->contain(['Embed', 'Lookup' => ['strategy' => 'lookup'], 'Reference']);

        $query = new SelectQuery(null, 'users');

        $loader->attachAssociations($query, $repository);

        self::assertSame([
            ['$set' => ['profile.loaded' => true]],
            ['$lookup' => ['from' => 'profiles']],
        ], $query->compile()['pipeline']);
        self::assertSame(['Reference'], array_map(fn($item) => $item->name(), $loader->getExternalAssociations()));
    }

    public function testQueryBuilderIsAppliedDuringNormalization(): void
    {
        $target = new RepositoryStub();
        $association = new AssociationStub($target);
        $loader = new EagerLoader();
        $loader->contain('Reference', static fn(SelectQuery $query): SelectQuery => $query->where(['active' => true]));

        $normalized = $loader->normalized(new RepositoryStub(['Reference' => $association]));

        self::assertSame(['active' => true], $normalized['Reference']->getConfig()['conditions']);
    }
}
