<?php

namespace Pharaonic\Laravel\Bookmarks\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Pharaonic\Laravel\Bookmarks\Events\BookmarkCreated;
use Pharaonic\Laravel\Bookmarks\Events\BookmarkRemoved;
use Pharaonic\Laravel\Bookmarks\Events\BookmarkUpdated;
use Pharaonic\Laravel\Bookmarks\Models\Bookmark;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\CustomBookmark;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Post;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\SecondaryBookmark;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\User;
use Pharaonic\Laravel\Bookmarks\Tests\TestCase;
use UnexpectedValueException;

class CustomModelTest extends TestCase
{
    public function test_the_configured_model_and_its_table_are_used_everywhere(): void
    {
        $this->useModel(CustomBookmark::class);

        $this->assertTrue(Schema::hasTable('saved_items'));
        $this->assertFalse(Schema::hasTable('bookmarks'));

        Event::fake([BookmarkCreated::class, BookmarkUpdated::class, BookmarkRemoved::class]);
        $user = User::create();
        $post = Post::create();

        $bookmark = $user->bookmark($post, ['pinned' => true]);
        $this->assertInstanceOf(CustomBookmark::class, $bookmark);
        $this->assertTrue($bookmark->isPinned());
        $this->assertInstanceOf(CustomBookmark::class, $user->bookmark($post, ['pinned' => false]));
        $this->assertInstanceOf(CustomBookmark::class, $user->bookmarkOf($post));
        $this->assertInstanceOf(CustomBookmark::class, $user->givenBookmarks()->firstOrFail());
        $this->assertInstanceOf(CustomBookmark::class, $post->bookmarks()->firstOrFail());
        $this->assertInstanceOf(CustomBookmark::class, Post::with('bookmarks')->firstOrFail()->bookmarks->first());
        $this->assertTrue($user->hasBookmarked($post));
        $this->assertSame(1, Post::whereBookmarkedBy($user)->count());
        $this->assertSame(0, Post::whereNotBookmarkedBy($user)->count());
        $this->assertTrue((bool) Post::withBookmarkedBy($user)->firstOrFail()->getAttribute('is_bookmarked'));
        $this->assertSame(1, (int) Post::withCount('bookmarks')->firstOrFail()->getAttribute('bookmarks_count'));
        $this->assertSame(1, DB::table('saved_items')->count());

        $this->assertNull($user->toggleBookmark($post));
        $this->assertInstanceOf(CustomBookmark::class, $user->toggleBookmark($post));

        foreach ([BookmarkCreated::class, BookmarkUpdated::class, BookmarkRemoved::class] as $event) {
            Event::assertDispatched($event, function ($event) {
                return $event->bookmark instanceof CustomBookmark;
            });
        }

        $post->delete();
        $this->assertSame(0, DB::table('saved_items')->count());
    }

    public function test_a_model_on_another_connection_gets_its_table_there(): void
    {
        $this->app['config']->set('database.connections.secondary', $this->app['config']->get('database.connections.testing'));
        $this->useModel(SecondaryBookmark::class);

        $this->assertTrue(Schema::connection('secondary')->hasTable('bookmarks'));

        if (DB::connection()->getDriverName() === 'sqlite') {
            // Two in-memory SQLite connections are two databases.
            $this->assertFalse(Schema::hasTable('bookmarks'));
        }

        $user = User::create();
        $post = Post::create();
        $bookmark = $user->bookmark($post, ['a' => 1]);

        $this->assertInstanceOf(SecondaryBookmark::class, $bookmark);
        $this->assertSame(1, DB::connection('secondary')->table('bookmarks')->count());
        $this->assertTrue($user->hasBookmarked($post));
        $this->assertCount(1, $user->givenBookmarks()->get());
        $this->assertTrue($user->unbookmark($post));
    }

    public function test_a_model_that_is_not_a_bookmark_is_refused(): void
    {
        $this->app['config']->set('pharaonic.bookmarks.model', Post::class);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('must name '.Bookmark::class.' or a subclass of it, not ['.Post::class.']');

        User::create()->bookmark(Post::create());
    }

    private function useModel(string $class): void
    {
        $kernel = $this->app->make(Kernel::class);
        $this->assertSame(0, $kernel->call('migrate:rollback'));
        $this->app['config']->set('pharaonic.bookmarks.model', $class);
        $this->assertSame(0, $kernel->call('migrate'));
    }
}
