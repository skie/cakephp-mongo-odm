# Code Generation with Bake

### PENDING
> Samples below are verified against `src/Command/Bake/*` and the live baked
> output in `mongo-testapp`. The exact `bin/cake bake --help` listing should be
> re-checked against a real run before this banner is removed.

The Bake console is run using the PHP CLI. If you have problems running the
script, ensure that:

1. You have the PHP CLI installed and that it has the proper modules enabled
   (`intl`, and the MongoDB extension).
2. If the database host is `localhost`, try `127.0.0.1` instead, as `localhost`
   can cause issues with PHP CLI.
3. Depending on how your computer is configured, you may need to set execute
   rights on the Cake shell script to call it using `bin/cake bake`.

Before running Bake you should make sure you have at least one Mongo connection
configured (in `config/mongo.php`).

You can get the list of available bake commands by running `bin/cake bake --help`.
For Windows usage use `bin\cake bake --help`:

```bash
$ bin/cake bake --help
Current Paths:

* app:  src/
* root: /path/to/your/app/
* core: /path/to/your/app/vendor/cakephp/cakephp/

Available Commands:

Bake:
- bake collection
- bake document
- bake mongo_model
- bake mongocontroller
- bake mongotemplate
- bake mongofixture
- bake mongotest
- bake mongo_enum
- bake mongo_migration
- bake mongo_migration_diff
- bake mongo_migration_snapshot
- bake mongo_migration_simple
- bake mongo_seed

To run a command, type `cake command_name [args|options]`
To get help on a specific command, type `cake command_name --help`
```

The migration bake commands (`mongo_migration`, `mongo_seed`, …) are covered in
the [Migrations](../migrations) guide.

## Bake Models

Models are baked from existing Mongo collections. ODM conventions apply, so
Bake detects relations from `*_id` fields typed as ObjectId:

- An `objectid` field named `{name}_id` that points at an existing collection
  becomes a `belongsTo` relation.
- Collections holding a foreign key that points back at the current collection
  become `hasOne` (unique index) or `hasMany` (non-unique).
- A join collection named `<source>_<target>` (or `<target>_<source>`) with
  `<source>_id` and `<target>_id` becomes a `belongsToMany` relation.

Baking a model generates a Document, a Collection, a fixture, and a test:

```bash
bin/cake bake mongo_model Articles
```

This generates `src/Model/Collection/ArticlesCollection.php`,
`src/Model/Document/Article.php`, `tests/Fixture/ArticlesFixture.php`, and
`tests/TestCase/Model/Collection/ArticlesCollectionTest.php`.

The baked Document uses attributes to describe its fields and collection:

```php
namespace App\Model\Document;

use Crustum\Mongo\Database\Schema\CollectionSchemaInterface;
use Crustum\Mongo\ODM\Attribute\Document as DocumentAttribute;
use Crustum\Mongo\ODM\Attribute\Field;
use Crustum\Mongo\ODM\Document;

#[DocumentAttribute(collection: 'articles')]
#[Field(name: 'title', type: CollectionSchemaInterface::TYPE_STRING)]
#[Field(name: 'user_id', type: CollectionSchemaInterface::TYPE_OBJECTID)]
#[Field(name: '_id', type: CollectionSchemaInterface::TYPE_OBJECTID, primaryKey: true)]
class Article extends Document
{
    protected array $_accessible = [
        'title' => true,
        'user_id' => true,
        // ...
    ];
}
```

The baked Collection wires up the document class, schema, associations,
validation, and rules:

```php
namespace App\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;

class ArticlesCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setDocumentClass(Article::class);
        $this->setSchemaFromDocument(Article::class);
        $this->setDisplayField('title');
        $this->setPrimaryKey('_id');

        $this->addBehavior('Timestamp');
        $this->belongsTo('Users', ['foreignKey' => 'user_id']);
        $this->hasMany('Comments', ['foreignKey' => 'article_id']);
        $this->belongsToMany('Tags', [
            'foreignKey' => 'article_id',
            'targetForeignKey' => 'tag_id',
            'joinCollection' => 'articles_tags',
        ]);
    }
}
```

### Options

`bake mongo_model` accepts the usual Bake options plus Mongo-specific skips.
Common examples:

```bash
# Bake a model without the test file
bin/cake bake mongo_model Articles --no-test

# Bake a model without validation and rules
bin/cake bake mongo_model Articles --no-validation --no-rules

# Bake only the Document, without a Collection, fixture, or test
bin/cake bake mongo_model Articles --no-collection --no-fixture --no-test

# Bake a model from a specific connection / collection
bin/cake bake mongo_model Articles --connection mongo --collection articles
```

You can also bake either half of a model on its own:

```bash
bin/cake bake collection Articles
bin/cake bake document Article --collection articles
bin/cake bake document Article --collection articles --schema-file config/MongoMigrations/schema-dump-mongo.lock
```

## Bake Enums

You can use Bake to generate [backed
enums](https://www.php.net/manual/en/language.enumerations.backed.php) for use
in your models. Enums are placed in `src/Model/Enum/` and implement
`EnumLabelInterface`, which provides a `label()` method for human-readable
display.

To bake a string-backed enum:

```bash
bin/cake bake mongo_enum ArticleStatus draft,published,archived
```

This generates `src/Model/Enum/ArticleStatus.php`:

```php
namespace App\Model\Enum;

use Cake\Database\Type\EnumLabelInterface;
use Cake\Utility\Inflector;

enum ArticleStatus: string implements EnumLabelInterface
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return Inflector::humanize(Inflector::underscore($this->name));
    }
}
```

For int-backed enums, use the `--int` option and provide values with colons:

```bash
bin/cake bake mongo_enum Priority low:1,medium:2,high:3 --int
```

This generates an int-backed enum:

```php
enum Priority: int implements EnumLabelInterface
{
    case Low = 1;
    case Medium = 2;
    case High = 3;

    // ...
}
```

You can also bake enums into plugins:

```bash
bin/cake bake mongo_enum MyPlugin.OrderStatus pending,processing,shipped
```

## Bake Themes

The `theme` option is common to all bake commands and allows changing the bake
template files used when baking. To create your own templates, see [Creating a
Bake Theme](../bake/development#creating-a-bake-theme).
