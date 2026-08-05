<?php
declare(strict_types=1);

namespace TestApp\Model\Enum;

enum Gender: string
{
    case NoSelection = '';
    case Male = 'Male';
    case Female = 'Female';
}
