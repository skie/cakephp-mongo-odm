<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Association;

use Crustum\Mongo\ODM\Association\DBRef;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use MongoDB\BSON\ObjectId;
use MongoDB\Model\BSONDocument;

class DBRefTest extends TestCase
{
    public function testCreateRefUsesCollectionAndDocumentId(): void
    {
        $association = new DBRef('Author', ['collection' => 'authors']);
        $document = new Document(['_id' => new ObjectId('507f1f77bcf86cd799439011')]);
        $ref = $association->createRef($document);

        $this->assertInstanceOf(BSONDocument::class, $ref);
        $this->assertEquals([
            '$ref' => 'authors',
            '$id' => new ObjectId('507f1f77bcf86cd799439011'),
        ], $ref->getArrayCopy());
    }
}
