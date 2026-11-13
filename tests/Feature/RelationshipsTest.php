<?php

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use Pharaonic\Laravel\Bookmarks\Models\Bookmark;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Bot;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Comment;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Post;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\User;

test('given bookmarks and bookmarks are morph many relations', function () {
    $user = User::create();

    $this->assertInstanceOf(MorphMany::class, $user->givenBookmarks());
    $this->assertSame('bookmarker_type', $user->givenBookmarks()->getMorphType());
    $this->assertSame('bookmarks.bookmarker_id', $user->givenBookmarks()->getQualifiedForeignKeyName());

    $this->assertInstanceOf(MorphMany::class, $user->bookmarks());
    $this->assertSame('bookmarkable_type', $user->bookmarks()->getMorphType());
    $this->assertSame('bookmarks.bookmarkable_id', $user->bookmarks()->getQualifiedForeignKeyName());
});

test('a model with both traits keeps given and received bookmarks apart', function () {
    $alice = User::create();
    $bob = User::create();

    $alice->bookmark($bob);

    $this->assertSame(1, $alice->givenBookmarks()->count());
    $this->assertSame(0, $alice->bookmarks()->count());
    $this->assertSame(0, $bob->givenBookmarks()->count());
    $this->assertSame(1, $bob->bookmarks()->count());
    $this->assertTrue($bob->bookmarkedBy($alice));
    $this->assertFalse($alice->bookmarkedBy($bob));
});

test('the bookmark resolves both ends through morph to', function () {
    $bot = Bot::create();
    $comment = Comment::create();
    $bot->bookmark($comment);

    $bookmark = Bookmark::query()->firstOrFail();

    $this->assertInstanceOf(MorphTo::class, $bookmark->bookmarker());
    $this->assertInstanceOf(MorphTo::class, $bookmark->bookmarkable());
    $this->assertTrue($bookmark->bookmarker->is($bot));
    $this->assertTrue($bookmark->bookmarkable->is($comment));
});

test('bookmarks count runs a count query', function () {
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
});

test('with count and load count count the bookmarks', function () {
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
});

test('eager loading bookmarks and bookmarkers takes a fixed number of queries', function () {
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
});

test('given bookmarks eager load their bookmarkables', function () {
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
});

test('where bookmarked by keeps only the models the bookmarker bookmarked', function () {
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
});

test('a feed can flag what the viewer bookmarked in the same query', function () {
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
});

test('bookmark scopes filter by bookmarker and bookmarkable', function () {
    $user = User::create();
    $post = Post::create();
    $user->bookmark($post);
    Bot::create()->bookmark($post);

    $this->assertSame(1, Bookmark::whereBookmarker($user)->count());
    $this->assertSame(2, Bookmark::whereBookmarkable($post)->count());
    $this->assertSame(1, User::whereHas('givenBookmarks', function ($query) use ($post) {
        $query->whereBookmarkable($post);
    })->count());
});

test('where not bookmarked by keeps the other models', function () {
    $alice = User::create();
    [$first, $second, $third] = [Post::create(), Post::create(), Post::create()];
    $alice->bookmark($first);
    User::create()->bookmark($second);

    $this->assertSame([$second->id, $third->id], toIntegers(Post::whereNotBookmarkedBy($alice)->orderBy('id')->pluck('id')));
    $this->assertSame(3, Post::whereNotBookmarkedBy(Bot::create())->count());
});

test('an unsaved bookmarker matches nothing in the scopes', function () {
    $post = Post::create();
    User::create()->bookmark($post);
    Post::create();
    $unsaved = new User;

    $this->assertSame(0, Post::whereBookmarkedBy($unsaved)->count());
    $this->assertSame(2, Post::whereNotBookmarkedBy($unsaved)->count());
    $this->assertSame([0, 0], toIntegers(Post::withBookmarkedBy($unsaved)->orderBy('id')->pluck('is_bookmarked')));
});

test('with bookmarked by flags each row in one query', function () {
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
});

test('with bookmarked by keeps the columns already selected and combines with counts', function () {
    $viewer = User::create();
    $post = Post::create(['name' => 'a']);
    $viewer->bookmark($post);
    User::create()->bookmark($post);

    $row = Post::query()->select('id')->withBookmarkedBy($viewer)->withCount('bookmarks')->firstOrFail();

    $this->assertSame(['id', 'is_bookmarked', 'bookmarks_count'], array_keys($row->getAttributes()));
    $this->assertTrue((bool) $row->getAttribute('is_bookmarked'));
    $this->assertSame(2, (int) $row->getAttribute('bookmarks_count'));
});

test('bookmarked models paginate', function () {
    $user = User::create();

    foreach (range(1, 5) as $i) {
        $user->bookmark(Post::create());
    }

    Post::create();

    $page = Post::whereBookmarkedBy($user)->withBookmarkedBy($user)->orderBy('id')->paginate(2, ['*'], 'page', 2);

    $this->assertSame(5, $page->total());
    $this->assertCount(2, $page->items());
    $this->assertTrue((bool) $page->items()[0]->getAttribute('is_bookmarked'));
});

test('with exists and where has work on the relations', function () {
    $user = User::create();
    $post = Post::create();
    $user->bookmark($post, ['priority' => 5]);
    Post::create();

    $this->assertSame(1, Post::whereHas('bookmarks', function ($query) {
        $query->where('meta->priority', 5);
    })->count());
    $this->assertSame(1, Post::doesntHave('bookmarks')->count());
    $this->assertSame(1, User::has('givenBookmarks')->count());
});

/**
 * @param  iterable<mixed>  $values
 * @return array<int, int>
 */
function toIntegers(iterable $values): array
{
    $ids = [];

    foreach ($values as $value) {
        $ids[] = (int) $value;
    }

    return $ids;
}
