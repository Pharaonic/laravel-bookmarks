## Relationships

All relations are native Eloquent relations, so everything Eloquent offers works on them.

| Relation | On | Type | Holds |
| --- | --- | --- | --- |
| `givenBookmarks()` | `CanBookmark` models | `MorphMany` of `Bookmark` | The bookmarks the model kept. |
| `bookmarks()` | `HasBookmarks` models | `MorphMany` of `Bookmark` | The bookmarks the model received. |
| `bookmarker()` | `Bookmark` | `MorphTo` | The model that kept the bookmark. |
| `bookmarkable()` | `Bookmark` | `MorphTo` | The model that was bookmarked. |

### Why `givenBookmarks()` and not `bookmarks()`

The bookmarker side's relation is called `givenBookmarks()` so it never clashes with `bookmarks()` on the bookmarked side. A `User` can use both traits without a trait conflict, and `$user->bookmarks` always means the bookmarks other models kept of that user.

### Reading through the relations

```php
use Pharaonic\Laravel\Bookmarks\Models\Bookmark;

$bookmark = $post->bookmarks()->latest()->first();

$bookmark->bookmarker;     // App\User
$bookmark->bookmarkable;   // App\Post
$bookmark->meta->note;     // "Read later"

$user->givenBookmarks()->with('bookmarkable')->latest()->get()
    ->map(fn (Bookmark $bookmark) => $bookmark->bookmarkable); // what the user saved, of every type
```

### The `Bookmark` model

`Pharaonic\Laravel\Bookmarks\Models\Bookmark` reads the table from `pharaonic.bookmarks.table` and has standard timestamps. It has two scopes for filtering bookmarks by one end:

```php
Bookmark::whereBookmarker($user)->count();       // bookmarks the user kept
Bookmark::whereBookmarkable($post)->count();     // bookmarks of the post

User::whereHas('givenBookmarks', fn ($query) => $query->whereBookmarkable($post))->get(); // who saved the post
```

Both scopes match nothing for an unsaved model. To add your own relations or scopes to the model, extend it, as [Custom Bookmark Model](#custom-model) shows.
