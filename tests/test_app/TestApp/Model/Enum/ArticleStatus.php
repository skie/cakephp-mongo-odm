<?php
declare(strict_types=1);

namespace TestApp\Model\Enum;

enum ArticleStatus: string
{
    case Published = 'Y';
    case Unpublished = 'N';
}
