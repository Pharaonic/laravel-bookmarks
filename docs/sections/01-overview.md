:::badges
- Laravel Package {color=blue}
- {release.label} {color=green}
- {package.license} License {color=purple}
:::

# Laravel Bookmarks

Laravel Bookmarks lets any Eloquent model bookmark any other, and keep a JSON object of metadata on each bookmark: a note, a priority, a reminder flag, or whatever your application needs. Add `CanBookmark` to the model that bookmarks, such as a user, and `HasBookmarks` to the model that is bookmarked, such as a post. Each bookmark is one row in a `bookmarks` table, and a unique index keeps every pair to one row, even when two requests write it at the same moment. `bookmark()` creates the bookmark, replaces its metadata, or leaves it untouched, and always returns the stored row; the metadata reads back as an immutable `BookmarkMeta` object.

:::features
### JSON-Object Metadata {icon="document"}
Each bookmark holds a JSON object, read as properties at every depth.

### Replace or Keep {icon="switch"}
New metadata replaces the whole object, no metadata keeps it, an empty array empties it.

### No Empty Writes {icon="check-circle"}
Equal metadata in any key order runs no UPDATE and fires no event.

### Unique by the Database {icon="lock"}
A unique index keeps each pair to one row under concurrent requests.

### Native Relations {icon="chart"}
Count, filter, flag and eager load bookmarks with plain Eloquent queries.

### Mixed Key Types {icon="tag"}
Integer, UUID and ULID models share one table, with morph map aliases respected.
:::

:::info Quick Tip
Metadata is replaced, never merged. To change one key, read the current object, change it, and pass the whole object back, as [Metadata](#metadata) shows.
:::
