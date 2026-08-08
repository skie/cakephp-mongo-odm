<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use Cake\I18n\DateTime;
use Cake\ORM\DtoMapper;
use TestApp\Dto\ArticleDto;
use TestApp\Dto\ArticleWithDatesDto;
use TestApp\Dto\AuthorDto;
use TestApp\Dto\CommentDto;
use TestApp\Dto\SimpleArticleDto;

/**
 * DtoMapper test case.
 */
class DtoMapperTest extends TestCase
{
    protected DtoMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new DtoMapper();
        DtoMapper::clearCache();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        DtoMapper::clearCache();
    }

    public function testMapSimpleDto(): void
    {
        $data = [
            '_id' => '000000000000000000000001',
            'title' => 'Test Article',
            'body' => 'Test Body',
        ];

        $dto = $this->mapper->map($data, SimpleArticleDto::class);

        $this->assertInstanceOf(SimpleArticleDto::class, $dto);
        $this->assertSame('000000000000000000000001', $dto->_id);
        $this->assertSame('Test Article', $dto->title);
        $this->assertSame('Test Body', $dto->body);
    }

    public function testMapWithNullableField(): void
    {
        $data = [
            '_id' => '000000000000000000000001',
            'title' => 'Test Article',
        ];

        $dto = $this->mapper->map($data, SimpleArticleDto::class);

        $this->assertInstanceOf(SimpleArticleDto::class, $dto);
        $this->assertSame('000000000000000000000001', $dto->_id);
        $this->assertSame('Test Article', $dto->title);
        $this->assertNull($dto->body);
    }

    public function testMapWithDefaultValue(): void
    {
        $data = [
            '_id' => '000000000000000000000001',
            'title' => 'Test Article',
        ];

        $dto = $this->mapper->map($data, ArticleDto::class);

        $this->assertInstanceOf(ArticleDto::class, $dto);
        $this->assertSame([], $dto->comments);
    }

    public function testMapNestedDto(): void
    {
        $data = [
            '_id' => '000000000000000000000001',
            'title' => 'Test Article',
            'author' => [
                '_id' => '000000000000000000000010',
                'name' => 'John Doe',
            ],
        ];

        $dto = $this->mapper->map($data, ArticleDto::class);

        $this->assertInstanceOf(ArticleDto::class, $dto);
        $this->assertInstanceOf(AuthorDto::class, $dto->author);
        $this->assertSame('000000000000000000000010', $dto->author->_id);
        $this->assertSame('John Doe', $dto->author->name);
    }

    public function testMapNestedDtoNull(): void
    {
        $data = [
            '_id' => '000000000000000000000001',
            'title' => 'Test Article',
            'author' => null,
        ];

        $dto = $this->mapper->map($data, ArticleDto::class);

        $this->assertInstanceOf(ArticleDto::class, $dto);
        $this->assertNull($dto->author);
    }

    public function testMapCollectionOfDtos(): void
    {
        $data = [
            '_id' => '000000000000000000000001',
            'title' => 'Test Article',
            'comments' => [
                ['_id' => '000000000000000000000001', 'comment' => 'First comment', 'article_id' => 1, 'user_id' => 1],
                ['_id' => '000000000000000000000002', 'comment' => 'Second comment', 'article_id' => 1, 'user_id' => 2],
            ],
        ];

        $dto = $this->mapper->map($data, ArticleDto::class);

        $this->assertInstanceOf(ArticleDto::class, $dto);
        $this->assertCount(2, $dto->comments);
        $this->assertInstanceOf(CommentDto::class, $dto->comments[0]);
        $this->assertInstanceOf(CommentDto::class, $dto->comments[1]);
        $this->assertSame('First comment', $dto->comments[0]->comment);
        $this->assertSame('Second comment', $dto->comments[1]->comment);
    }

    public function testMapCollectionEmpty(): void
    {
        $data = [
            '_id' => '000000000000000000000001',
            'title' => 'Test Article',
            'comments' => [],
        ];

        $dto = $this->mapper->map($data, ArticleDto::class);

        $this->assertInstanceOf(ArticleDto::class, $dto);
        $this->assertSame([], $dto->comments);
    }

    public function testMapWithExtraFields(): void
    {
        $data = [
            '_id' => '000000000000000000000001',
            'title' => 'Test Article',
            'body' => 'Test Body',
            'extra_field' => 'ignored',
            'another_field' => 123,
        ];

        $dto = $this->mapper->map($data, SimpleArticleDto::class);

        $this->assertInstanceOf(SimpleArticleDto::class, $dto);
        $this->assertSame('000000000000000000000001', $dto->_id);
        $this->assertSame('Test Article', $dto->title);
        $this->assertSame('Test Body', $dto->body);
    }

    public function testMapComplexNestedStructure(): void
    {
        $data = [
            '_id' => '000000000000000000000001',
            'title' => 'Test Article',
            'body' => 'Test Body',
            'author' => [
                '_id' => '000000000000000000000010',
                'name' => 'Jane Doe',
            ],
            'comments' => [
                ['_id' => '000000000000000000000001', 'comment' => 'Great article!', 'article_id' => 1, 'user_id' => 5],
                ['_id' => '000000000000000000000002', 'comment' => 'Thanks for sharing', 'article_id' => 1, 'user_id' => 6],
                ['_id' => '000000000000000000000003', 'comment' => 'Very helpful', 'article_id' => 1, 'user_id' => 7],
            ],
        ];

        $dto = $this->mapper->map($data, ArticleDto::class);

        $this->assertInstanceOf(ArticleDto::class, $dto);
        $this->assertSame('000000000000000000000001', $dto->_id);
        $this->assertSame('Test Article', $dto->title);
        $this->assertSame('Test Body', $dto->body);

        $this->assertInstanceOf(AuthorDto::class, $dto->author);
        $this->assertSame('000000000000000000000010', $dto->author->_id);
        $this->assertSame('Jane Doe', $dto->author->name);

        $this->assertCount(3, $dto->comments);
        $this->assertSame('Great article!', $dto->comments[0]->comment);
        $this->assertSame(5, $dto->comments[0]->user_id);
    }

    public function testCacheIsUsed(): void
    {
        $data = ['_id' => '000000000000000000000001', 'title' => 'Test', 'body' => 'Body'];

        // First call populates cache
        $this->mapper->map($data, SimpleArticleDto::class);

        // Second call should use cache
        $dto = $this->mapper->map($data, SimpleArticleDto::class);

        $this->assertInstanceOf(SimpleArticleDto::class, $dto);
    }

    public function testClearCache(): void
    {
        $data = ['_id' => '000000000000000000000001', 'title' => 'Test', 'body' => 'Body'];

        $this->mapper->map($data, SimpleArticleDto::class);

        DtoMapper::clearCache();

        // Should still work after clearing cache
        $dto = $this->mapper->map($data, SimpleArticleDto::class);

        $this->assertInstanceOf(SimpleArticleDto::class, $dto);
    }

    public function testMapWithDateTimeObjects(): void
    {
        $created = new DateTime('2024-01-15 10:30:00');
        $modified = new DateTime('2024-06-20 14:45:00');

        $data = [
            '_id' => '000000000000000000000001',
            'title' => 'Test Article',
            'created' => $created,
            'modified' => $modified,
        ];

        $dto = $this->mapper->map($data, ArticleWithDatesDto::class);

        $this->assertInstanceOf(ArticleWithDatesDto::class, $dto);
        $this->assertSame('000000000000000000000001', $dto->_id);
        $this->assertSame('Test Article', $dto->title);
        // DateTime objects should be passed through, not mapped
        $this->assertSame($created, $dto->created);
        $this->assertSame($modified, $dto->modified);
    }

    public function testMapWithNullDateTime(): void
    {
        $data = [
            '_id' => '000000000000000000000001',
            'title' => 'Test Article',
            'created' => null,
            'modified' => null,
        ];

        $dto = $this->mapper->map($data, ArticleWithDatesDto::class);

        $this->assertInstanceOf(ArticleWithDatesDto::class, $dto);
        $this->assertNull($dto->created);
        $this->assertNull($dto->modified);
    }
}
