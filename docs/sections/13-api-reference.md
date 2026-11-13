## API Reference

### `CanBookmark`

`Pharaonic\Laravel\Bookmarks\Concerns\CanBookmark`, for the model that bookmarks. `$meta` is an array with string keys, a `stdClass`, a `BookmarkMeta`, or `null`.

| Method | Returns | Description |
| --- | --- | --- |
| `bookmark(Model $bookmarkable, $meta = null)` | `Bookmark` | Creates the bookmark, replaces its whole metadata, or keeps it (`null`). Returns the stored row. |
| `bookmarkOf(Model $bookmarkable)` | `?Bookmark` | The bookmark of the model, or null. Null when either is unsaved. |
| `hasBookmarked(Model $bookmarkable)` | `bool` | Whether this model bookmarked the model. False when either is unsaved. |
| `unbookmark(Model $bookmarkable)` | `bool` | Removes the bookmark. True when this call removed it, false when there was none. |
| `toggleBookmark(Model $bookmarkable, $meta = null)` | `?Bookmark` | Creates the bookmark with the metadata and returns it, or removes it and returns null. |
| `givenBookmarks()` | `MorphMany` | The bookmarks this model kept. |

### `HasBookmarks`

`Pharaonic\Laravel\Bookmarks\Concerns\HasBookmarks`, for the model that is bookmarked.

| Method | Returns | Description |
| --- | --- | --- |
| `bookmarks()` | `MorphMany` | The bookmarks this model received. |
| `bookmarkBy(Model $bookmarker, $meta = null)` | `Bookmark` | Same as `$bookmarker->bookmark($this, $meta)`, for any bookmarker model. |
| `unbookmarkBy(Model $bookmarker)` | `bool` | Same as `$bookmarker->unbookmark($this)`. |
| `bookmarkedBy(Model $bookmarker)` | `bool` | Same as `$bookmarker->hasBookmarked($this)`. |
| `whereBookmarkedBy(Model $bookmarker)` | scope | Keeps the models the bookmarker bookmarked: `Post::whereBookmarkedBy($user)`. |
| `whereNotBookmarkedBy(Model $bookmarker)` | scope | Keeps the models the bookmarker did not bookmark. |
| `withBookmarkedBy(Model $bookmarker)` | scope | Adds an `is_bookmarked` column from one `EXISTS` subquery. |

### `Bookmark`

`Pharaonic\Laravel\Bookmarks\Models\Bookmark`, or the class in `pharaonic.bookmarks.model`.

| Member | Type | Description |
| --- | --- | --- |
| `bookmarker()` | `MorphTo` | The model that kept the bookmark. |
| `bookmarkable()` | `MorphTo` | The model that was bookmarked. |
| `whereBookmarker(Model $bookmarker)` | scope | Bookmarks kept by the model. Matches nothing for an unsaved model. |
| `whereBookmarkable(Model $bookmarkable)` | scope | Bookmarks of the model. Matches nothing for an unsaved model. |
| `meta` | `BookmarkMeta` | The metadata. Assign an array, `stdClass` or `BookmarkMeta` to replace it; null when the column was not selected. |
| `id` | `int` | Primary key. |
| `bookmarker_type`, `bookmarkable_type` | `string` | `getMorphClass()` of each end, up to 191 characters. |
| `bookmarker_id`, `bookmarkable_id` | `string` | The key of each end as a string, up to 64 characters. |
| `created_at`, `updated_at` | `Carbon` | Timestamps. `updated_at` changes only when the metadata does. |

### `BookmarkMeta`

`Pharaonic\Laravel\Bookmarks\ValueObjects\BookmarkMeta`, immutable.

| Member | Returns | Description |
| --- | --- | --- |
| `new BookmarkMeta(array\|stdClass $data = [])` | `BookmarkMeta` | Builds metadata, for example to pass around before bookmarking. |
| `$meta->key` | `mixed` | The value, or null when absent. Objects are `BookmarkMeta`, lists are arrays. |
| `get(string $key, $default = null)` | `mixed` | The value, or the default only when the key is absent. |
| `has(string $key)` | `bool` | Whether the key is present, even with a null value. |
| `isEmpty()` | `bool` | Whether the metadata is `{}`. |
| `toArray()` | `array` | Plain nested arrays; `{}` and `[]` both become `[]`. |
| `toObject()` | `stdClass` | A detached copy that keeps `{}` and `[]` apart. |
| `jsonSerialize()` | `stdClass` | Used by `json_encode()`: always a JSON object. |

### Events

| Event | Properties | Dispatched |
| --- | --- | --- |
| `Pharaonic\Laravel\Bookmarks\Events\BookmarkCreated` | `Bookmark $bookmark`, `Model $bookmarker`, `Model $bookmarkable` | After a call inserted a bookmark. |
| `Pharaonic\Laravel\Bookmarks\Events\BookmarkUpdated` | `Bookmark $bookmark`, `Model $bookmarker`, `Model $bookmarkable` | After a call replaced the metadata with different metadata. |
| `Pharaonic\Laravel\Bookmarks\Events\BookmarkRemoved` | `Bookmark $bookmark`, `Model $bookmarker`, `Model $bookmarkable` | After a call deleted a bookmark (not on cleanup). |

### Exceptions

| Exception | Thrown by | When |
| --- | --- | --- |
| `InvalidArgumentException` | the writes, `new BookmarkMeta()`, assigning `meta` | A model is unsaved or has no key, a key is longer than 64 characters, a morph type is longer than 191, or the metadata is not a JSON object. Nothing is written. |
| `UnexpectedValueException` | reading `meta`; every call | A stored value is not a JSON object; `pharaonic.bookmarks.model` is not a `Bookmark` class. |
| `LogicException` | writing to a `BookmarkMeta` | Always: metadata is read-only. |
| `RuntimeException` | `bookmark()`, `bookmarkBy()`, `toggleBookmark()` | A model event cancelled the save, or the row kept being deleted while it was written. |
| `Illuminate\Database\QueryException` | every method | Any database error other than the pair's own unique index refusing a duplicate. |

### Configuration and publish tags

| Key | Default |
| --- | --- |
| `pharaonic.bookmarks.table` | `bookmarks` |
| `pharaonic.bookmarks.model` | `Pharaonic\Laravel\Bookmarks\Models\Bookmark` |
| `pharaonic.bookmarks.cleanup_on_delete` | `true` |

| Tag | Publishes |
| --- | --- |
| `bookmarks-config`, `pharaonic-config` | `config/pharaonic/bookmarks.php` |
| `bookmarks-migrations`, `pharaonic-migrations` | `database/migrations/2026_11_13_000001_create_pharaonic_bookmarks_table.php` |
| `laravel-bookmarks`, `pharaonic` | both |
