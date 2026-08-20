# Installation and Overview

### PENDING
> Samples below are verified against `src/Migration/**`; re-check the exact CLI
> output against a live run before removing this banner.

This guide covers installation, plugin loading, and the basic migration model
used by the Crustum Mongo Migrations layer.

## Installation

Migrations is provided by the `Crustum/Mongo` plugin. Load the plugin in your
application:

```php
// src/Application.php
$this->addPlugin('Crustum/Mongo');
```

Or via the console:

```bash
bin/cake plugin load Crustum/Mongo
```

Configure the Mongo connection in `config/mongo.php` (loaded through
`Configure::load('mongo')`). Migration files live in `config/MongoMigrations/`
by default and seed files in `config/MongoSeeds/`.

## Overview

A migration is a PHP file that describes the changes to apply to your database.
A migration file can add, change, or remove collections, fields, indexes, and
`$jsonSchema` validators.

To create a collection, use a migration similar to this:

```php
<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseMigration;

class CreateProducts extends BaseMigration
{
    public function change(): void
    {
        $collection = $this->collection('products');
        $collection->addColumn('name', 'string', [
            'length' => 255,
        ]);
        $collection->addColumn('description', 'text');
        $collection->addColumn('created', 'date');
        $collection->addColumn('modified', 'date');
        $collection->create();
    }
}
```

When applied, this migration will create a collection named `products` with the
following field definitions:

- `_id` of type `objectid` as the primary key (implicit in Mongo).
- `name` of type `string`
- `description` of type `text`
- `created` / `modified` of type `date`

> [!NOTE]
> Migrations are not automatically applied. Use the CLI commands to apply and
> roll back migrations.

Once the file has been created in the `config/MongoMigrations` folder, you can
apply it:

```bash
bin/cake mongo migrations migrate
```

## Next Steps

- Use [Creating Migrations](../migrations/getting-started/creating-migrations)
  to generate migration files with `bake`
- Use [Running and Managing
  Migrations](../migrations/getting-started/running-and-managing-migrations) to
  apply, roll back, and inspect migrations
- Use [Writing Migrations](../migrations/guides/writing-migrations) for the full
  collection API and migration authoring reference
