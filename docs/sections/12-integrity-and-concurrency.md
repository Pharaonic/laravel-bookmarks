## Integrity and Concurrency

### The unique index

The `bookmarks_unique_pair` index on (`bookmarkable_type`, `bookmarkable_id`, `bookmarker_type`, `bookmarker_id`) is what keeps a pair to one row. When `bookmark()` tries to insert a pair that already has a row, the index refuses the insert, and the call reads that row and continues with it: it replaces the metadata you passed, or returns the row unchanged.

Only a refusal by that index counts as "already bookmarked". Every other database error is thrown: a missing table, a lost connection, or a unique index of your own that refused the row.

### Transactions and retries

Each write runs in a transaction on the bookmark model's connection. Inside it, the insert runs in a savepoint, so a refused insert doesn't abort the transaction on PostgreSQL, and a row is locked (`FOR UPDATE`) before its metadata is compared and replaced, so a concurrent delete cannot slip in between. On MySQL, whose transactions read from a snapshot, that locking read also finds a row another request committed after your transaction started.

When the database rolls a statement back to break a deadlock, or SQLite reports that the database is locked, Laravel runs the package's transaction again, up to three times, so the call still returns its normal result. Inside your own transaction the database has already rolled your whole transaction back, so the error reaches you; run your transaction with retries:

```php
DB::transaction(function () use ($user, $post) {
    $user->bookmark($post, ['note' => 'Read later']);
    // ...
}, 3);
```

### Concurrent writes of the same pair

| Situation | Result |
| --- | --- |
| Two requests bookmark a new pair at once | One row. One call creates it (`BookmarkCreated`), the other replaces its metadata (`BookmarkUpdated`) when it differs. Both return the row. |
| Two requests replace the metadata at once, with different objects | The last write wins, whole: the row holds one call's object, never a merge of both. |
| A bookmark and an unbookmark at once | Either the row is gone, or it exists with the metadata of the bookmark call. When the row keeps disappearing, `bookmark()` gives up after three tries with a `RuntimeException` rather than report a bookmark that isn't stored. |
| Two unbookmarks at once | One call deletes the row and returns `true`; the other returns `false`. |

### Toggling under concurrency

`toggleBookmark()` inserts first: when the insert works, the pair is now bookmarked and the call returns the bookmark. When the pair already has a bookmark, the call deletes it and returns `null`. When another call deleted it first, the pair has none either way, and the call returns `null` without an event.

What is guaranteed:

- the table never holds two rows for a pair;
- every call returns the state it left the pair in;
- events are dispatched only for changes the call made.

What is not: two toggles of the same pair at the same moment don't simply cancel out. When a user's intent matters, as in a bookmark button, send the state the user wants and call `bookmark()` or `unbookmark()`.

### Tested databases

The test suite runs on SQLite, MySQL 8.4 and PostgreSQL 16, including tests that start several PHP processes, each with its own connection, bookmarking with different metadata, unbookmarking or toggling the same pair at the same moment. On SQLite the database file serializes those writes; MySQL and PostgreSQL run them in parallel.
