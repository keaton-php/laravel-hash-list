<!-- nav-group: Start here -->
# Installation

## Requirements

- PHP 8.2 or higher, with `ext-mbstring` and `ext-intl`
- Laravel 11, 12 or 13 (Laravel 13 needs PHP 8.3)
- MySQL, PostgreSQL or SQLite

`ext-intl` converts international domains to punycode, so an address gives the same hash on every server.

The package uses Laravel's `insertOrIgnore()`, which SQL Server doesn't support. The tests run on SQLite, MySQL 8.4 and PostgreSQL 16. MariaDB is not tested.

## Installing the package

You can install the package via composer, and publish and run the migration:

```bash
composer require keaton-php/laravel-hash-list
php artisan vendor:publish --tag="hash_list-migrations"
php artisan migrate
```

The migration creates one table, `hash_list_entries`. The service provider is discovered automatically.

## Configuration

You can publish the config file with:

```bash
php artisan vendor:publish --tag="hash_list-config"
```

| Key (`.env`) | Default | What it does |
|---|---|---|
| `algorithm` (`HASH_LIST_ALGORITHM`) | `sha256` | `sha256` or `md5`. The whole table uses one algorithm. |
| `table` (`HASH_LIST_TABLE`) | `hash_list_entries` | The table the package uses. If you change it, also change `$table` in the published migration before you migrate. |
| `connection` (`HASH_LIST_CONNECTION`) | `null` | The database connection for the table. `null` uses the default connection. If you change it, also set `$connection` in the published migration. |
| `default_list` (`HASH_LIST_DEFAULT_LIST`) | `default` | The list that is used when you don't call `on()`. |
| `strip_plus_tags` (`HASH_LIST_STRIP_PLUS_TAGS`) | `false` | Treats `user+tag@host` as `user@host` before hashing. |
| `chunk_size` | `500` | The number of rows per insert on import, and per query on export. Each row binds 3 values, so lower it on SQLite older than 3.32, which allows 999 bound values. |
| `guard` (`HASH_LIST_GUARD`) | `false` | Turns on the send-time guard. |
| `guard_list` | `null` | The list the guard checks. `null` uses `default_list`. |

An invalid setting throws `InvalidConfiguration`. This covers an unknown algorithm, a missing list name, a chunk size below 1, and a mistyped boolean such as `HASH_LIST_GUARD=ture`. Booleans accept `true`/`false`, `1`/`0`, `on`/`off` and `yes`/`no`. A missing or empty value is off.

**Next:** [Basic usage](03-basic-usage.md).
