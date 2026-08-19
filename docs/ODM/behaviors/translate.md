---
title: "Translate"
description: "Translate documents in the Crustum Mongo ODM: store multi-language content, retrieve translations, configure TranslateBehavior for internationalization."
---

# Translate

`class` Crustum\Mongo\ODM\Behavior\**TranslateBehavior**

> ### PENDING
>
> The examples below are ported from the Cake ORM cookbook and rewritten for the
> ODM's two storage strategies (verified against
> `src/ODM/Behavior/TranslateBehavior.php` and the translate test suites). They
> have not been executed against a live `test_mongo_db` yet — run them before
> removing this banner.

The Translate behavior allows you to create and retrieve translated copies
of your documents in multiple languages.

> [!NOTE]
> The behavior resolves the collection's primary key from its first column
> (defaulting to `_id`). Translate operations require a single-value key.

## Translation Strategies

The behavior offers two strategies for how the translations are stored.

1. **Shadow collection strategy**: stores translations in a separate
   `{ReferenceName}Translations` collection, one document per locale, linked to
   the source document by `_shadow_id`. This is the default strategy.
2. **Embed strategy**: embeds the translations inside the source document
   itself, under a single field (by default `_translations`) keyed by locale.

The Cake SQL ORM's EAV (`i18n`) strategy has **no Mongo analog** — it is not
implemented. If you need per-field, per-locale rows you must use the shadow
collection strategy.

## Shadow Collection Strategy

Let's assume we have an `articles` collection and we want its `title` and `body`
fields to be translated. For that we create a shadow collection
`articles_translations`. Each document in it corresponds to one source document
for one locale:

```js
// articles_translations
{ "_id": ObjectId(...), "_shadow_id": "64c7c2e3a1b2c3d4e5f6a7b8", "locale": "es", "title": "Un Artículo", "body": "..." }
```

The shadow collection needs `_shadow_id` and `locale` fields, plus one field
with the same name as each translated field in the main collection.

When reading, the active locale's translation is joined to the source documents
with a single `$lookup`/`$unwind` pipeline (matched on `_shadow_id` and
`locale`) — there are no SQL joins. Filtering or ordering on a translated field
makes the behavior add that lookup automatically.

A note on language abbreviations: The Translate Behavior doesn't impose any
restrictions on the language identifier. It is wise to use the same language
abbreviations as required for
[Internationalization and Localization](../../core-libraries/internationalization-and-localization)
so that switching the language works identically for both the `Translate`
behavior and `Internationalization and Localization`.

So it's recommended to use either the two letter ISO code of the language like
`en`, `fr`, `de` or the full locale name such as `fr_FR`, `es_AR`, `da_DK`
which contains both the language and the country where it is spoken.

## Embed Strategy

The embed strategy is native to the ODM: translations live inside the source
document, so there is no second collection and no `$lookup`. The default locale
stays in the root fields (Cake semantics), every other locale lives in an
embedded map:

```js
// articles
{
  "_id": "64c7c2e3a1b2c3d4e5f6a7b8",
  "title": "My First Article",
  "_translations": {
    "de_DE": { "title": "Mein erster Artikel" },
    "fr_FR": { "title": "Mon premier article" }
  }
}
```

Reads merge the active locale's fields into the root document with a plain
result formatter, and writes persist the `_translations` field as part of the
document. Translated fields can be filtered and ordered with dotted paths
(`_translations.de_DE.title`). The storage field name is configurable via the
`embedField` option (default `_translations`).

## Attaching the Translate Behavior to Your Collections

Attaching the behavior can be done in the `initialize()` method in your
Collection class:

```php
class ArticlesCollection extends Collection
{
    public function initialize(array $config): void
    {
        // By default, the shadow collection strategy will be used.
        $this->addBehavior('Translate', ['fields' => ['title', 'body']]);
    }
}
```

For the shadow strategy specifying the `fields` key is optional, as the
behavior can infer the fields from the shadow collection's schema.

If you want to use the `EmbedStrategy` then you can configure the behavior as:

```php
class ArticlesCollection extends Collection
{
    public function initialize(array $config): void
    {
        $this->addBehavior('Translate', [
            'strategyClass' => \Crustum\Mongo\ODM\Behavior\Translate\EmbedStrategy::class,
            'fields' => ['title', 'body'],
        ]);
    }
}
```

For both strategies you are required to pass the `fields` key when the schema
cannot be used to infer them. This list of fields is needed to tell the
behavior what fields will be able to store translations.

By default, the locale specified in the `App.defaultLocale` config is used as
the default locale for the `TranslateBehavior`. You can override that by setting
the `defaultLocale` config of the behavior:

```php
class ArticlesCollection extends Collection
{
    public function initialize(array $config): void
    {
        $this->addBehavior('Translate', [
            'defaultLocale' => 'en_GB',
        ]);
    }
}
```

Setting `defaultLocale` to an empty string `''` disables a default locale
entirely: translated fields for *all* locales (including the application's
default) are stored in the translation store.

## Quick tour

Regardless of the data-structure strategy you choose the behavior provides the
same API to manage translations.

