# Bake Console

The Bake console is another effort to get you up and running with Crustum Mongo
fast. The console can create any of the ODM's basic ingredients: collections,
documents, models, controllers, view templates, fixtures, tests, and enums.

Bake is provided by the `Crustum/Mongo` plugin itself. It re-uses the CakePHP
Bake machinery (Twig templates, themes, events) but ships Mongo-native commands
and templates that generate ODM code.

## Installation

Bake is part of the `Crustum/Mongo` plugin — loading the plugin makes the
Mongo bake commands available. The stock `cakephp/bake` plugin is used under
the hood as a development dependency:

```bash
composer require --dev cakephp/bake:"^3.0"
```

The above installs Bake as a development dependency, so it will not be
installed during production deployments. The `Crustum/Mongo` plugin provides
the Mongo templates and commands on top of it.

## Running Bake

Run Bake with the CLI entry point of your application:

```bash
bin/cake bake
```

On Windows, use `bin\cake bake`.

The plugin registers the following Mongo bake commands:

- `bake collection` — a `Model/Collection` class
- `bake document` — a `Model/Document` class (attribute-based)
- `bake mongo_model` — a full model: Document + Collection + fixture + test
- `bake mongocontroller` — a controller
- `bake mongotemplate` — view templates (index/add/edit/view)
- `bake mongofixture` — a test fixture
- `bake mongotest` — a unit test
- `bake mongo_enum` — a backed enum

The plugin also registers the migration commands (`bake mongo_migration`,
`bake mongo_seed`, and friends) — see [Migrations](../migrations).

## Documentation Map

- [Code Generation with Bake](../bake/usage) covers running the console, listing
  commands, baking models and enums, and changing bake themes.
- [Extending Bake](../bake/development) covers events, Twig templates, custom
  themes, application template overrides, and creating custom bake commands.
