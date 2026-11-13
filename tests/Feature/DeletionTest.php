<?php

use Pharaonic\Laravel\Bookmarks\Models\Bookmark;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Bot;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Post;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Product;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\SoftDeletableMember;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\SoftDeletablePost;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\User;

test('deleting a bookmarkable deletes the bookmarks it received', function () {
    $user = User::create();
    [$deleted, $kept] = [Post::create(), Post::create()];
    $user->bookmark($deleted);
    Bot::create()->bookmark($deleted);
    $user->bookmark($kept);

    $deleted->delete();

    $this->assertSame(0, Bookmark::whereBookmarkable($deleted)->count());
    $this->assertTrue($user->hasBookmarked($kept));
    $this->assertSame(1, Bookmark::query()->count());
});

test('deleting a bookmarker deletes the bookmarks it gave', function () {
    $bot = Bot::create();
    $other = Bot::create();
    $post = Post::create();
    $bot->bookmark($post);
    $bot->bookmark(Product::create());
    $other->bookmark($post);

    $bot->delete();

    $this->assertSame(0, Bookmark::whereBookmarker($bot)->count());
    $this->assertTrue($post->bookmarkedBy($other));
});

test('deleting a model with both traits deletes both directions', function () {
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
});

test('a soft deleted bookmarkable keeps its bookmarks until force deleted', function () {
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
});

test('force deleting a bookmarkable that was never soft deleted deletes its bookmarks', function () {
    $post = SoftDeletablePost::create();
    User::create()->bookmark($post);

    $post->forceDelete();

    $this->assertSame(0, Bookmark::query()->count());
});

test('a soft deleted bookmarker keeps its bookmarks until force deleted', function () {
    $member = SoftDeletableMember::create();
    $post = Post::create();
    $member->bookmark($post);

    $member->delete();
    $this->assertSame(1, Bookmark::query()->count());

    $member->restore();
    $this->assertTrue($member->hasBookmarked($post));

    $member->forceDelete();
    $this->assertSame(0, Bookmark::query()->count());
});

test('cleanup can be turned off', function () {
    $this->app['config']->set('pharaonic.bookmarks.cleanup_on_delete', false);

    $user = User::create();
    $post = Post::create();
    $user->bookmark($post);
    $post->delete();
    $user->delete();

    $this->assertSame(1, Bookmark::query()->count());
});

test('query builder deletes skip model events and cleanup', function () {
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
});