Now, select a language to be used for retrieving documents by changing the
application language, which will affect all translations:

```php
// In the Articles controller. Change the locale to Spanish, for example
I18n::setLocale('es');
```

Then, get an existing document:

```php
$article = $articles->get('64c7c2e3a1b2c3d4e5f6a7b8');
echo $article->title; // Echoes 'A title', not translated yet
```

Next, translate your document:

```php
$article->title = 'Un Artículo';
$articles->save($article);
```

You can try now getting your document again:

```php
$article = $articles->get('64c7c2e3a1b2c3d4e5f6a7b8');
echo $article->title; // Echoes 'Un Artículo', yay piece of cake!
```

Working with multiple translations can be done by using a special trait in your
Document class:

```php
use Crustum\Mongo\ODM\Behavior\Translate\TranslateTrait;
use Crustum\Mongo\ODM\Document;

class Article extends Document
{
    use TranslateTrait;
}
```

Now you can find all translations for a single document:

```php
$article = $articles->find('translations')->first();
echo $article->translation('es')->title; // 'Un Artículo'

echo $article->translation('en')->title; // 'An Article';
```

And save multiple translations at once:

```php
$article->translation('es')->title = 'Otro Título';
$article->translation('fr')->title = 'Un autre Titre';
$articles->save($article);
```

If you want to go deeper on how it works or how to tune the behavior for your
needs, keep on reading the rest of this chapter.

## Reading Translated Content

As shown above you can use the `setLocale()` method to choose the active
translation for documents that are loaded:

```php
// Load I18n core functions at the beginning of your Articles Controller:
use Cake\I18n\I18n;

// Then you can change the language in your action:
I18n::setLocale('es');

// All documents in results will contain spanish translation
$results = $articles->find()->all();
```

This method works with any finder in your collections. For example, you can
use TranslateBehavior with `find('list')`:

```php
I18n::setLocale('es');
$data = $articles->find('list')->toArray();

// Data will contain
['64c7c2e3a1b2c3d4e5f6a7b8' => 'Mi primer artículo', ...]

// Change the locale to french for a single find call
$data = $articles->find('list', locale: 'fr')->toArray();
```

### Retrieve All Translations For A Document

When building interfaces for updating translated content, it is often helpful
to show one or more translation(s) at the same time. You can use the
`translations` finder for this:

```php
// Find the first article with all corresponding translations
$article = $articles->find('translations')->first();
```

In the example above you will get a list of documents back that have a
`_translations` property set. This property will contain a list of translation
documents indexed by locale. For example the following properties would be
accessible:

```php
// Outputs 'en'
echo $article->_translations['en']->locale;

// Outputs 'My awesome post!'
echo $article->_translations['en']->body;
```

A more elegant way for dealing with this data is by adding a trait to the
document class that is used for your collection:

```php
use Crustum\Mongo\ODM\Behavior\Translate\TranslateTrait;
use Crustum\Mongo\ODM\Document;

class Article extends Document
{
    use TranslateTrait;
}
```

This trait contains a single method called `translation`, which lets you access
or create new translation documents on the fly:

```php
// Outputs 'My awesome post!'
echo $article->translation('en')->body;

// Adds a new translation document to the article
$article->translation('de')->title = 'Wunderbar';
```

### Limiting the Translations to be Retrieved

You can limit the languages that are fetched from the database for a particular
set of records:

```php
$results = $articles->find('translations', locales: ['en', 'es']);
$article = $results->first();
$spanishTranslation = $article->translation('es');
$englishTranslation = $article->translation('en');
```

### Preventing Retrieval of Empty Translations

Translation records can contain any string. If a record has been translated and
stored as an empty string (`''`), the translate behavior will take and use this
to overwrite the original field value.

If this is undesired, you can ignore translations which are empty using the
`allowEmptyTranslations` config key:

```php
class ArticlesCollection extends Collection
{
    public function initialize(array $config): void
    {
        $this->addBehavior('Translate', [
            'fields' => ['title', 'body'],
            'allowEmptyTranslations' => false,
        ]);
    }
}
```

The above would only load translated data that had content.

### Retrieving All Translations For Associations

It is also possible to find translations for any association in a single find
operation:

```php
$article = $articles->find('translations')->contain([
    'Categories' => function ($query) {
        return $query->find('translations');
    },
])->first();

// Outputs 'Programación'
echo $article->categories[0]->translation('es')->name;
```

This assumes that `Categories` has the TranslateBehavior attached to it. It
simply uses the query builder function for the `contain` clause to use the
`translations` custom finder in the association.

### Retrieving one language without using I18n::setLocale

Calling `I18n::setLocale('es');` changes the default locale for all translated
finds. There may be times you wish to retrieve translated content without
modifying the application's state. For these scenarios use the behavior's
`setLocale()` method:

```php
I18n::setLocale('en'); // reset for illustration

// specific locale.
$articles->getBehavior('Translate')->setLocale('es');

$article = $articles->get('64c7c2e3a1b2c3d4e5f6a7b8');
echo $article->title; // Echo 'Un Artículo', yay piece of cake!
```

