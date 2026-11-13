## Installation

Install the package with Composer and create its table.

### Requirements

- PHP 8.0
- Laravel 7
- MySQL, MariaDB, PostgreSQL or SQLite

### Composer Installation

```bash title="Terminal" no-line-numbers
composer require pharaonic/laravel-bookmarks
```

The service provider is registered automatically.

### Run Migrations

The package loads its migration automatically, so the `bookmarks` table is created with your other migrations:

```bash title="Terminal" no-line-numbers
php artisan migrate
```

To change the migration before it runs, publish it into `database/migrations`:

```bash title="Terminal" no-line-numbers
php artisan vendor:publish --tag=bookmarks-migrations
```

The published file keeps the package's file name, `2026_11_13_000001_create_pharaonic_bookmarks_table.php`, so Laravel runs your copy instead of the package's, never both.

:::warning Existing bookmarks table
The migration stops with an error when the table already exists. Set `pharaonic.bookmarks.table` to another name before migrating, or move that table out of the way first.
:::

### Publish Configuration

Publish the configuration only when you need another table name, your own Bookmark model, or want to turn off cleanup on delete:

```bash title="Terminal" no-line-numbers
php artisan vendor:publish --tag=bookmarks-config
```

The file is published to `config/pharaonic/bookmarks.php`.

:::success Installation Complete
You're all set! Add `CanBookmark` and `HasBookmarks` to your models, as [Basic Usage](#basic-usage) shows.
:::
