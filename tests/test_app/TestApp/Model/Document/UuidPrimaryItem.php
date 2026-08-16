<?php
declare(strict_types=1);

namespace TestApp\Model\Document;

use Crustum\Mongo\ODM\Attribute\Document as DocumentAttribute;
use Crustum\Mongo\ODM\Attribute\Field;
use Crustum\Mongo\ODM\Document;

/**
 * Document for the `uuid_primary_items` collection whose application primary
 * key is the UUID `id` field (the Mongo `_id` is separate).
 */
#[DocumentAttribute(collection: 'uuid_primary_items', primaryKey: 'id')]
#[Field(name: 'id', type: 'uuid', primaryKey: true)]
#[Field(name: 'name', type: 'string')]
class UuidPrimaryItem extends Document
{
    /**
     * Fields that can be mass assigned using newDocument() or patchDocument().
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'id' => true,
        'name' => true,
    ];
}