Note that this only changes the locale of the Articles collection, it would not
affect the language of associated data. To affect associated data it's
necessary to call the method on each collection, for example:

```php
I18n::setLocale('en'); // reset for illustration

$articles->getBehavior('Translate')->setLocale('es');
$articles->Categories->getBehavior('Translate')->setLocale('es');

$data = $articles->find('all', contain: ['Categories']);
```

This example also assumes that `Categories` has the TranslateBehavior attached
to it.

> [!NOTE]
> When a document carries a `_locale` property, that locale wins over both the
> locale set with `setLocale()` and the globally configured one.

### Querying Translated Fields

With the shadow strategy, conditions on translated fields are not substituted
automatically: the behavior only detects them to add the translations lookup.
Use the `translationField()` method to compose find conditions on translated
fields:

```php
$articles->getBehavior('Translate')->setLocale('es');
$query = $articles->find()->where([
    $articles->getBehavior('Translate')->translationField('title') => 'Otro Título',
]);
```

For the current locale, `translationField()` returns the collection-aliased
field for default-locale fields and the translated alias for the rest. With the
`EmbedStrategy`, translated fields referenced in `where`/`order` clauses are
rewritten automatically to the active locale's dotted path
(`_translations.{locale}.{field}`), so you can filter or sort on a translated
field directly:

```php
$articles->getBehavior('Translate')->setLocale('es');
$query = $articles->find()->where(['title' => 'Otro Título']);
```

## Saving in Another Language

The philosophy behind the TranslateBehavior is that you have a document
representing the default language, and multiple translations that can override
certain fields in such document. Keeping this in mind, you can intuitively save
translations for any given document. For example, given the following setup:

```php
// in src/Model/Collection/ArticlesCollection.php
class ArticlesCollection extends Collection
{
    public function initialize(array $config): void
    {
        $this->addBehavior('Translate', ['fields' => ['title', 'body']]);
    }
}

// in src/Model/Document/Article.php
class Article extends Document
{
    use TranslateTrait;
}

// In the Articles Controller
$article = new Article([
    'title' => 'My First Article',
    'body' => 'This is the content',
    'footnote' => 'Some afterwords',
]);

$articles->save($article);
```

So, after you save your first article, you can now save a translation for it.
There are a couple ways to do it. The first one is setting the language directly
into the document:

```php
$article->_locale = 'es';
$article->title = 'Mi primer Artículo';

$articles->save($article);
```

After the document has been saved, the translated field will be persisted as
well. One thing to note is that values from the default language that were not
overridden will be preserved:

```php
// Outputs 'This is the content'
echo $article->body;

// Outputs 'Mi primer Artículo'
echo $article->title;
```

Once you override the value, the translation for that field will be saved and
can be retrieved as usual:

```php
$article->body = 'El contenido';
$articles->save($article);
```

The second way to use for saving documents in another language is to set the
default language directly to the collection:

```php
$article->title = 'Mi Primer Artículo';

$articles->getBehavior('Translate')->setLocale('es');
$articles->save($article);
```

Setting the language directly in the collection is useful when you need to both
retrieve and save documents for the same language or when you need to save
multiple documents at once.

## Saving Multiple Translations

It is a common requirement to be able to add or edit multiple translations to
any database record at the same time. This can be done using the
`TranslateTrait`:

```php
use Crustum\Mongo\ODM\Behavior\Translate\TranslateTrait;
use Crustum\Mongo\ODM\Document;

class Article extends Document
{
    use TranslateTrait;
}
```

Now, you can populate translations before saving them:

```php
$translations = [
    'fr' => ['title' => 'Un article'],
    'es' => ['title' => 'Un artículo'],
];

foreach ($translations as $lang => $data) {
    $article->translation($lang)->set($data, ['guard' => false]);
}

$articles->save($article);
```

And create form controls for your translated fields:

```php
// In a view template.
<?= $this->Form->create($article); ?>
<fieldset>
    <legend>French</legend>
    <?= $this->Form->control('_translations.fr.title'); ?>
    <?= $this->Form->control('_translations.fr.body'); ?>
</fieldset>
<fieldset>
    <legend>Spanish</legend>
    <?= $this->Form->control('_translations.es.title'); ?>
    <?= $this->Form->control('_translations.es.body'); ?>
</fieldset>
```

In your controller, you can marshal the data as normal:

```php
$article = $articles->newDocument($this->request->getData());
$articles->save($article);
```

This will result in your article, the french and spanish translations all being
persisted. You'll need to remember to add `_translations` into the accessible
fields of your document as well.

### Validating Translated Documents

When attaching `TranslateBehavior` to a model, you can define the validator
that should be used when translation records are created/modified by the
behavior during `newDocument()` or `patchDocument()`:

```php
class ArticlesCollection extends Collection
{
    public function initialize(array $config): void
    {
        $this->addBehavior('Translate', [
            'fields' => ['title'],
            'validator' => 'translated',
        ]);
    }
}
```

The above will use the validator created by `validationTranslated` to validate
translated documents.