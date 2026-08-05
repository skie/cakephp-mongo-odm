<?php
declare(strict_types=1);

namespace TestApp\Dto;

/**
 * Simple readonly DTO for Author.
 */
readonly class AuthorDto
{
    /**
     * @param string $id
     * @param string $name
     */
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
