<?php

namespace Pharaonic\Laravel\Bookmarks\Tests\Feature;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use Pharaonic\Laravel\Bookmarks\Models\Bookmark;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Bot;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Comment;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Post;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\User;
use Pharaonic\Laravel\Bookmarks\Tests\TestCase;

class RelationshipsTest extends TestCase
{
    public function test_given_bookmarks_and_bookmarks_are_morph_many_relations(): void
    {
        $user = User::create();

        $this->assertInstanceOf(MorphMany::class, $user->givenBookmarks());
        $this->assertSame('bookmarker_type', $user->givenBookmarks()->getMorphType());
        $this->assertSame('bookmarks.bookmarker_id', $user->givenBookmarks()->getQualifiedForeignKeyName());

        $this->assertInstanceOf(MorphMany::class, $user->bookmarks());
        $this->assertSame('bookmarkable_type', $user->bookmarks()->getMorphType());
        $this->assertSame('bookmarks.bookmarkable_id', $user->bookmarks()->getQualifiedForeignKeyName());
    }

    public function test_a_model_with_both_traits_keeps_given_and_received_bookmarks_apart(): void
    {
        $alice = User::create();
        $bob = User::create();

        $alice->bookmark($bob);

        $this->assertSame(1, $alice->givenBookmarks()->count());
        $this->assertSame(0, $alice->bookmarks()->count());
        $this->assertSame(0, $bob->givenBookmarks()->count());
        $this->assertSame(1, $bob->bookmarks()->count());
        $this->assertTrue($bob->bookmarkedBy($alice));
        $this->assertFalse($alice->bookmarkedBy($bob));
    }

    public function test_the_bookmark_resolves_both_ends_through_morph_to(): void
    {
        $bot = Bot::create();
        $comment = Comment::create();
        $bot->bookmark($comment);

        $bookmark = Bookmark::query()->firstOrFail();

        $this->assertInstanceOf(MorphTo::class, $bookmark->bookmarker());
        $this->assertInstanceOf(MorphTo::class, $bookmark->bookmarkable());
        $this->assertTrue($bookmark->bookmarker->is($bot));
        $this->assertTrue($bookmark->bookmarkable->is($comment));
    }

    public function test_bookmarks_count_runs_a_count_query(): void
    {
        $post = Post::create();
        User::create()->bookmark($post);
        User::create()->bookmark($post);

        DB::enableQueryLog();
        $count = $post->bookmarks()->count();
        $queries = DB::getQueryLog();

        $this->assertSame(2, $count);
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('count(*)', $queries[0]['query']);
        $this->assertFalse($post->relationLoaded('bookmarks'));
    }

    public function test_with_count_and_load_count_count_the_bookmarks(): void
    {
        [$first, $second] = [Post::create(), Post::create()];
        User::create()->bookmark($first);
        User::create()->bookmark($first);
        User::create()->bookmark($second);
        Post::create();

        $counts = Post::withCount('bookmarks')->orderBy('id')->get()->pluck('bookmarks_count')->map(function ($value) {
            return (int) $value;
        })->all();
        $this->assertSame([2, 1, 0], $counts);

        $second->loadCount('bookmarks');
        $this->assertSame(1, (int) $second->getAttribute('bookmarks_count'));
    }

    public function test_eager_loading_bookmarks_and_bookmarkers_takes_a_fixed_number_of_queries(): void
    {
        foreach (range(1, 3) as $i) {
            $post = Post::create();
            User::create()->bookmark($post);
            Bot::create()->bookmark($post);
        }

        DB::enableQueryLog();
        $posts = Post::with('bookmarks.bookmarker')->get();
        $names = $posts->flatMap(function (Post $post) {
            return $post->bookmarks->map(function (Bookmark $bookmark) {
                return get_class($bookmark->bookmarker);
            });
        });

        // posts, bookmarks, users, bots.
        $this->assertCount(4, DB::getQueryLog());
        $this->assertCount(6, $names);
    }

    public function test_given_bookmarks_eager_load_their_bookmarkables(): void
    {
        $user = User::create();
        $user->bookmark(Post::create());
        $user->bookmark(Comment::create());

        $bookmarks = $user->givenBookmarks()->with('bookmarkable')->latest()->get();

        $this->assertCount(2, $bookmarks);
        $this->assertEqualsCanonicalizing(
            [Post::class, Comment::class],
            $bookmarks->map(function (Bookmark $bookmark) {
                return get_class($bookmark->bookmarkable);
            })->all()
        );
    }

    public function test_where_bookmarked_by_keeps_only_the_models_the_bookmarker_bookmarked(): void
    {
        $alice = User::create();
        $bob = User::create();
        [$first, $second, $third] = [Post::create(), Post::create(), Post::create()];

        $alice->bookmark($first);
        $alice->bookmark($third);
        $bob->bookmark($second);
        Bot::create()->bookmark($second);

        $this->assertSame([$first->id, $third->id], Post::whereBookmarkedBy($alice)->orderBy('id')->pluck('id')->map(function ($value) {
            return (int) $value;
        })->all());
        $this->assertSame([$second->id], Post::whereBookmarkedBy($bob)->pluck('id')->map(function ($value) {
            return (int) $value;
        })->all());
        $this->assertSame(0, Comment::whereBookmarkedBy($alice)->count());
    }

