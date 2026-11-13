## Querying and Counts

Counting, filtering and flagging run in SQL. None of them loads the bookmarks into PHP.

### Counting

```php
$post->bookmarks()->count();                 // select count(*) ...

$posts = Post::withCount('bookmarks')->get();
$posts->first()->bookmarks_count;            // 12

$post->loadCount('bookmarks');
```

:::warning
`$post->bookmarks->count()` (property, not method) loads every bookmark of the post first. Use `bookmarks()->count()` or `withCount('bookmarks')`.
:::

### Filtering by bookmarker

`whereBookmarkedBy()` keeps the models the bookmarker bookmarked, and `whereNotBookmarkedBy()` keeps the others:

```php
Post::whereBookmarkedBy($user)->latest()->paginate();
Post::whereNotBookmarkedBy($user)->latest()->paginate();
```

For an unsaved bookmarker, `whereBookmarkedBy()` matches nothing and `whereNotBookmarkedBy()` keeps every row.

### "Did I save this?" in a list

Calling `$user->hasBookmarked($post)` for every post of a page runs one query per post. `withBookmarkedBy()` adds an `is_bookmarked` column from one `EXISTS` subquery instead:

```php
$posts = Post::withBookmarkedBy($request->user())
    ->withCount('bookmarks')
    ->latest()
    ->paginate();

(bool) $post->is_bookmarked;   // true when the viewer saved it
$post->bookmarks_count;        // 12
```

`is_bookmarked` is `1`/`0` on MySQL, MariaDB and SQLite and `true`/`false` on PostgreSQL, so cast it with `(bool)` or a model cast. It is `false` for every row when the viewer is unsaved (a guest model).

`withBookmarkedBy()` keeps the columns you already selected, and selects `posts.*` when you selected none. Call it after `select()`, because a later `select()` replaces the column list.

### Filtering by metadata

The `meta` column is JSON, so Laravel's JSON clauses work on the relations:

```php
$user->givenBookmarks()->where('meta->priority', '>=', 3)->get();

Post::whereHas('bookmarks', fn ($query) => $query
    ->whereBookmarker($user)
    ->where('meta->remind', true)
)->get();
```

### Eager loading

Load bookmarks and both ends with a fixed number of queries:

```php
Post::with('bookmarks.bookmarker')->get();                       // posts, bookmarks, then one query per bookmarker type
$user->givenBookmarks()->with('bookmarkable')->latest()->get();
```

### Indexes

The migration creates:

| Index | Columns | Serves |
| --- | --- | --- |
| `bookmarks_unique_pair` (unique) | `bookmarkable_type`, `bookmarkable_id`, `bookmarker_type`, `bookmarker_id` | Uniqueness, `bookmarks()`, `withCount('bookmarks')`, `bookmarkedBy()`, `withBookmarkedBy()` |
| `bookmarks_bookmarker_index` | `bookmarker_type`, `bookmarker_id` | `givenBookmarks()`, `whereBookmarkedBy()` |

The ids are strings, so the relations compare your model's key as a string too. That keeps both indexes usable, and lets PostgreSQL compare a `bigint` or `uuid` key with the `varchar` column at all. The `meta` column has no index; add a generated column and an index of your own if you filter large tables by a metadata key.
