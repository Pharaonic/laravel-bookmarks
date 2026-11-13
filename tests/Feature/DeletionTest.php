<?php

namespace Pharaonic\Laravel\Bookmarks\Tests\Feature;

use Pharaonic\Laravel\Bookmarks\Models\Bookmark;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Bot;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Post;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Product;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\SoftDeletableMember;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\SoftDeletablePost;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\User;
use Pharaonic\Laravel\Bookmarks\Tests\TestCase;

class DeletionTest extends TestCase
{
    public function test_deleting_a_bookmarkable_deletes_the_bookmarks_it_received(): void
    {
        $user = User::create();
        [$deleted, $kept] = [Post::create(), Post::create()];
        $user->bookmark($deleted);
        Bot::create()->bookmark($deleted);
        $user->bookmark($kept);

        $deleted->delete();

        $this->assertSame(0, Bookmark::whereBookmarkable($deleted)->count());
        $this->assertTrue($user->hasBookmarked($kept));
        $this->assertSame(1, Bookmark::query()->count());
    }

    public function test_deleting_a_bookmarker_deletes_the_bookmarks_it_gave(): void
    {
        $bot = Bot::create();
        $other = Bot::create();
        $post = Post::create();
        $bot->bookmark($post);
        $bot->bookmark(Product::create());
        $other->bookmark($post);

        $bot->delete();

        $this->assertSame(0, Bookmark::whereBookmarker($bot)->count());
        $this->assertTrue($post->bookmarkedBy($other));
    }

    public function test_deleting_a_model_with_both_traits_deletes_both_directions(): void
    {
        $alice = User::create();
        $bob = User::create();
        $post = Post::create();

        $alice->bookmark($bob);
        $bob->bookmark($alice);
        $alice->bookmark($alice);
        $alice->bookmark($post);
        $bob->bookmark($post);

        $alice->delete();

        $this->assertSame(1, Bookmark::query()->count());
        $this->assertTrue($post->bookmarkedBy($bob));
    }

    public function test_a_soft_deleted_bookmarkable_keeps_its_bookmarks_until_force_deleted(): void
    {
        $user = User::create();
        $post = SoftDeletablePost::create();
        $user->bookmark($post);

        $post->delete();
        $this->assertSame(1, Bookmark::query()->count());

        $post->restore();
        $this->assertTrue($post->bookmarkedBy($user));

        $post->delete();
        $post->forceDelete();
        $this->assertSame(0, Bookmark::query()->count());
    }

    public function test_force_deleting_a_bookmarkable_that_was_never_soft_deleted_deletes_its_bookmarks(): void
    {
        $post = SoftDeletablePost::create();
        User::create()->bookmark($post);

        $post->forceDelete();

        $this->assertSame(0, Bookmark::query()->count());
    }

    public function test_a_soft_deleted_bookmarker_keeps_its_bookmarks_until_force_deleted(): void
    {
        $member = SoftDeletableMember::create();
        $post = Post::create();
        $member->bookmark($post);

        $member->delete();
        $this->assertSame(1, Bookmark::query()->count());

        $member->restore();
        $this->assertTrue($member->hasBookmarked($post));

        $member->forceDelete();
        $this->assertSame(0, Bookmark::query()->count());
    }

    public function test_cleanup_can_be_turned_off(): void
    {
        $this->app['config']->set('pharaonic.bookmarks.cleanup_on_delete', false);

        $user = User::create();
        $post = Post::create();
        $user->bookmark($post);
        $post->delete();
        $user->delete();

        $this->assertSame(1, Bookmark::query()->count());
    }

    public function test_query_builder_deletes_skip_model_events_and_cleanup(): void
    {
        $post = Post::create();
        User::create()->bookmark($post);

        Post::query()->whereKey($post->getKey())->delete();

        $this->assertSame(1, Bookmark::query()->count());

        // The documented manual cleanup.
        Bookmark::query()->where('bookmarkable_type', $post->getMorphClass())
            ->whereNotIn('bookmarkable_id', Post::query()->pluck('id')->map(function ($value) {
                return (string) $value;
            }))
            ->delete();

        $this->assertSame(0, Bookmark::query()->count());
    }
}
