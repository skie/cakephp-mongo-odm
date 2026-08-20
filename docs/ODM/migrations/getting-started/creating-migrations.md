# Creating Migrations

### PENDING
> Baked-output samples below are verified against `src/Migration/Command/*`,
> `templates/bake/Migration/*`, `src/View/Helper/MongoMigrationHelper.php`, and
> live files in `mongo-testapp/config/MongoMigrations/`. Re-check against a real
> `bake mongo_migration` run before removing this banner.

Migration files are stored in the `config/MongoMigrations` directory of your
application. The names of the migration files are prefixed with the date in
which they were created, in the format `YYYYMMDDHHMMSS_MigrationName.php`.

Examples:

- `20260818001951_CreateUsers.php`
- `20260818002002_CreateArticles.php`

The easiest way to create a migration file is by using `bake mongo_migration`:

```bash
bin/cake bake mongo_migration CreateProducts
```

This creates an empty migration that you can edit to add any fields, indexes,
and validators you need. See [Writing
Migrations](../migrations/guides/writing-migrations) for more information on
using `Collection` objects to define schema changes.

> [!NOTE]
> Migrations need to be applied using `bin/cake mongo migrations migrate` after
> they have been created.

## Migration File Names

When generating a migration, you can follow one of the following patterns to
have additional skeleton code generated:

- `/^(Create)(.*)/` Creates the specified collection.
- `/^(Drop)(.*)/` Drops the specified collection and ignores specified field arguments.
- `/^(Add).*(?:To)(.*)/` Adds fields to the specified collection.
- `/^(Remove).*(?:From)(.*)/` Removes fields from the specified collection.
- `/^(Alter).*(?:On)(.*)/` Alters fields from the specified collection.
- `/^(Alter)(.*)/` Alters the specified collection.

You can also use the `underscore_form` as the name for your migrations, such as
`create_products`.

> [!WARNING]
> Migration names are used as class names, and thus may collide with other
> migrations if the class names are not unique. In that case, you may need to
> rename the migration manually.

Mongo migration classes are named classes extending
`Crustum\Mongo\Migration\BaseMigration` (anonymous migration classes are not
supported).

## Creating a Collection

You can use `bake mongo_migration` to create a collection:

```bash
bin/cake bake mongo_migration CreateProducts name:string description:text created modified
```

The command above will generate a migration file that resembles:

```php
<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseMigration;

class CreateProducts extends BaseMigration
{
    /**
     * Change Method.
     *
     * More information on this method is available here:
     * https://book.cakephp.org/migrations/5/guides/writing-migrations/migration-methods.html#the-change-method
     *
     * @return void
     */
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

Note that `created` and `modified` are inferred as the Mongo `date` type, and
string fields get the default `length` of `255`.

## Column Syntax

The `bake mongo_migration` command provides a compact syntax to define fields
when generating a migration:

```bash
bin/cake bake mongo_migration CreateProducts name:string description:text created modified
```

You can use the column syntax when creating collections and adding fields. You
can also edit the generated migration afterwards to customize the fields
further.

Fields on the command line follow this pattern:

```text
fieldName:fieldType?[length]:default[value]:unique
```

Examples of valid field definitions:

- `email:string?`
- `email:string:unique`
- `email:string?[50]`
- `email:string[120]:unique`

While defining decimal fields, the `length` can include precision and scale:

- `amount:decimal128[5,2]`
- `amount:decimal128?[5,2]`

Fields with a question mark after the field type are nullable.

The `length` part is optional and should always be written between brackets.

The `default[value]` part is optional and sets the default value for the field.
Supported value types include:

- Booleans: `true` or `false` such as `active:boolean:default[true]`
- Integers: `0`, `123`, `-456` such as `count:integer:default[0]`
- Floats: `1.5`, `-2.75` such as `rate:decimal128:default[1.5]`
- Strings: `'hello'` or `"world"` such as `status:string:default['pending']`
- Null: `null` or `NULL` such as `description:text?:default[null]`

The `:unique` part marks the field with a unique index.

There are some heuristics for choosing field types when left unspecified or set
to an invalid value. The default field type is `string`:

- `id` and any `*_id` field: `objectid`
- `created`, `modified`, `updated`: `datetime` (stored as Mongo `date`)
- `latitude`, `longitude`, `lat`, `lng`: `decimal` (stored as `decimal128`)

Additionally, you can create an empty migration file if you want full control
over what needs to be executed by omitting column definitions:

```bash
bin/cake bake mongo_migration CreateCustomCollection
```

## Adding Fields to an Existing Collection

If the migration name is of the form `AddXXXToYYY` and is followed by a list of
field names and types, a migration file containing the code for creating the
fields will be generated:

```bash
bin/cake bake mongo_migration AddPriceToProducts price:decimal128[5,2]
```

This generates:

```php
<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseMigration;

class AddPriceToProducts extends BaseMigration
{
    public function change(): void
    {
        $collection = $this->collection('products');
        $collection->addColumn('price', 'decimal128', [
            'precision' => 5,
            'scale' => 2,
        ]);
        $collection->update();
    }
}
```

## Adding a Field with an Index

It is also possible to add indexes to fields:

```bash
bin/cake bake mongo_migration AddNameIndexToProducts name:string:unique
```

This will generate:

```php
<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseMigration;

class AddNameIndexToProducts extends BaseMigration
{
    public function change(): void
    {
        $collection = $this->collection('products');
        $collection->addColumn('name', 'string', [
            'length' => 255,
        ]);
        $collection->addIndex(['name' => 1], ['unique' => true]);
        $collection->update();
    }
}
```

## Adding a Field with a Default Value

You can specify default values for fields using the `default[value]` syntax:

```bash
bin/cake bake mongo_migration AddActiveToUsers active:boolean:default[true]
```

This will generate:

```php
<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseMigration;

class AddActiveToUsers extends BaseMigration
{
    public function change(): void
    {
        $collection = $this->collection('users');
        $collection->addColumn('active', 'boolean', [
            'default' => true,
        ]);
        $collection->update();
    }
}
```

You can combine default values with other options like nullable and indexes:

```bash
bin/cake bake mongo_migration AddStatusToOrders status:string:default['pending']:unique
```

## Altering a Field

In the same way, you can generate a migration to alter a field if the migration
name is of the form `AlterXXXOnYYY`:

```bash
bin/cake bake mongo_migration AlterPriceOnProducts price:decimal128[7,2]
```

This will generate a migration that re-adds the field with the new definition
and updates the collection:

```php
<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseMigration;

class AlterPriceOnProducts extends BaseMigration
{
    public function change(): void
    {
        $collection = $this->collection('products');
        $collection->addColumn('price', 'decimal128', [
            'precision' => 7,
            'scale' => 2,
        ]);
        $collection->update();
    }
}
```

> [!WARNING]
> Changing the type of a field can result in data loss if the current and
> target field type are not compatible. For example, converting a string to a
> numeric type.

## Removing a Field

In the same way, you can generate a migration to remove a field if the
migration name is of the form `RemoveXXXFromYYY`:

```bash
bin/cake bake mongo_migration RemovePriceFromProducts price
```

This creates:

```php
<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseMigration;

class RemovePriceFromProducts extends BaseMigration
{
    public function up(): void
    {
        $collection = $this->collection('products');
        $collection->removeField('price');
        $collection->update();
    }
}
```

> [!NOTE]
> `removeField()` is not reversible, so it must be called in the `up()` method.
> Add a corresponding `addColumn()` call to the `down()` method.
