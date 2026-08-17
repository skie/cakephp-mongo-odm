<?php
declare(strict_types=1);

namespace TestApp\Dto;

/**
 * Simple readonly DTO for Comment.
 */
readonly class CommentDto
{
    /**
     * @param string $_id
     * @param string $comment
     * @param string $article_id
     * @param string $user_id
     */
    public function __construct(
        public string $_id,
        public string $comment,
        public string $article_id,
        public string $user_id,
    ) {
    }
}
