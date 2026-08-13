<?php
declare(strict_types=1);

namespace TestApp\Model\Entity;

use Cake\ORM\Entity;

/**
 * SQL ORM Order entity — bridge source row.
 *
 * Carries the cross-boundary properties loaded by the bridge associations
 * (author, profile, documents, tags) alongside the plain SQL columns.
 *
 * @property int $id
 * @property string|null $customer_name
 * @property string|null $author_id
 * @property \Crustum\Mongo\ODM\Document|null $author
 * @property \Crustum\Mongo\ODM\Document|null $profile
 * @property array<\Crustum\Mongo\ODM\Document> $documents
 * @property array<\Crustum\Mongo\ODM\Document> $posts
 * @property array<\Crustum\Mongo\ODM\Document> $tags
 */
class Order extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        '*' => true,
    ];
}
