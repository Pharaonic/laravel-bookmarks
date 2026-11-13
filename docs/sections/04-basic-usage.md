## Basic Usage

### Add the traits

Add `CanBookmark` to the model that bookmarks. It does not have to be a user: a team, a bot or any other model works.

```php title="app/Models/User.php"
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Pharaonic\Laravel\Bookmarks\Concerns\CanBookmark;

class User extends Authenticatable
{
    use CanBookmark;
}
```

Add `HasBookmarks` to the model that is bookmarked. It gives the model its `bookmarks()` relation and query scopes; a model without it can still be bookmarked.

```php title="app/Models/Post.php"
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Bookmarks\Concerns\HasBookmarks;

class Post extends Model
{
    use HasBookmarks;
}
```

A model can use both traits, for example users who bookmark each other.

### Bookmark

`bookmark()` returns the stored `Bookmark`, whether this call created it or it already existed:

```php
$bookmark = $user->bookmark($post);

$bookmark->exists;            // true
$bookmark->meta->isEmpty();   // true: a new bookmark without metadata stores {}
```

Pass metadata as the second argument. It replaces the whole metadata of an existing bookmark:

```php
$user->bookmark($post, ['note' => 'Read later', 'priority' => 5]);
$user->bookmark($post, ['priority' => 2]);

$user->bookmarkOf($post)->meta->toArray();   // ['priority' => 2]: note is gone
```

Leave the metadata out (or pass `null`) to keep what is stored, and pass `[]` to empty it:

```php
$user->bookmark($post);       // keeps ['priority' => 2]
$user->bookmark($post, []);   // stores {}
```

### Read and remove

```php
$user->hasBookmarked($post);   // true
$user->bookmarkOf($post);      // the Bookmark, or null
$user->unbookmark($post);      // true: this call removed it
$user->unbookmark($post);      // false: there was none
```

### Toggle

`toggleBookmark()` creates the bookmark, with the metadata you pass, and returns it; when the bookmark exists, it removes it and returns `null`. The metadata is only used when it creates one.

```php
$user->toggleBookmark($post, ['source' => 'feed']);   // the new Bookmark
$user->toggleBookmark($post);                          // null: removed
```

:::tip
For a bookmark button, send the state the user wants and call `bookmark()` or `unbookmark()`. A double click then can't undo itself, which `toggleBookmark()` can.
:::

### From the bookmarked side

`HasBookmarks` offers the same operations from the other side, with the same results:

```php
$post->bookmarkBy($user, ['note' => 'Read later']);   // same as $user->bookmark($post, [...])
$post->bookmarkedBy($user);                            // same as $user->hasBookmarked($post)
$post->unbookmarkBy($user);                            // same as $user->unbookmark($post)
```

### Unsaved models

The writes (`bookmark()`, `unbookmark()`, `toggleBookmark()`, `bookmarkBy()`, `unbookmarkBy()`) throw an `InvalidArgumentException` when either model is not saved yet, and write nothing. The reads return `false` or `null` instead.

### A returned bookmark is a snapshot

A `Bookmark` you hold does not change when a later call writes the same pair. Use the model the later call returns, or `refresh()` the one you have:

```php
$first = $user->bookmark($post, ['a' => 1]);
$user->bookmark($post, ['a' => 2]);

$first->meta->a;              // 1
$first->refresh()->meta->a;   // 2
```
