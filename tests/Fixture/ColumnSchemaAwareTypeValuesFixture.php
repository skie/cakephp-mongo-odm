<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;
use TestApp\Database\ColumnSchemaAwareTypeValueObject;

/**
 * Port of `Cake\Test\Fixture\ColumnSchemaAwareTypeValuesFixture` for the ODM test harness.
 *
 * The value objects come from the test_app port and are serialized verbatim;
 * a custom Mongo type would be needed to process them during read/write.
 */
class ColumnSchemaAwareTypeValuesFixture extends TestFixture
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
    public string $table = 'column_schema_aware_type_values';

    /**
     * {@inheritDoc}
     *
     * @return void
     */
    public function init(): void
    {
        $this->records = [
            [
                'val' => new ColumnSchemaAwareTypeValueObject('THIS TEXT SHOULD BE PROCESSED VIA A CUSTOM TYPE'),
            ],
            [
                'val' => 'THIS TEXT ALSO SHOULD BE PROCESSED VIA A CUSTOM TYPE',
            ],
        ];
    }
}
