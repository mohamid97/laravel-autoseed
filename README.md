# Laravel AutoSeed

Laravel AutoSeed inspects your database schema and fills eligible tables with
Faker-generated data. It uses column types, column names, foreign keys, and
unique indexes to create useful development and testing data without requiring
you to write a factory for every table.

> **Warning:** This package inserts and can truncate database data. Use it only
> with development or test databases. The command refuses to run in production
> unless you explicitly pass `--force`.

## Requirements

- PHP 8.2 or later
- Laravel 11 or 12
- A database driver supported by Laravel's schema inspection APIs

## Installation

Install it as a development dependency:

```bash
composer require --dev muhmd/laravel-autoseed
```

Laravel package discovery registers the service provider and the `autoseed:run`
Artisan command automatically.

## Quick start

Run the command to seed eligible tables with the configured default row count
(50 rows per table by default):

```bash
php artisan autoseed:run
```

For a clean development database, migrate first, then seed:

```bash
php artisan migrate:fresh
php artisan autoseed:run
```

To generate different row counts or target particular tables:

```bash
# Generate 200 rows per selected table count
php artisan autoseed:run --rows=200

# Override individual table counts
php artisan autoseed:run --table=users:1000 --table=order_items:x10

# Seed only the named tables
php artisan autoseed:run --only=users --only=orders

# Skip named tables
php artisan autoseed:run --except=logs --except=audit_events

# Multiply all computed row counts by five
php artisan autoseed:run --multiplier=5

# Truncate selected tables before seeding
php artisan autoseed:run --fresh

# Set Faker's random seed
php artisan autoseed:run --seed=42
```

`--fresh` truncates the tables selected for this run; it does not run
migrations. Make sure the selected tables can safely be emptied. When using
`--only`, parent tables are not automatically included, so required referenced
rows must already exist.

## Command options

| Option | Description |
| --- | --- |
| `--rows=N` | Use `N` as the default row count for each table in this run. |
| `--table=TABLE:COUNT` | Override one table's count. Repeat for multiple tables. |
| `--only=TABLE` | Seed only the named table(s). Repeat to name multiple tables. |
| `--except=TABLE` | Skip the named table(s). Repeat to name multiple tables. |
| `--multiplier=N` | Multiply each computed table count. Defaults to `1`. |
| `--fresh` | Truncate selected tables before inserting rows. |
| `--seed=N` | Seed Faker's generator. Some other random choices are not seeded. |
| `--force` | Allow running in a Laravel production environment. Use with care. |

Table counts can be exact numbers or a multiplier of the base row count:

- `--table=users:1000` creates 1,000 users (before applying `--multiplier`).
- `--table=order_items:x10` creates ten times the base count for `order_items`.

The base count is `--rows` when supplied, otherwise `default_rows` from the
package configuration. Table-specific overrides take precedence over that base
count, and `--multiplier` is applied last.

## Configuration

Publish the configuration file if you want to customize defaults:

```bash
php artisan vendor:publish --tag=autoseed-config
```

This creates `config/autoseed.php`:

| Setting | Default | Purpose |
| --- | ---: | --- |
| `default_rows` | `50` | Default rows to generate per table. |
| `tables` | `[]` | Per-table count overrides; accepts numbers or strings like `'x10'`. |
| `exclude` | Laravel infrastructure tables | Table names/patterns to skip. Patterns use `fnmatch`. |
| `morph_map` | `[]` | Morph alias to table mappings, merged with Laravel's morph map. |
| `chunk_size` | `500` | Rows per bulk insert. |
| `pool_limit` | `50000` | Maximum parent rows loaded in memory per foreign key. |
| `null_chance` | `10` | Percentage chance of using `NULL` for a nullable generated column. |
| `self_ref_pct` | `70` | Percentage of rows linked to another row in a supported self-referencing table. |
| `locale` | `en_US` | Faker locale; can also be set with `FAKER_LOCALE`. |

The default excluded tables include Laravel migration, queue, cache, session,
and token tables, as well as Telescope and Pulse tables.

## How it works

1. Inspects tables, columns, primary keys, unique indexes, and foreign keys
   using Laravel's schema APIs.
2. Orders tables so referenced parent tables are generally seeded before
   dependent tables.
3. Generates values based on the column's database type and, where useful, its
   name (for example, `email`, `created_at`, `price`, or `status`).
4. Uses available referenced rows for foreign keys, including composite foreign
   keys. It also recognizes conventional polymorphic `*_type` / `*_id` pairs
   when a morph map is configured.
5. Inserts generated rows in batches.

Generated values may include names, email addresses, dates, numbers, booleans,
JSON, UUIDs, enum values, and other common data. Nullable columns may be set to
`NULL` according to `null_chance`; nullable `deleted_at` columns are generated
as `NULL`. Generated password values are Laravel hashes of the literal
`password`.

## Important limitations

- This generates dummy data from schema information; it does not run Eloquent
  model factories, events, observers, or application-specific business logic.
- It cannot infer every application's validation rules or domain constraints.
  Review the generated data before relying on it in tests.
- Required foreign keys whose parent table has no rows can cause a table to be
  skipped with a warning. Cyclic dependencies may not be seedable in every
  schema.
- Faker's `--seed` option seeds Faker values, but other randomized choices are
  not fully deterministic, so identical complete database contents are not
  guaranteed across runs.
- Running with `--force` in production is dangerous. The package does not
  provide a production-data safety net.

## Contributing

Issues and pull requests are welcome. When reporting a problem, include your
PHP and Laravel versions, database driver, and a minimal description of the
relevant schema. Do not include credentials or production data.

## License

This package is open-sourced under the [MIT License](LICENSE).
