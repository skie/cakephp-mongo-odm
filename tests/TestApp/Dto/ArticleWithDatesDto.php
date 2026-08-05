<?php
declare(strict_types=1);

namespace TestApp\Dto;

final class ArticleWithDatesDto
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $created,
    ) {
    }

    public static function createFromArray(array $data): self
    {
        return new self(
            (string)($data['title'] ?? ''),
            isset($data['created']) ? (string)$data['created'] : null,
        );
    }
}
