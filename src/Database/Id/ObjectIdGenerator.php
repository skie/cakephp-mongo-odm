<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Id;

use MongoDB\BSON\ObjectId;

/**
 * Default `ObjectId` identifier generator.
 *
 * Mirrors the driver default: each generated id is a fresh `MongoDB\BSON\ObjectId`.
 *
 * @ported-from \Doctrine\ODM\MongoDB\Id\ObjectIdGenerator
 */
class ObjectIdGenerator implements IdGeneratorInterface
{
    /**
     * @inheritDoc
     */
    public function generate(array|object|null $document = null): ObjectId
    {
        return new ObjectId();
    }
}
