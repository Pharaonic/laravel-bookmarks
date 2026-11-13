## Troubleshooting

### `The [bookmarks] table already exists` when migrating

Your application already has a `bookmarks` table. Set `pharaonic.bookmarks.table` to another name before migrating, or move that table out of the way first.

### `Bookmark metadata must be a JSON object, not a list`

You passed a list at the top, such as `['a', 'b']` or a JSON array. Wrap it in an object: `['items' => ['a', 'b']]`.

### `Bookmark metadata must be a JSON object … not string`

You passed a JSON string, for example a request field. Decode it first: `json_decode($json)` keeps `{}` and `[]` apart.

### `Bookmark metadata can only hold JSON values … not Illuminate\Support\Carbon (at meta.remind_at)`

Objects other than `stdClass` and `BookmarkMeta` are refused, wherever they are. Store a plain value: `$date->toIso8601String()` for a date, `$collection->all()` for a Collection.

### `Bookmark metadata cannot be null: assign [] to store the empty object {}`

You assigned `null` to `$bookmark->meta`. Assign `[]` to empty it. With `bookmark()`, `null` keeps the metadata instead.

### A key disappeared after `bookmark()`

New metadata replaces the whole object; nothing is merged. Pass the full object, as [Examples](#examples) shows for changing one key.

### `Bookmark metadata is read-only`

You wrote to `$bookmark->meta`. Build a new object (`$bookmark->meta->toObject()`, change it) and pass it to `bookmark()`, or assign it to `$bookmark->meta`.

### `The meta column of bookmark [12] in the [bookmarks] table holds NULL, not a JSON object`

The row was written outside the package with a value that isn't a JSON object. Repair it (`UPDATE bookmarks SET meta = '{}' WHERE meta IS NULL`), or call `bookmark()` with new metadata, which replaces it.

### `A model event cancelled saving the bookmark`

A `creating`, `updating` or `saving` listener on the `Bookmark` model returned `false`. The package throws instead of returning a bookmark that isn't stored.

### `The bookmarker [App\Models\User] is not saved`

A write was called with a model that isn't saved. Save it first, or check `$model->exists`.

### `Register a shorter alias with Relation::morphMap()`

The class name is longer than the 191 characters the type columns hold. Add a morph map alias for the model.

### `is_bookmarked` is `1`, not `true`

MySQL, MariaDB and SQLite return `EXISTS` as an integer. Cast it: `(bool) $post->is_bookmarked`.

### `Illegal mix of collations` on MySQL

Your model's table and the bookmarks table use different collations, and the model has a string key (UUID, ULID). Create both with the same collation, the `collation` of your database connection.

### `operator does not exist: bigint = character varying` on PostgreSQL

A query compares a model key with the string `bookmarkable_id` column directly, for example a hand-written join. Go through the package relations and scopes, which compare the key as a string, or cast it yourself: `cast(posts.id as varchar) = bookmarks.bookmarkable_id`.

### Bookmarks stay after deleting models

The models were deleted with a query (`Post::where(...)->delete()`), which skips model events, or `cleanup_on_delete` is false. See [Deletes and Soft Deletes](#deletion-and-soft-deletes) for the manual cleanup.
