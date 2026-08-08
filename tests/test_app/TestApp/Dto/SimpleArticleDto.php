<?php
declare(strict_types=1);

namespace TestApp\Dto;

/**
 * Simple readonly DTO without nested types.
 */
readonly class SimpleArticleDto
{
    /**
     * @param string $_id
     * @param string $title
     * @param string|null $body
     */
    public function __construct(
        public string $_id,
        public string $title,
        public ?string $body = null,
    ) {
    }
}
