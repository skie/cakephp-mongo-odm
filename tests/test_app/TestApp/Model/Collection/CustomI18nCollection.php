<?php
declare(strict_types=1);

namespace TestApp\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;

class CustomI18nCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->setCollection('custom_i18n_collection');
    }

    #[\Override]
    public static function defaultConnectionName(): string
    {
        return 'custom_i18n_datasource';
    }
}
