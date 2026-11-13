## Events

The package dispatches three events, only when a call actually changed the table:

| Event | Dispatched by | Not dispatched |
| --- | --- | --- |
| `Pharaonic\Laravel\Bookmarks\Events\BookmarkCreated` | `bookmark()`, `bookmarkBy()`, `toggleBookmark()` when they insert the bookmark | When the bookmark already existed |
| `Pharaonic\Laravel\Bookmarks\Events\BookmarkUpdated` | `bookmark()`, `bookmarkBy()` when they replace the metadata with different metadata | When the metadata was omitted, `null` or equal; for a direct `$bookmark->save()` |
| `Pharaonic\Laravel\Bookmarks\Events\BookmarkRemoved` | `unbookmark()`, `unbookmarkBy()`, `toggleBookmark()` when they delete the bookmark | When there was none, and for the cleanup on delete |

Each has three public properties: `$bookmark` (the `Bookmark` row, or your configured subclass), `$bookmarker`, and `$bookmarkable`. In `BookmarkUpdated`, `$bookmark->meta` is the new metadata; in `BookmarkRemoved`, `$bookmark` is the deleted row, with `exists` false.

```php title="app/Listeners/ScheduleReminder.php"
namespace App\Listeners;

use App\Jobs\SendReminder;
use Pharaonic\Laravel\Bookmarks\Events\BookmarkCreated;

class ScheduleReminder
{
    public function handle(BookmarkCreated $event)
    {
        if ($event->bookmark->meta->get('remind') === true) {
            SendReminder::dispatch($event->bookmark)->delay(now()->addDay());
        }
    }
}
```

Laravel discovers the listener in `app/Listeners` from the `BookmarkCreated` type of its `handle()` method. To register it yourself, add it in a service provider:

```php title="app/Providers/AppServiceProvider.php"
use App\Listeners\ScheduleReminder;
use Illuminate\Support\Facades\Event;
use Pharaonic\Laravel\Bookmarks\Events\BookmarkCreated;

public function boot(): void
{
    Event::listen(BookmarkCreated::class, ScheduleReminder::class);
}
```

### When listeners run

Each write runs in a short transaction of its own, and the event is dispatched right after it. When you bookmark inside your own database transaction, the package's transaction is a savepoint in yours, so listeners run before your transaction commits, and a rollback afterwards does not take the event back. Make a listener that must only see committed bookmarks a queued listener dispatched after your transaction, or call the package outside it.

### Model events

Inserts and updates go through Eloquent's `save()`, so the `Bookmark` model's own `creating`, `created`, `saving`, `saved`, `updating` and `updated` events fire.

- A listener that returns `false` from `creating`, `updating` or `saving` cancels the write. `bookmark()` and `toggleBookmark()` then throw a `RuntimeException` ("A model event cancelled saving the bookmark…") instead of returning a bookmark that isn't stored, and no package event is dispatched.
- A listener that changes the metadata changes what is stored, and the returned bookmark and the event carry the stored metadata.

The removal deletes the row with a query, so the `Bookmark` model's `deleting` and `deleted` events do not fire. Listen to `BookmarkRemoved` instead.
