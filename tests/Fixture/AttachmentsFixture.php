<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\AttachmentsFixture` for the ODM test harness.
 */
class AttachmentsFixture extends TestFixture
{
    /**
     * The connection name.
     *
     * @var string
     */
    public string $connection = 'test_mongo';

    /**
     * The collection name.
     *
     * @var string
     */
    public string $collection = 'attachments';

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'comment_id' => '000000000000000000000005', 'attachment' => 'attachment.zip', 'created' => '2007-03-18 10:51:23', 'updated' => '2007-03-18 10:53:31'],
    ];
}
