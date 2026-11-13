<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Pharaonic\Laravel\Bookmarks\Events\BookmarkCreated;
use Pharaonic\Laravel\Bookmarks\Events\BookmarkUpdated;
use Pharaonic\Laravel\Bookmarks\Models\Bookmark;
use Pharaonic\Laravel\Bookmarks\Support\BookmarkManager;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Post;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\User;

test('a bookmark another connection wrote gets the new metadata', function () {
    $this->useSharedDatabase();
    $user = User::create();
    $post = Post::create();
    insertBookmarkFromAnotherConnection($user, $post, '{"from":"other"}');

    Event::fake([BookmarkCreated::class, BookmarkUpdated::class]);

    $bookmark = $user->bookmark($post, ['from' => 'this']);

    $this->assertSame(['from' => 'this'], $bookmark->meta->toArray());
    $this->assertSame(1, Bookmark::query()->count());
    Event::assertNotDispatched(BookmarkCreated::class);
    Event::assertDispatchedTimes(BookmarkUpdated::class, 1);
});

test('a unique violation on insert replaces the metadata of the winning row', function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite lets one connection write at a time.');
    }

    $user = User::create();
    $post = Post::create();
    $attempts = new stdClass;
    $attempts->count = 0;

    // Another request commits the pair between this call's read and its insert.
    Bookmark::creating(function () use ($attempts, $user, $post) {
        if ($attempts->count++ === 0) {
            insertBookmarkFromAnotherConnection($user, $post, '{"winner":true}');
        }
    });
    Event::fake([BookmarkCreated::class, BookmarkUpdated::class]);

    $bookmark = $user->bookmark($post, ['loser' => true]);

    $this->assertSame(1, $attempts->count);
    $this->assertSame(['loser' => true], $bookmark->meta->toArray());
    $this->assertSame(['loser' => true], $user->bookmarkOf($post)?->meta->toArray());
    $this->assertSame(1, Bookmark::query()->count());
    Event::assertNotDispatched(BookmarkCreated::class);
    Event::assertDispatchedTimes(BookmarkUpdated::class, 1);
});

test('a bookmark committed during the callers transaction is found not thrown', function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite lets one connection write at a time.');
    }

    $user = User::create();
    $post = Post::create();

    DB::transaction(function () use ($user, $post) {
        // MySQL (REPEATABLE READ) takes the transaction's snapshot at its first read.
        $this->assertFalse($user->hasBookmarked($post));

        insertBookmarkFromAnotherConnection($user, $post, '{"from":"other"}');

        $this->assertSame(['from' => 'this'], $user->bookmark($post, ['from' => 'this'])->meta->toArray());
        $this->assertNull($user->toggleBookmark($post));
    });

    $this->assertFalse($user->hasBookmarked($post));
    $this->assertSame(0, Bookmark::query()->count());
});

test('a violation of another unique index is not swallowed', function () {
    Schema::table('bookmarks', function (Blueprint $table) {
        $table->unique(['bookmarker_type', 'bookmarker_id'], 'bookmarks_one_per_bookmarker');
    });

    $user = User::create();
    $user->bookmark(Post::create());

    foreach ([fn () => $user->bookmark(Post::create()), fn () => $user->toggleBookmark(Post::create())] as $write) {
        try {
            $write();
            $this->fail('Another unique index violation was swallowed.');
        } catch (QueryException $exception) {
            $this->assertTrue(BookmarkManager::isUniqueViolation($exception));
        }
    }

    $this->assertSame(1, Bookmark::query()->count());
});

