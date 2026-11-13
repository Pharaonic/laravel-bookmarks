<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Pharaonic\Laravel\Bookmarks\Events\BookmarkCreated;
use Pharaonic\Laravel\Bookmarks\Events\BookmarkRemoved;
use Pharaonic\Laravel\Bookmarks\Events\BookmarkUpdated;
use Pharaonic\Laravel\Bookmarks\Models\Bookmark;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Post;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\User;

const BOOKMARK_EVENTS = [BookmarkCreated::class, BookmarkUpdated::class, BookmarkRemoved::class];

test('created is dispatched once with the bookmark and both models', function () {
    Event::fake(BOOKMARK_EVENTS);
    $user = User::create();
    $post = Post::create();

    $bookmark = $user->bookmark($post, ['a' => 1]);
    $user->bookmark($post);

    Event::assertDispatchedTimes(BookmarkCreated::class, 1);
    Event::assertDispatched(BookmarkCreated::class, function (BookmarkCreated $event) use ($bookmark, $user, $post) {
        return $event->bookmark->is($bookmark) && $event->bookmarker === $user && $event->bookmarkable === $post
            && $event->bookmark->meta->a === 1;
    });
    Event::assertNotDispatched(BookmarkUpdated::class);
});

test('updated is dispatched only for different metadata', function () {
    $user = User::create();
    $post = Post::create();
    $user->bookmark($post, ['a' => 1, 'b' => 2]);
    Event::fake(BOOKMARK_EVENTS);

    $user->bookmark($post);
    $user->bookmark($post, null);
    $user->bookmark($post, ['b' => 2, 'a' => 1]);
    Event::assertNotDispatched(BookmarkUpdated::class);

    $post->bookmarkBy($user, ['c' => 3]);
    Event::assertDispatchedTimes(BookmarkUpdated::class, 1);
    Event::assertDispatched(BookmarkUpdated::class, function (BookmarkUpdated $event) use ($user, $post) {
        return $event->bookmark->meta->toArray() === ['c' => 3]
            && $event->bookmarker === $user && $event->bookmarkable === $post;
    });
    Event::assertNotDispatched(BookmarkCreated::class);
});

test('removed is dispatched only when a row was deleted', function () {
    $user = User::create();
    $post = Post::create();
    Event::fake(BOOKMARK_EVENTS);

    $this->assertFalse($user->unbookmark($post));
    Event::assertNotDispatched(BookmarkRemoved::class);

    $user->bookmark($post);
    $user->unbookmark($post);
    $post->bookmarkBy($user);
    $post->unbookmarkBy($user);
    $user->toggleBookmark($post);
    $user->toggleBookmark($post);

    Event::assertDispatchedTimes(BookmarkCreated::class, 3);
    Event::assertDispatchedTimes(BookmarkRemoved::class, 3);
    Event::assertDispatched(BookmarkRemoved::class, function (BookmarkRemoved $event) use ($user, $post) {
        return ! $event->bookmark->exists && $event->bookmarker === $user && $event->bookmarkable === $post;
    });
});

test('cleanup on delete dispatches no removed event', function () {
    $user = User::create();
    $post = Post::create();
    $user->bookmark($post);
    Event::fake(BOOKMARK_EVENTS);

    $post->delete();

    $this->assertSame(0, Bookmark::query()->count());
    Event::assertNotDispatched(BookmarkRemoved::class);
});

test('a cancelled insert throws and dispatches nothing', function () {
    Bookmark::creating(function () {
        return false;
    });
    Event::fake(BOOKMARK_EVENTS);
    $user = User::create();
    $post = Post::create();

    foreach ([fn () => $user->bookmark($post), fn () => $user->toggleBookmark($post)] as $write) {
        try {
            $write();
            $this->fail('A cancelled insert returned a bookmark.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cancelled saving the bookmark, so it was not created', $exception->getMessage());
        }
    }

    $this->assertSame(0, Bookmark::query()->count());
    Event::assertNotDispatched(BookmarkCreated::class);
});

test('a cancelled update throws keeps the row and dispatches nothing', function () {
    $user = User::create();
    $post = Post::create();
    $user->bookmark($post, ['a' => 1]);
    Bookmark::updating(function () {
        return false;
    });
    Event::fake(BOOKMARK_EVENTS);

    try {
        $user->bookmark($post, ['a' => 2]);
        $this->fail('A cancelled update returned a bookmark.');
    } catch (RuntimeException $exception) {
        $this->assertStringContainsString('so it was not updated', $exception->getMessage());
    }

    $this->assertSame(['a' => 1], $user->bookmarkOf($post)?->meta->toArray());
    Event::assertNotDispatched(BookmarkUpdated::class);
});

test('metadata a model event changes is what is returned and dispatched', function () {
    Bookmark::saving(function (Bookmark $bookmark) {
        $bookmark->meta = ['seen' => true] + $bookmark->meta->toArray();
    });
    Event::fake(BOOKMARK_EVENTS);
    $user = User::create();
    $post = Post::create();

    $bookmark = $user->bookmark($post, ['a' => 1]);

    $this->assertEquals(['seen' => true, 'a' => 1], $bookmark->meta->toArray());
    $this->assertSame(json_encode($bookmark->meta), json_encode($user->bookmarkOf($post)?->meta));
    Event::assertDispatched(BookmarkCreated::class, function (BookmarkCreated $event) {
        return $event->bookmark->meta->seen === true;
    });
});

test('a model event that restores the stored metadata dispatches no update', function () {
    $user = User::create();
    $post = Post::create();
    $user->bookmark($post, ['a' => 1]);
    Bookmark::saving(function (Bookmark $bookmark) {
        $bookmark->meta = ['a' => 1];
    });
    Event::fake(BOOKMARK_EVENTS);

    $this->assertSame(['a' => 1], $user->bookmark($post, ['a' => 2])->meta->toArray());

    Event::assertNotDispatched(BookmarkUpdated::class);
});

test('inside a transaction events are dispatched before it commits', function () {
    $user = User::create();
    $post = Post::create();
    $levels = [];
    Event::listen(BookmarkCreated::class, function () use (&$levels) {
        $levels[] = DB::transactionLevel();
    });

    DB::transaction(function () use ($user, $post) {
        $user->bookmark($post);
    });
    $user->unbookmark($post);
    $user->bookmark($post);

    $this->assertSame([1, 0], $levels);
});
