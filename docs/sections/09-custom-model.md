## Custom Bookmark Model

To add relations, scopes, accessors or casts to bookmarks, extend the package model and name your class in the configuration. Do it before you migrate when your model changes the table or the connection.

```php title="app/Models/Bookmark.php"
namespace App\Models;

use Pharaonic\Laravel\Bookmarks\Models\Bookmark as BaseBookmark;

class Bookmark extends BaseBookmark
{
    public function scopePinned($query)
    {
        return $query->where('meta->pinned', true);
    }

    public function isPinned(): bool
    {
        return $this->meta->get('pinned') === true;
    }
}
```

```php title="config/pharaonic/bookmarks.php"
'model' => \App\Models\Bookmark::class,
```

The package then uses your class everywhere: `bookmark()`, `bookmarkOf()` and `toggleBookmark()` return it, `givenBookmarks()` and `bookmarks()` load it, the scopes and the cleanup query its table, and the events carry it.

```php
$user->bookmark($post, ['pinned' => true])->isPinned();   // true
$user->givenBookmarks()->pinned()->get();
```

A class that is not `Pharaonic\Laravel\Bookmarks\Models\Bookmark` or a subclass of it makes every call throw an `UnexpectedValueException`.

### Table and connection

| Your model sets | The package |
| --- | --- |
| nothing | uses `pharaonic.bookmarks.table` on the default connection. |
| `protected $table = 'saved_items';` | uses `saved_items`, and the migration creates it, ignoring `pharaonic.bookmarks.table`. |
| `protected $connection = 'secondary';` | writes on that connection, in its own transactions, and the migration creates the table there. |

:::warning Another connection
`whereBookmarkedBy()`, `whereNotBookmarkedBy()`, `withBookmarkedBy()`, `withCount('bookmarks')` and `whereHas()` put the bookmarks table in a subquery of your models' query, so they need both in the same database. Eager loading and the trait methods run separate queries and work across connections.
:::

### What stays the package's

Keep the columns and the `meta` accessor and mutator as they are: the package writes `bookmarker_type`, `bookmarker_id`, `bookmarkable_type`, `bookmarkable_id` and `meta` itself, through `forceFill()`, and reads the row through a query without your global scopes, so a scope cannot hide the row that holds a pair. Your own extra columns need defaults, because the package never fills them.
