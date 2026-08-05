<?php
declare(strict_types=1);

namespace TestApp\Model\Enum;

use Cake\Database\Type\EnumLabelInterface;
use Cake\Utility\Inflector;

enum ArticleStatusLabel: string implements EnumLabelInterface
{
    case Published = 'Y';
    case Unpublished = 'N';

    public function label(): string
    {
        return 'Is ' . Inflector::humanize(Inflector::underscore($this->name));
    }
}
