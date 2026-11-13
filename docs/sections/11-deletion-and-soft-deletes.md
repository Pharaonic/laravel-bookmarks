## Deletes and Soft Deletes

When a model that uses `CanBookmark` or `HasBookmarks` is deleted through Eloquent, its bookmarks are deleted with it:

| Action | Bookmarks it kept (`CanBookmark`) | Bookmarks it received (`HasBookmarks`) |
| --- | --- | --- |
| `$model->delete()` | deleted | deleted |
| `$model->delete()` on a `SoftDeletes` model | kept | kept |
| `$model->restore()` | still there | still there |
| `$model->forceDelete()` | deleted | deleted |

A model with both traits loses both directions. Keeping the bookmarks on a soft delete means a restored model has exactly the bookmarks, and the metadata, it had.

```php
$post->delete();        // SoftDeletes: the bookmarks stay
$post->restore();       // $post->bookmarkedBy($user) is still true
$post->forceDelete();   // the bookmarks are deleted
```

The cleanup deletes the rows with one query and dispatches no `BookmarkRemoved` events.

### Turning cleanup off

```php title="config/pharaonic/bookmarks.php"
'cleanup_on_delete' => false,
```

Deleting a model then leaves its bookmarks in the table. `whereBookmarkedBy()` and `withCount()` ignore them for models that no longer exist, but `givenBookmarks()` of a bookmarker still lists bookmarks of deleted posts.

### Deletes that skip the cleanup

The cleanup runs in the model's `deleted` event, so it only happens when a model instance is deleted. These skip it:

- `Post::where(...)->delete()` and other query deletes;
- `DB::table('posts')->delete()` and raw SQL;
- `truncate()`.

After such a delete, remove the bookmarks yourself, for example in the same job:

```php
use Pharaonic\Laravel\Bookmarks\Models\Bookmark;

$ids = Post::where('created_at', '<', now()->subYear())->pluck('id');

Post::whereIn('id', $ids)->delete();
Bookmark::where('bookmarkable_type', (new Post)->getMorphClass())
    ->whereIn('bookmarkable_id', $ids->map(fn ($id) => (string) $id))
    ->delete();
```

To clean up after the fact, delete the bookmarks whose model is gone:

```php
Bookmark::where('bookmarkable_type', (new Post)->getMorphClass())
    ->whereNotIn('bookmarkable_id', Post::withTrashed()->pluck('id')->map(fn ($id) => (string) $id))
    ->delete();
```

Leave out `withTrashed()` for models without `SoftDeletes`. Run it per type, and for `bookmarker_type` the same way. Use your configured model class instead of `Bookmark` when you have one.
