<?php
declare(strict_types=1);

namespace TestApp\Dto;

final class AuthorDto
{
    public function __construct(
        public readonly string $name,
    ) {
    }

    public static function createFromArray(array $data): self
    {
        return new self((string)($data['name'] ?? ''));
    }
}
