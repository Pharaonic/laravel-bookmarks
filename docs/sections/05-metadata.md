## Metadata

Every bookmark has a `meta` attribute that holds one JSON object. It is never `NULL` and never a list or a scalar: a bookmark without metadata holds the empty object `{}`.

### What you can pass

`bookmark()`, `bookmarkBy()`, `toggleBookmark()` and the `meta` attribute accept:

- an array with string keys, such as `['note' => 'Read later']`; the empty array `[]` is the empty object;
- a `stdClass`, such as `json_decode($json)`, which keeps `{}` and `[]` apart at every depth;
- a `BookmarkMeta`, such as another bookmark's `meta`.

Inside the object, values can be strings, integers, floats, booleans, `null`, lists and nested objects (arrays with string keys, `stdClass` or `BookmarkMeta`).

Anything else throws an `InvalidArgumentException` before the database is touched: a list at the top (`['a', 'b']`), a string (including a JSON string), a number, a boolean, an object such as a Collection, a Carbon date or a model, `NAN` or `INF`, invalid UTF-8, a key that starts with a NUL byte, a structure that contains itself, or nesting deeper than 512 levels. The message says where the problem is, for example `(at meta.preferences.channels[2])`.

:::info Arrays below the top
Below the top level, an empty PHP array is the empty **list** `[]`, as `json_encode()` writes it. Use `new stdClass` for an empty object there. A PHP array whose keys are not 0, 1, 2… is an object, with its keys as strings: `[1 => 'a']` is `{"1":"a"}`.
:::

### Reading metadata

`$bookmark->meta` is a `Pharaonic\Laravel\Bookmarks\ValueObjects\BookmarkMeta`. Read keys as properties; a nested object is a `BookmarkMeta` too, and a list is a PHP array.

```php
$bookmark = $user->bookmark($post, [
    'note' => 'Read later',
    'priority' => 5,
    'reminder' => null,
    'preferences' => ['notify' => true, 'channels' => ['mail', 'push']],
]);

$bookmark->meta->note;                    // "Read later"
$bookmark->meta->preferences->notify;     // true
$bookmark->meta->preferences->channels;   // ["mail", "push"]
$bookmark->meta->missing;                 // null
```

`get()` takes a default for a key that is absent, and `has()` tells an absent key from one that holds `null`:

```php
$bookmark->meta->get('priority', 0);    // 5
$bookmark->meta->get('missing', 0);     // 0
$bookmark->meta->get('reminder', 0);    // null: the key is present
$bookmark->meta->has('reminder');       // true
$bookmark->meta->has('missing');        // false
isset($bookmark->meta->reminder);       // false, as isset() means
```

Read a key that is not a valid PHP name with `get('my-key')` or `$meta->{'my-key'}`.

### Converting

| Method | Returns | Notes |
| --- | --- | --- |
| `toArray()` | `array` | Plain nested arrays. `{}` and `[]` both become `[]`, and numeric keys become integers. |
| `toObject()` | `stdClass` | A detached copy that keeps `{}` and `[]` apart. Pass it back to `bookmark()` without losing anything. |
| `json_encode($meta)` | `string` | Always a JSON object. |
| `isEmpty()` | `bool` | Whether the metadata is `{}`. |

`$bookmark->toJson()` and `json_encode($bookmark)` write `meta` as a JSON object. `$bookmark->toArray()['meta']` is the `BookmarkMeta` itself, so the JSON shape survives until it is encoded.

### Metadata is read-only

Writing to a `BookmarkMeta` throws a `LogicException`, at every depth, and values it returns are copies, so nothing you do with them changes the stored metadata:

```php
$bookmark->meta->priority = 10;                // LogicException
$bookmark->meta->preferences->notify = false;  // LogicException
```

To change one key, build the new object and pass it whole:

```php
$meta = $user->bookmarkOf($post)->meta->toObject();
$meta->priority = 10;

$user->bookmark($post, $meta);   // every other key is kept
```

### Replace, keep, or empty

| Pair already bookmarked? | Metadata argument | Result |
| --- | --- | --- |
| No | omitted or `null` | Inserts the bookmark with `{}`. |
| No | an object | Inserts the bookmark with that object. |
| Yes | omitted or `null` | Returns the bookmark. Nothing is written. |
| Yes | equal to what is stored | Returns the bookmark. Nothing is written, `updated_at` is kept, no event. |
| Yes | a different object | Replaces the whole object: one UPDATE, `updated_at` changes, `created_at` does not. |
| Yes | `[]` | Stores `{}`, unless it is already empty. |
| Either | not a JSON object | Throws `InvalidArgumentException`. Nothing is written. |

### When metadata is equal

Two objects are equal when they hold the same JSON, whatever the order of their keys, at every depth. List order and value types count:

| Stored | New | Equal? |
| --- | --- | --- |
| `{"a":1,"b":2}` | `{"b":2,"a":1}` | Yes |
| `{"a":{"x":1,"y":2}}` | `{"a":{"y":2,"x":1}}` | Yes |
| `{"a":1}` | `{"a":"1"}` | No |
| `{"a":1}` | `{"a":1.0}` | No |
| `{"a":true}` | `{"a":1}` | No |
| `{"a":null}` | `{}` | No |
| `{"a":[1,2]}` | `{"a":[2,1]}` | No |
| `{"a":[]}` | `{"a":{}}` | No |

Metadata is stored as canonical JSON, with its keys sorted, so it reads back in key order rather than the order you wrote it. Floats keep their type: `1.0` reads back as a float, not the integer `1`.

### Assigning on the model

You can also assign the attribute and save the model yourself:

```php
$bookmark->meta = ['priority' => 5];
$bookmark->save();
```

The attribute takes the same values as `bookmark()`. Assigning `null` throws, because it would store SQL `NULL`; assign `[]` for the empty object. Assigning metadata equal to what is stored leaves the attribute clean (`isDirty('meta')` is false), so `save()` runs no UPDATE. A direct `save()` fires the `Bookmark` model's own Eloquent events, but not the package's `BookmarkUpdated` event.

### Querying metadata

Use Laravel's JSON `where` clauses on the `meta` column:

```php
$user->givenBookmarks()->where('meta->priority', 5)->get();
$user->givenBookmarks()->where('meta->preferences->notify', true)->count();
```

### Partial selects and damaged rows

When a query does not select the `meta` column, `$bookmark->meta` is `null`, as for any column you didn't select.

When a row holds something other than a JSON object in `meta` (SQL `NULL`, a JSON string or list, broken JSON written outside the package), reading `$bookmark->meta` throws an `UnexpectedValueException` that names the table and the bookmark id. Calling `bookmark()` with new metadata replaces the damaged value.
