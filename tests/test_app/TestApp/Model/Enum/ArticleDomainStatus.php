<?php
declare(strict_types=1);

namespace TestApp\Model\Enum;

use Cake\Database\Type\Attribute\Label;
use Cake\Database\Type\EnumLabelTrait;

enum ArticleDomainStatus
{
    use EnumLabelTrait;

    #[Label('Article is published in the news domain', domain: 'news', context: 'Article')]
    case Published;

    #[Label('Article is unpublished in the news domain', domain: 'news')]
    case Unpublished;
}
