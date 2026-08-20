# Running and Managing Migrations

### PENDING
> Command names/options below are verified against `src/Migration/Command/*`
> option parsers. Re-check the exact CLI output against a live run before
> removing this banner.

This guide covers the commands you use after writing or generating migration
files. All commands take the common `-p`/`--plugin`, `-c`/`--connection`, and
`-s`/`--source` options.

## Applying Migrations

Once you have generated or written your migration file, apply the changes to
your database:

```bash
# Run all the migrations
bin/cake mongo migrations migrate

# Migrate to a specific version using the --target option
bin/cake mongo migrations migrate --target 20260818001951

# Migrate up to a specific date
bin/cake mongo migrations migrate --date 2026-08-18

# Run migrations from a custom source directory
bin/cake mongo migrations migrate --source Alternate

# Run migrations against a different connection
bin/cake mongo migrations migrate --connection my_custom_connection

# Run migrations for a plugin
bin/cake mongo migrations migrate --plugin MyAwesomePlugin

# Dry-run (only record the run, do not execute)
bin/cake mongo migrations migrate --fake
```

## Reverting Migrations

The `rollback` command undoes previously executed migrations:

```bash
# Roll back to the previous migration
bin/cake mongo migrations rollback

# Roll back to a specific version
bin/cake mongo migrations rollback --target 20260818001951

# Roll back a specific number of migrations
bin/cake mongo migrations rollback --count 3

# Roll back even if a migration is not reversible
bin/cake mongo migrations rollback --force
```

You can also use the `--source`, `--connection`, and `--plugin` options just
like for the `migrate` command.

## Viewing Migration Status

The `status` command prints a list of all migrations, along with their current
status:

```bash
bin/cake mongo migrations status
```

You can also output the results as JSON:

```bash
bin/cake mongo migrations status --format json
```

You can also use the `--source`, `--connection`, and `--plugin` options just
like for the `migrate` command.

## Marking a Migration as Migrated

It can sometimes be useful to mark a set of migrations as migrated without
actually running them. In order to do this, use the `mark_migrated` command.

You can mark all migrations as migrated:

```bash
bin/cake mongo migrations mark_migrated
```

You can also mark all migrations up to a specific version using the `--target`
option:

```bash
bin/cake mongo migrations mark_migrated --target 20260818001951
```

If you do not want the targeted migration to be marked as migrated during the
process, use the `--exclude` flag:

```bash
bin/cake mongo migrations mark_migrated --target 20260818001951 --exclude
```

If you wish to mark only the targeted migration as migrated, use the `--only`
flag:

```bash
bin/cake mongo migrations mark_migrated --target 20260818001951 --only
```

You can also use the `--source`, `--connection`, and `--plugin` options just
like for the `migrate` command.

This command also accepts the migration version number as a positional
argument:

```bash
bin/cake mongo migrations mark_migrated 20260818001951
```

If you wish to mark all migrations as migrated, you can use the `all` special
value:

```bash
bin/cake mongo migrations mark_migrated all
```

## Seeding Your Database

Seed classes are a good way to populate your database with default or starter
data. They are also useful for generating data for development environments.

By default, seeds are looked for in the `config/MongoSeeds/` directory of your
application. See [Database Seeding](../migrations/guides/seeding) for how to
build and use seed classes.
