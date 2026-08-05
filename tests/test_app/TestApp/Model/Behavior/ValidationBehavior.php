<?php
declare(strict_types=1);

namespace TestApp\Model\Behavior;

use Cake\Validation\Validator;
use Crustum\Mongo\ODM\Behavior;

/**
 * Description of ValidationBehavior
 */
class ValidationBehavior extends Behavior
{
    public function validationBehavior(Validator $validator): Validator
    {
        return $validator->add('name', 'behaviorRule');
    }
}