test('other database errors are not swallowed', function () {
    $user = User::create();
    $post = Post::create();
    Schema::drop('bookmarks');

    foreach (['bookmark', 'unbookmark', 'hasBookmarked', 'bookmarkOf', 'toggleBookmark'] as $method) {
        try {
            $user->{$method}($post);
            $this->fail("{$method}() hid a missing table.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString('bookmarks', $exception->getMessage());
        }
    }
});

test('an existing pair leaves the callers transaction usable', function () {
    $user = User::create();
    $post = Post::create();
    $user->bookmark($post);

    DB::transaction(function () use ($user, $post) {
        $this->assertSame(['a' => 1], $user->bookmark($post, ['a' => 1])->meta->toArray());
        $this->assertNull($user->toggleBookmark($post));
        $this->assertInstanceOf(Bookmark::class, $user->toggleBookmark($post, ['b' => 2]));
        $this->assertSame(1, Post::query()->count());
    });

    $this->assertSame(['b' => 2], $user->bookmarkOf($post)?->meta->toArray());
});

test('a rolled back transaction rolls the bookmark back', function () {
    $user = User::create();
    $post = Post::create();
    $user->bookmark($post, ['a' => 1]);

    DB::beginTransaction();
    $user->bookmark($post, ['a' => 2]);
    $user->bookmark(Post::create());
    DB::rollBack();

    $this->assertSame(['a' => 1], $user->bookmarkOf($post)?->meta->toArray());
    $this->assertSame(1, Bookmark::query()->count());
});

test('a deadlock outside a transaction is retried with one event', function () {
    $attempts = deadlockBookmarkInserts(1);
    Event::fake([BookmarkCreated::class]);

    User::create()->bookmark(Post::create());

    $this->assertSame(2, $attempts->count);
    $this->assertSame(1, Bookmark::query()->count());
    Event::assertDispatchedTimes(BookmarkCreated::class, 1);
});

test('a deadlock that keeps happening is thrown', function () {
    $attempts = deadlockBookmarkInserts(PHP_INT_MAX);
    $user = User::create();
    $post = Post::create();

    try {
        $user->bookmark($post);
        $this->fail('The deadlock was swallowed.');
    } catch (PDOException $exception) {
        // Laravel 8.x+ rethrows it as DeadlockException.
        $this->assertStringContainsString('Deadlock found', $exception->getMessage());
    }

    $this->assertSame(3, $attempts->count);
    $this->assertSame(0, Bookmark::query()->count());
    $this->assertSame(0, DB::transactionLevel());
});

test('a deadlock inside a transaction is left to the caller', function () {
    $attempts = deadlockBookmarkInserts(1);
    $user = User::create();
    $post = Post::create();

    DB::beginTransaction();

    try {
        $user->bookmark($post);
        $this->fail('The deadlock was retried inside the caller\'s transaction.');
    } catch (PDOException $exception) {
        $this->assertStringContainsString('Deadlock found', $exception->getMessage());
    } finally {
        DB::rollBack();
    }

    $this->assertSame(1, $attempts->count);
});

test('a pair that keeps vanishing after a unique violation is thrown not looped', function () {
    $attempts = failBookmarkInsertsWith(
        'UNIQUE constraint failed: bookmarks.bookmarkable_type, bookmarks.bookmarkable_id, bookmarks.bookmarker_type, bookmarks.bookmarker_id'
    );

    try {
        User::create()->bookmark(Post::create());
        $this->fail('A pair that kept vanishing was reported as bookmarked.');
    } catch (RuntimeException $exception) {
        $this->assertStringContainsString('deleted while it was being written, 3 times in a row', $exception->getMessage());
    }

    $this->assertSame(3, $attempts->count);
    $this->assertSame(0, Bookmark::query()->count());
});

test('a toggle whose pair vanished reports no bookmark', function () {
    failBookmarkInsertsWith('duplicate key value violates unique constraint "bookmarks_unique_pair"', '23505');

    $this->assertNull(User::create()->toggleBookmark(Post::create()));
    $this->assertSame(0, Bookmark::query()->count());
});

test('another unique violation is thrown at once', function () {
    $attempts = failBookmarkInsertsWith('duplicate key value violates unique constraint "bookmarks_other_unique"', '23505');

    foreach ([fn () => User::create()->bookmark(Post::create()), fn () => User::create()->toggleBookmark(Post::create())] as $write) {
        try {
            $write();
            $this->fail('Another unique index violation was swallowed.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('bookmarks_other_unique', $exception->getMessage());
        }
    }

    $this->assertSame(2, $attempts->count);
});

test('only the pair index is recognized', function (string $sqlState, string $message, int $code, bool $pair) {
    $pdo = new PDOException('SQLSTATE['.$sqlState.']: '.$message);
    $pdo->errorInfo = [$sqlState, $code, $message];

    $this->assertSame($pair, BookmarkManager::isPairViolation(new QueryException('testing', 'insert ...', [], $pdo), new Bookmark));
})->with([
    'mysql pair' => ['23000', "Duplicate entry 'x' for key 'bookmarks.bookmarks_unique_pair'", 1062, true],
    'mysql other' => ['23000', "Duplicate entry 'x' for key 'bookmarks.bookmarks_slug_unique'", 1062, false],
    'pgsql pair' => ['23505', 'duplicate key value violates unique constraint "bookmarks_unique_pair"', 7, true],
    'pgsql other' => ['23505', 'duplicate key value violates unique constraint "bookmarks_slug_unique"', 7, false],
    'sqlite pair' => ['23000', 'UNIQUE constraint failed: bookmarks.bookmarkable_type, bookmarks.bookmarkable_id, bookmarks.bookmarker_type, bookmarks.bookmarker_id', 19, true],
    'sqlite other' => ['23000', 'UNIQUE constraint failed: bookmarks.slug', 19, false],
    'not unique' => ['23000', 'NOT NULL constraint failed: bookmarks.meta', 19, false],
]);

/**
 * Make every insert of a bookmark fail with the unique violation.
 */
function failBookmarkInsertsWith(string $message, string $sqlState = '23000'): stdClass
{
    $attempts = new stdClass;
    $attempts->count = 0;

    Bookmark::creating(function () use ($attempts, $message, $sqlState) {
        $attempts->count++;
        $pdo = new PDOException('SQLSTATE['.$sqlState.']: Integrity constraint violation: '.$message);
        $pdo->errorInfo = [$sqlState, $sqlState === '23505' ? 7 : 19, $message];

        throw new QueryException('testing', 'insert into "bookmarks" ...', [], $pdo);
    });

    return $attempts;
}

function insertBookmarkFromAnotherConnection(User $user, Post $post, string $meta): void
{
    // A second, independent connection to the same database, as another request would have.
    config()->set('database.connections.other', config('database.connections.testing'));
    DB::connection('other')->table('bookmarks')->insert([
        'bookmarker_type' => User::class,
        'bookmarker_id' => (string) $user->getKey(),
        'bookmarkable_type' => Post::class,
        'bookmarkable_id' => (string) $post->getKey(),
        'meta' => $meta,
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ]);
    DB::disconnect('other');
}

/**
 * Make the first $times inserts of a bookmark fail as MySQL's deadlock victim.
 */
function deadlockBookmarkInserts(int $times): stdClass
{
    $attempts = new stdClass;
    $attempts->count = 0;

    Bookmark::creating(function () use ($attempts, $times) {
        if (++$attempts->count > $times) {
            return;
        }

        $message = 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction';
        $pdo = new PDOException($message);
        $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

        throw new QueryException('testing', 'insert into `bookmarks` ...', [], $pdo);
    });

    return $attempts;
}
