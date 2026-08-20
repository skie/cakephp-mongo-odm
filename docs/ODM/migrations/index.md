# Migrations

### PENDING
> Samples below are verified against `src/Migration/**` and the live output in
> `mongo-testapp` (`config/MongoMigrations`, `config/MongoSeeds`). The exact CLI
> output of each `mongo migrations *` / `bake mongo_migration*` command should be
> re-checked against a real run before this banner is removed.

Migrations lets you track changes to your database schema over time as PHP code
that accompanies your application. This lets you ensure each environment your
application runs in has the appropriate schema by applying migrations.

Instead of writing schema modifications in SQL, this plugin allows you to
define schema changes with a high-level database portable API.

## The Mongo difference

Migrations is part of the `Crustum/Mongo` plugin and is a Mongo-native port of
`cakephp/migrations`. The SQL/Phinx concepts map to Mongo as follows:

- **Tables → collections**
- **Columns → fields** (with BSON types, e.g. `objectid`, `int64`, `decimal128`)
- **Foreign keys / constraints → `$jsonSchema` validators**
- **Migration journal → a `cake_migrations` collection**
- **Seed tracking → a `_seeds` collection**

Commands are namespaced under `mongo` and `bake mongo_*`:

- `mongo migrations migrate|rollback|status|mark_migrated|reset`
- `mongo migrations seed|seed_status|seed_reset`
- `mongo migrations diff`, `mongo schema dump`
- `bake mongo_migration`, `bake mongo_migration_diff`,
  `bake mongo_migration_snapshot`, `bake mongo_migration_simple`, `bake mongo_seed`

## Installation

Migrations is provided by the `Crustum/Mongo` plugin. Load the plugin in your
application:

```php
// src/Application.php
$this->addPlugin('Crustum/Mongo');
```

Configure the Mongo connection in `config/mongo.php` (loaded via
`Configure::load('mongo')`), as explained in the [Database
Configuration](../Database-basics#database-configuration).

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
        $collection->addColumn('price', 'decimal128');
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
- `price` of type `decimal128`
- `created` / `modified` of type `date`

> [!NOTE]
> Migrations are not automatically applied. Use the CLI commands to apply and
> roll back migrations.

```bash
bin/cake mongo migrations migrate
```

## Guide Map

Use the focused guides below instead of a single long reference page:

- [Installation and Overview](../migrations/getting-started/installation-and-overview)
- [Creating Migrations](../migrations/getting-started/creating-migrations)
- [Snapshots and Diffs](../migrations/getting-started/snapshots-and-diffs)
- [Running and Managing Migrations](../migrations/getting-started/running-and-managing-migrations)
- [Writing Migrations](../migrations/guides/writing-migrations)
- [Database Seeding](../migrations/guides/seeding)
- [Integration and Deployment](../migrations/advanced/integration-and-deployment)

## Creating Migrations

Migration naming conventions, bake patterns, column syntax, and generated
examples are covered in [Creating
Migrations](../migrations/getting-started/creating-migrations).

## Generating migration snapshots from an existing database

For snapshot generation, diff workflows, and dump files, see [Snapshots and
Diffs](../migrations/getting-started/snapshots-and-diffs).

## Generating a diff

The `bake mongo_migration_diff` workflow is covered in [Snapshots and
Diffs](../migrations/getting-started/snapshots-and-diffs).

## Applying Migrations

For `migrate`, `rollback`, `status`, `mark_migrated`, and related command
options, see [Running and Managing
Migrations](../migrations/getting-started/running-and-managing-migrations).

## Reverting Migrations

Rollback usage is documented in [Running and Managing
Migrations](../migrations/getting-started/running-and-managing-migrations).

## View Migrations Status

Status commands and JSON output are documented in [Running and Managing
Migrations](../migrations/getting-started/running-and-managing-migrations).

## Marking a migration as migrated

See [Running and Managing
Migrations](../migrations/getting-started/running-and-managing-migrations) for
`mark_migrated` examples and caveats.

## Seeding your database

Seed classes are documented in [Database Seeding](../migrations/guides/seeding).

## Generating a dump file

Dump generation is documented in [Snapshots and
Diffs](../migrations/getting-started/snapshots-and-diffs).

## Using Migrations for Tests

Test bootstrapping with the `Migrator` is covered in [Integration and
Deployment](../migrations/advanced/integration-and-deployment).

## Using Migrations In Plugins

Plugin-scoped migration workflows are covered in [Integration and
Deployment](../migrations/advanced/integration-and-deployment).

## Running Migrations in a non-shell environment

Programmatic execution through the `Migrations` class is covered in
[Integration and Deployment](../migrations/advanced/integration-and-deployment).

## Deployment

Deployment guidance and schema cache refresh steps are covered in [Integration
and Deployment](../migrations/advanced/integration-and-deployment).

## Alert of missing migrations

Local development alerts for pending migrations are covered in [Integration and
Deployment](../migrations/advanced/integration-and-deployment).
