<?php
declare(strict_types=1);

namespace TestApp\Model\Document;

use Crustum\Mongo\ODM\Attribute\Embedded;
use Crustum\Mongo\ODM\Attribute\Field;
use Crustum\Mongo\ODM\Document;

/**
 * Test embedded Document class carrying #[Embedded].
 */
#[Embedded(many: true, key: 'addresses')]
#[Field(name: 'street', type: 'string')]
#[Field(name: 'city', type: 'string')]
class BakeEmbeddedAddress extends Document
{
    /**
     * Fields that can be mass assigned using newDocument() or patchDocument().
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'street' => true,
        'city' => true,
    ];
}
