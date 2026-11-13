<?php

namespace Pharaonic\Laravel\Bookmarks\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Pharaonic\Laravel\Bookmarks\Events\BookmarkCreated;
use Pharaonic\Laravel\Bookmarks\Events\BookmarkRemoved;
use Pharaonic\Laravel\Bookmarks\Events\BookmarkUpdated;
use Pharaonic\Laravel\Bookmarks\Models\Bookmark;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Post;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\User;
use Pharaonic\Laravel\Bookmarks\Tests\TestCase;
use RuntimeException;

class EventsTest extends TestCase
{
    private const EVENTS = [BookmarkCreated::class, BookmarkUpdated::class, BookmarkRemoved::class];

    public function test_created_is_dispatched_once_with_the_bookmark_and_both_models(): void
    {
        Event::fake(self::EVENTS);
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
    }

    public function test_updated_is_dispatched_only_for_different_metadata(): void
    {
        $user = User::create();
        $post = Post::create();
        $user->bookmark($post, ['a' => 1, 'b' => 2]);
        Event::fake(self::EVENTS);

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
    }

    public function test_removed_is_dispatched_only_when_a_row_was_deleted(): void
    {
        $user = User::create();
        $post = Post::create();
        Event::fake(self::EVENTS);

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
    }

    public function test_cleanup_on_delete_dispatches_no_removed_event(): void
    {
        $user = User::create();
        $post = Post::create();
        $user->bookmark($post);
        Event::fake(self::EVENTS);

        $post->delete();

        $this->assertSame(0, Bookmark::query()->count());
        Event::assertNotDispatched(BookmarkRemoved::class);
    }

    public function test_a_cancelled_insert_throws_and_dispatches_nothing(): void
    {
        Bookmark::creating(function () {
            return false;
        });
        Event::fake(self::EVENTS);
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
    }

    public function test_a_cancelled_update_throws_keeps_the_row_and_dispatches_nothing(): void
    {
        $user = User::create();
        $post = Post::create();
        $user->bookmark($post, ['a' => 1]);
        Bookmark::updating(function () {
            return false;
        });
        Event::fake(self::EVENTS);

        try {
            $user->bookmark($post, ['a' => 2]);
            $this->fail('A cancelled update returned a bookmark.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('so it was not updated', $exception->getMessage());
        }

        $this->assertSame(['a' => 1], $user->bookmarkOf($post)?->meta->toArray());
        Event::assertNotDispatched(BookmarkUpdated::class);
    }

    public function test_metadata_a_model_event_changes_is_what_is_returned_and_dispatched(): void
    {
        Bookmark::saving(function (Bookmark $bookmark) {
            $bookmark->meta = ['seen' => true] + $bookmark->meta->toArray();
        });
        Event::fake(self::EVENTS);
        $user = User::create();
        $post = Post::create();

        $bookmark = $user->bookmark($post, ['a' => 1]);

        $this->assertEquals(['seen' => true, 'a' => 1], $bookmark->meta->toArray());
        $this->assertSame(json_encode($bookmark->meta), json_encode($user->bookmarkOf($post)?->meta));
        Event::assertDispatched(BookmarkCreated::class, function (BookmarkCreated $event) {
            return $event->bookmark->meta->seen === true;
        });
    }

    public function test_a_model_event_that_restores_the_stored_metadata_dispatches_no_update(): void
    {
        $user = User::create();
        $post = Post::create();
        $user->bookmark($post, ['a' => 1]);
        Bookmark::saving(function (Bookmark $bookmark) {
            $bookmark->meta = ['a' => 1];
        });
        Event::fake(self::EVENTS);

        $this->assertSame(['a' => 1], $user->bookmark($post, ['a' => 2])->meta->toArray());

        Event::assertNotDispatched(BookmarkUpdated::class);
    }

    public function test_inside_a_transaction_events_are_dispatched_before_it_commits(): void
    {
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
    }
}
