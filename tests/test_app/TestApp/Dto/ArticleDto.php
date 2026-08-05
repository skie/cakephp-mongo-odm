<?php
declare(strict_types=1);

namespace TestApp\Dto;

final class ArticleDto
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $author_name,
    ) {
    }

    public static function createFromArray(array $data): self
    {
        return new self(
            (string)($data['title'] ?? ''),
            (string)($data['body'] ?? ''),
            (string)($data['author_name'] ?? ''),
        );
    }
}
