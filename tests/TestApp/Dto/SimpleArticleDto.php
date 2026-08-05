<?php
declare(strict_types=1);

namespace TestApp\Dto;

final class SimpleArticleDto
{
    public function __construct(
        public readonly string $title,
    ) {
    }

    public static function createFromArray(array $data): self
    {
        return new self((string)($data['title'] ?? ''));
    }
}
