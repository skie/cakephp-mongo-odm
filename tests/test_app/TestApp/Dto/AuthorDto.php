<?php
declare(strict_types=1);

namespace TestApp\Dto;

/**
 * Simple readonly DTO for Author.
 */
readonly class AuthorDto
{
    /**
     * @param string $_id
     * @param string $name
     */
    public function __construct(
        public string $_id,
        public string $name,
    ) {
    }
}
