<?php
declare(strict_types=1);

namespace TestApp\Model\Enum;

use Cake\Database\Type\Attribute\Label;
use Cake\Database\Type\EnumLabelInterface;
use Cake\Database\Type\EnumLabelTrait;

enum ArticleStatusTraitLabeled: string implements EnumLabelInterface
{
    use EnumLabelTrait;

    #[Label('Article is published')]
    case Published = 'Y';

    #[Label('Article is not published')]
    case Unpublished = 'N';

    case PendingReview = 'P';

    #[Label('Article is a draft', context: 'ArticleStatus')]
    case Draft = 'D';
}
