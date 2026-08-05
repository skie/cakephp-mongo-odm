<?php
declare(strict_types=1);

namespace TestApp\Model\Enum;

use Cake\Database\Type\Attribute\Label;

enum ArticleStatusExtract: string
{
    #[Label('Published')]
    case Published = 'Y';

    #[Label('Unpublished')]
    case Unpublished = 'N';

    #[Label('Archived', context: 'article_status')]
    case Archived = 'A';
}
