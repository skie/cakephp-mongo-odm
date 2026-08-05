<?php
declare(strict_types=1);

namespace TestApp\Model\Enum;

use Cake\Database\Type\EnumLabelInterface;
use Cake\Database\Type\EnumLabelTrait;

enum ArticleStatusTrait: string implements EnumLabelInterface
{
    use EnumLabelTrait;

    case Published = 'Y';
    case Unpublished = 'N';
    case PendingReview = 'P';
}
