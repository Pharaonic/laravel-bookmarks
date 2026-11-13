## Examples

### 1. A save button with a note

Send the state the user wants, so a double click can't undo itself.

- ===Routes

  ```php title="routes/web.php"
  use App\Http\Controllers\PostBookmarkController;

  Route::middleware('auth')->group(function () {
      Route::put('posts/{post}/bookmark', [PostBookmarkController::class, 'update']);
      Route::delete('posts/{post}/bookmark', [PostBookmarkController::class, 'destroy']);
  });
  ```

- ===Controller

  ```php title="app/Http/Controllers/PostBookmarkController.php"
  namespace App\Http\Controllers;

  use App\Models\Post;
  use Illuminate\Http\Request;

  class PostBookmarkController extends Controller
  {
      public function update(Request $request, Post $post)
      {
          $data = $request->validate([
              'note' => 'nullable|string|max:500',
              'remind' => 'boolean',
          ]);

          $bookmark = $request->user()->bookmark($post, $data);

          return ['saved' => true, 'meta' => $bookmark->meta];
      }

      public function destroy(Request $request, Post $post)
      {
          $request->user()->unbookmark($post);

          return ['saved' => false];
      }
  }
  ```

`$request->validate()` returns an array with string keys, so it is valid metadata as it is. Every save replaces the whole object, so a note the user cleared is gone, not merged back.

### 2. A feed that shows what the viewer saved

The posts, their bookmark counts, and whether the viewer saved each one come from one query, plus the paginator's count. The route needs `auth`: `withBookmarkedBy()` takes a model, and `$request->user()` is null for a guest.

```php title="routes/web.php"
use App\Http\Controllers\FeedController;

Route::get('feed', [FeedController::class, 'index'])->middleware('auth');
```

```php title="app/Http/Controllers/FeedController.php"
public function index(Request $request)
{
    $posts = Post::withBookmarkedBy($request->user())
        ->withCount('bookmarks')
        ->latest()
        ->paginate(20);

    return view('feed', compact('posts'));
}
```

Each button sends the state the user wants to the routes of the first example:

```blade title="resources/views/feed.blade.php"
@foreach ($posts as $post)
    <form method="POST" action="{{ url('posts/'.$post->id.'/bookmark') }}">
        @csrf
        @method($post->is_bookmarked ? 'DELETE' : 'PUT')

        <button type="submit">
            {{ $post->is_bookmarked ? 'Saved' : 'Save' }} ({{ $post->bookmarks_count }})
        </button>
    </form>
@endforeach

{{ $posts->links() }}
```

The first example's controller answers with JSON, for buttons that send the request from JavaScript. For plain forms like these, return `back()` from it instead.

### 3. A "saved for later" page

Newest bookmark first, with every type the user saved and their notes:

```php
$bookmarks = $request->user()->givenBookmarks()->with('bookmarkable')->latest()->paginate(20);

foreach ($bookmarks as $bookmark) {
    $bookmark->bookmarkable;                   // App\Models\Post, App\Models\Product, ...
    $bookmark->meta->get('note', '');
}
```

Only the posts, or only those with a reminder:

```php
Post::whereBookmarkedBy($request->user())->latest()->paginate(20);

$request->user()->givenBookmarks()->where('meta->remind', true)->get();
```

### 4. Changing one key

Metadata is replaced as a whole, so read it, change the key, and write it back:

```php
$meta = $user->bookmarkOf($post)->meta->toObject();
$meta->priority = 1;
unset($meta->remind);

$user->bookmark($post, $meta);
```

`toObject()` keeps nested empty objects as objects, so nothing else changes shape on the way back. When the stored object already equals the new one, nothing is written.

### 5. Reminders from the event

```php title="app/Listeners/ScheduleReminder.php"
public function handle(BookmarkCreated $event)
{
    if ($event->bookmark->meta->get('remind') === true) {
        SendReminder::dispatch($event->bookmarker, $event->bookmarkable)->delay(now()->addDay());
    }
}
```

Register it for `Pharaonic\Laravel\Bookmarks\Events\BookmarkCreated` in your `EventServiceProvider`. Listen to `BookmarkUpdated` too if a reminder can be turned on later.