    public function test_a_feed_can_flag_what_the_viewer_bookmarked_in_the_same_query(): void
    {
        $viewer = User::create();
        [$first, $second] = [Post::create(), Post::create()];
        $viewer->bookmark($second);
        User::create()->bookmark($first);

        $posts = Post::withCount([
            'bookmarks',
            'bookmarks as bookmarked_by_viewer' => function ($query) use ($viewer) {
                $query->whereBookmarker($viewer);
            },
        ])->orderBy('id')->get();

        $this->assertSame([0, 1], $posts->pluck('bookmarked_by_viewer')->map(function ($value) {
            return (int) $value;
        })->all());
        $this->assertSame([1, 1], $posts->pluck('bookmarks_count')->map(function ($value) {
            return (int) $value;
        })->all());
    }

    public function test_bookmark_scopes_filter_by_bookmarker_and_bookmarkable(): void
    {
        $user = User::create();
        $post = Post::create();
        $user->bookmark($post);
        Bot::create()->bookmark($post);

        $this->assertSame(1, Bookmark::whereBookmarker($user)->count());
        $this->assertSame(2, Bookmark::whereBookmarkable($post)->count());
        $this->assertSame(1, User::whereHas('givenBookmarks', function ($query) use ($post) {
            $query->whereBookmarkable($post);
        })->count());
    }

    public function test_where_not_bookmarked_by_keeps_the_other_models(): void
    {
        $alice = User::create();
        [$first, $second, $third] = [Post::create(), Post::create(), Post::create()];
        $alice->bookmark($first);
        User::create()->bookmark($second);

        $this->assertSame([$second->id, $third->id], $this->ids(Post::whereNotBookmarkedBy($alice)->orderBy('id')->pluck('id')));
        $this->assertSame(3, Post::whereNotBookmarkedBy(Bot::create())->count());
    }

    public function test_an_unsaved_bookmarker_matches_nothing_in_the_scopes(): void
    {
        $post = Post::create();
        User::create()->bookmark($post);
        Post::create();
        $unsaved = new User;

        $this->assertSame(0, Post::whereBookmarkedBy($unsaved)->count());
        $this->assertSame(2, Post::whereNotBookmarkedBy($unsaved)->count());
        $this->assertSame([0, 0], $this->ids(Post::withBookmarkedBy($unsaved)->orderBy('id')->pluck('is_bookmarked')));
    }

    public function test_with_bookmarked_by_flags_each_row_in_one_query(): void
    {
        $viewer = User::create();
        [$first, $second, $third] = [Post::create(['name' => 'a']), Post::create(['name' => 'b']), Post::create(['name' => 'c'])];
        $viewer->bookmark($second);
        User::create()->bookmark($first);
        Bot::create()->bookmark($third);

        DB::enableQueryLog();
        $posts = Post::withBookmarkedBy($viewer)->orderBy('id')->get();

        $this->assertCount(1, DB::getQueryLog());
        $this->assertSame([false, true, false], $posts->map(function (Post $post) {
            return (bool) $post->getAttribute('is_bookmarked');
        })->all());
        $this->assertSame(['a', 'b', 'c'], $posts->pluck('name')->all());
    }

    public function test_with_bookmarked_by_keeps_the_columns_already_selected_and_combines_with_counts(): void
    {
        $viewer = User::create();
        $post = Post::create(['name' => 'a']);
        $viewer->bookmark($post);
        User::create()->bookmark($post);

        $row = Post::query()->select('id')->withBookmarkedBy($viewer)->withCount('bookmarks')->firstOrFail();

        $this->assertSame(['id', 'is_bookmarked', 'bookmarks_count'], array_keys($row->getAttributes()));
        $this->assertTrue((bool) $row->getAttribute('is_bookmarked'));
        $this->assertSame(2, (int) $row->getAttribute('bookmarks_count'));
    }

    public function test_bookmarked_models_paginate(): void
    {
        $user = User::create();

        foreach (range(1, 5) as $i) {
            $user->bookmark(Post::create());
        }

        Post::create();

        $page = Post::whereBookmarkedBy($user)->withBookmarkedBy($user)->orderBy('id')->paginate(2, ['*'], 'page', 2);

        $this->assertSame(5, $page->total());
        $this->assertCount(2, $page->items());
        $this->assertTrue((bool) $page->items()[0]->getAttribute('is_bookmarked'));
    }

    public function test_with_exists_and_where_has_work_on_the_relations(): void
    {
        $user = User::create();
        $post = Post::create();
        $user->bookmark($post, ['priority' => 5]);
        Post::create();

        $this->assertSame(1, Post::whereHas('bookmarks', function ($query) {
            $query->where('meta->priority', 5);
        })->count());
        $this->assertSame(1, Post::doesntHave('bookmarks')->count());
        $this->assertSame(1, User::has('givenBookmarks')->count());
    }

    /**
     * @param  iterable<mixed>  $values
     * @return array<int, int>
     */
    private function ids(iterable $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            $ids[] = (int) $value;
        }

        return $ids;
    }
}
