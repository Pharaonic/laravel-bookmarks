# Maintaining This Package

This file records package-specific maintainer facts that are safe to keep in the public repository.

## Purpose

Polymorphic bookmarks between any two Eloquent models, each with JSON-object metadata: `CanBookmark` for the model that bookmarks, `HasBookmarks` for the model that is bookmarked, one `bookmarks` table whose unique index keeps each pair to one row.

## Supported Laravel lines

One branch per Laravel major, from `6.x` to the newest major. Each line's PHP range, `illuminate/*` constraint, and tools follow the Pharaonic Laravel line table. `6.x` runs PHPUnit; later lines run Pest.

## Core library

None. Everything the package does is Eloquent glue.

## Public API invariants

Configuration keys (`pharaonic.bookmarks.*`), publish tags, and registered Blade, Artisan, macro, and validation names are public API.

- Configuration: `pharaonic.bookmarks.table` (default `bookmarks`), `pharaonic.bookmarks.model` (default `Models\Bookmark`, or a subclass), and `pharaonic.bookmarks.cleanup_on_delete` (default `true`).
- Publish tags: `pharaonic`, `laravel-bookmarks`, `pharaonic-config`, `bookmarks-config`, `pharaonic-migrations`, and `bookmarks-migrations`.
- `CanBookmark`: `givenBookmarks()`, `bookmark()`, `bookmarkOf()`, `hasBookmarked()`, `unbookmark()`, `toggleBookmark()`. `HasBookmarks`: `bookmarks()`, `bookmarkedBy()`, `bookmarkBy()`, `unbookmarkBy()`, `scopeWhereBookmarkedBy()`, `scopeWhereNotBookmarkedBy()`, `scopeWithBookmarkedBy()` (the `is_bookmarked` column). Neither trait defines a method of the other, so a model can use both.
- `bookmark()` and `bookmarkBy()` return the stored bookmark: created, replaced, or unchanged. Metadata replaces the whole object, never merges; `null` (or no argument) keeps it, and `[]` empties it to `{}`. Equal metadata (same JSON, any key order) runs no UPDATE and dispatches no event. `unbookmark()` and `unbookmarkBy()` return whether the call deleted the row; `toggleBookmark()` returns the new bookmark, or null when the pair has none afterwards. Reads return false or null for an unsaved model; the writes throw `InvalidArgumentException` for one.
- `Models\Bookmark` with `bookmarker()`, `bookmarkable()`, `scopeWhereBookmarker()`, `scopeWhereBookmarkable()`, the `meta` accessor and mutator, and the columns `bookmarker_type`, `bookmarker_id`, `bookmarkable_type`, `bookmarkable_id`, `meta`.
- `ValueObjects\BookmarkMeta`, immutable: property access, `get()`, `has()`, `isEmpty()`, `toArray()`, `toObject()`, `jsonSerialize()`. It is deliberately not `Arrayable`, so `toJson()` keeps `{}` and `[]` apart.
- `Events\BookmarkCreated`, `Events\BookmarkUpdated`, and `Events\BookmarkRemoved`, each with public `$bookmark`, `$bookmarker`, and `$bookmarkable`, dispatched only when the call inserted, changed, or deleted the row.
- Errors are SPL exceptions: `InvalidArgumentException` for input, `UnexpectedValueException` for a stored value that is not a JSON object or a `model` setting that is not a Bookmark, `LogicException` for writing to `BookmarkMeta`, `RuntimeException` when a model event cancels the save.
- `Support\BookmarkManager`, `Support\MetaNormalizer`, and `Relations\BookmarksMorphMany` are internal.

## Package-specific constraints

- The migration file name `2026_11_13_000001_create_pharaonic_bookmarks_table.php` and its class `CreatePharaonicBookmarksTable` never change. The class is named because `6.x` supports Laravel below 8.37.
- Later schema changes go in new migrations, never in the released one.
- The migration refuses to run over an existing table, and creates the table with the configured model's table name on the configured model's connection.
- `meta` is an accessor and mutator, not a cast class: custom casts need Laravel 7, and `ComparesCastableAttributes` needs Laravel 12.x, so neither can be in the shared code. The mutator writes canonical JSON (keys sorted at every depth, floats with a fraction) and keeps the stored string when the new metadata is equal, so the attribute stays clean even when the database reformatted it (MySQL does).
- Metadata comparison is canonical-JSON equality: key order is ignored at every depth; list order and JSON types are not (`1`, `1.0`, `"1"`, and `true` differ). Stored metadata comes back with sorted keys.
- Every write runs in a transaction on the model's connection, through `Connection::transaction($callback, 3)`: outside a caller's transaction Laravel retries deadlocks and SQLite "database is locked"; inside one, the error goes to the caller. The insert runs in a savepoint, so a refused insert leaves PostgreSQL's transaction usable.
- Uniqueness comes from the `{table}_unique_pair` index. Its violation is told apart from any other unique index by name (MySQL, MariaDB, PostgreSQL, SQL Server) or by its four columns (SQLite); any other violation is thrown at once. After the pair's violation, the row is read with `lockForUpdate()` inside the transaction, so MySQL's REPEATABLE READ snapshot cannot hide it and a concurrent delete cannot slip in before the update.
- On MySQL and PostgreSQL the write reads first, so replacing existing metadata locks the row directly instead of upgrading a shared lock. On SQLite it inserts first: a SQLite transaction that reads first fails at once instead of waiting when it then writes.
- Removal is a query-builder delete checked by affected rows, so Eloquent `deleting`/`deleted` events do not run for it. Cleanup on delete is a bulk delete and dispatches no `BookmarkRemoved`.
- Events are dispatched right after the write's own transaction, so inside a caller's transaction they come before it commits, because `ShouldDispatchAfterCommit` needs Laravel 10.30.
- Ids are stored as strings (`varchar(64)`), so the bookmark relations compare the parent key as a string in `has()`, `whereHas()`, and `withCount()` (`BookmarksMorphMany`): PostgreSQL refuses `bigint = varchar` and `uuid = varchar`. MySQL uses `CONCAT()` rather than `CAST()`, which fails when the connection collation differs from the table's. A model on another connection cannot be used in `whereHas()`-style subqueries across databases.
- `phpstan.neon.dist` also analyses `tests/Fixtures`: PHPStan analyses a trait only through a class that uses it.
- `ConcurrencyTest` runs separate PHP processes against a shared database: a SQLite file by default, MySQL or PostgreSQL through the `BOOKMARKS_DB*` environment variables (`.github/workflows/databases.yml`).

## Branch notes

Fixes land on the oldest affected line and are ported to every later line and `develop`.

## Documentation

https://pharaonic.dev/packages/laravel/bookmarks

Keep this file factual and package-specific. General Pharaonic engineering policy belongs in the private engineering repository, not here.
