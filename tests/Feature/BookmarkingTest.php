<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Pharaonic\Laravel\Bookmarks\Models\Bookmark;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Bot;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Post;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Product;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\User;
use Pharaonic\Laravel\Bookmarks\ValueObjects\BookmarkMeta;

afterEach(function () {
    Carbon::setTestNow();
});

test('the readme example runs', function () {
    $user = User::create();
    $post = Post::create();

    $bookmark = $user->bookmark($post, [
        'note' => 'Read later',
        'priority' => 5,
        'read' => false,
        'preferences' => ['notify' => true],
    ]);

    $this->assertSame(5, $bookmark->meta->priority);
    $this->assertTrue($bookmark->meta->preferences->notify);
    $this->assertSame('none', $bookmark->meta->get('missing', 'none'));

    $user->bookmark($post, ['priority' => 2]);
    $bookmark = $user->bookmarkOf($post);
    $this->assertInstanceOf(Bookmark::class, $bookmark);
    $this->assertNull($bookmark->meta->get('note'));
    $this->assertSame(2, $bookmark->meta->priority);

    $this->assertSame(['priority' => 2], $user->bookmark($post)->meta->toArray());
    $this->assertTrue($user->bookmark($post, [])->meta->isEmpty());
    $this->assertTrue($user->hasBookmarked($post));
    $this->assertTrue($post->bookmarkedBy($user));

    $this->assertTrue($user->unbookmark($post));
    $this->assertFalse($user->unbookmark($post));
});

test('a new bookmark without metadata stores the empty object', function () {
    $bookmark = User::create()->bookmark(Post::create());

    $this->assertTrue($bookmark->exists);
    $this->assertInstanceOf(BookmarkMeta::class, $bookmark->meta);
    $this->assertTrue($bookmark->meta->isEmpty());
    $this->assertSame('{}', DB::table('bookmarks')->value('meta'));
});

test('metadata given on creation survives a reload', function () {
    $user = User::create();
    $post = Post::create();
    $user->bookmark($post, ['a' => ['b' => [1, 2.5, 'c', null, true]], 'o' => new stdClass]);

    $meta = Bookmark::query()->firstOrFail()->meta;

    $this->assertSame([1, 2.5, 'c', null, true], $meta->a->b);
    $this->assertSame('{"a":{"b":[1,2.5,"c",null,true]},"o":{}}', json_encode($meta));
});

test('bookmarks of different models are separate', function () {
    $user = User::create();
    $post = Post::create();
    $product = Product::create();

    $user->bookmark($post, ['kind' => 'post']);
    $user->bookmark($product, ['kind' => 'product']);
    Bot::create()->bookmark($post);

    $this->assertSame(3, Bookmark::query()->count());
    $this->assertSame('post', $user->bookmarkOf($post)?->meta->kind);
    $this->assertSame('product', $user->bookmarkOf($product)?->meta->kind);
});

test('metadata replaces the whole object', function () {
    $user = User::create();
    $post = Post::create();

    $user->bookmark($post, ['a' => 1, 'b' => 2]);
    $bookmark = $user->bookmark($post, ['b' => 3]);

    $this->assertSame(['b' => 3], $bookmark->meta->toArray());
    $this->assertSame(['b' => 3], $user->bookmarkOf($post)?->meta->toArray());
    $this->assertSame(1, Bookmark::query()->count());
});

test('omitted or null metadata keeps the existing metadata', function () {
    $user = User::create();
    $post = Post::create();
    $first = $user->bookmark($post, ['a' => 1]);

    $this->assertSame(['a' => 1], $user->bookmark($post)->meta->toArray());
    $this->assertSame(['a' => 1], $user->bookmark($post, null)->meta->toArray());
    $this->assertSame($first->getKey(), $user->bookmark($post)->getKey());
});

test('the empty array resets the metadata to the empty object', function () {
    $user = User::create();
    $post = Post::create();
    $user->bookmark($post, ['a' => 1]);

    $this->assertTrue($user->bookmark($post, [])->meta->isEmpty());
    $this->assertSame('{}', DB::table('bookmarks')->value('meta'));
});

test('equal metadata writes nothing and keeps updated at', function () {
    $user = User::create();
    $post = Post::create();
    Carbon::setTestNow(Carbon::parse('2026-01-01 10:00:00'));
    $user->bookmark($post, ['a' => 1, 'nested' => ['x' => 1, 'y' => [1, 2]]]);

    Carbon::setTestNow(Carbon::parse('2026-01-02 10:00:00'));
    $queries = countUpdates(function () use ($user, $post) {
        $user->bookmark($post, ['nested' => ['y' => [1, 2], 'x' => 1], 'a' => 1]);
        $user->bookmark($post, $user->bookmarkOf($post)?->meta);
        $user->bookmark($post);
    });

    $this->assertSame(0, $queries);
    $this->assertSame('2026-01-01 10:00:00', storedUpdatedAt());
});

test('different metadata is one update that keeps created at', function (array|stdClass $old, array|stdClass $new) {
    $user = User::create();
    $post = Post::create();
    Carbon::setTestNow(Carbon::parse('2026-01-01 10:00:00'));
    $user->bookmark($post, $old);

    Carbon::setTestNow(Carbon::parse('2026-01-02 10:00:00'));
    $updates = countUpdates(function () use ($user, $post, $new) {
        $bookmark = $user->bookmark($post, $new);
        $this->assertSame(json_encode((new BookmarkMeta($new))), json_encode($bookmark->meta));
    });

    $this->assertSame(1, $updates);
    $this->assertSame('2026-01-02 10:00:00', storedUpdatedAt());
    $this->assertSame('2026-01-01 10:00:00', (string) DB::table('bookmarks')->value('created_at'));
    $this->assertSame(json_encode(new BookmarkMeta($new)), json_encode($user->bookmarkOf($post)?->meta));
})->with([
    'integer to string' => [['a' => 1], ['a' => '1']],
    'true to one' => [['a' => true], ['a' => 1]],
    'null to missing' => [['a' => null], []],
    'list order' => [['a' => [1, 2]], ['a' => [2, 1]]],
    'nested value' => [['a' => ['x' => 1]], ['a' => ['x' => 2]]],
    'empty list to empty object' => [['a' => []], ['a' => new stdClass]],
    'integer to float' => [['a' => 1], ['a' => 1.0]],
]);

test('bookmark of has bookmarked and unbookmark', function () {
    $user = User::create();
    $post = Post::create();

    $this->assertNull($user->bookmarkOf($post));
    $this->assertFalse($user->hasBookmarked($post));

    $bookmark = $user->bookmark($post);

    $this->assertTrue($bookmark->is($user->bookmarkOf($post)));
    $this->assertTrue($user->hasBookmarked($post));
    $this->assertTrue($user->unbookmark($post));
    $this->assertFalse($user->unbookmark($post));
    $this->assertNull($user->bookmarkOf($post));
});

test('toggle creates with metadata then removes', function () {
    $user = User::create();
    $post = Post::create();

    $created = $user->toggleBookmark($post, ['a' => 1]);
    $this->assertInstanceOf(Bookmark::class, $created);
    $this->assertSame(['a' => 1], $created->meta->toArray());
    $this->assertTrue($user->hasBookmarked($post));

    $this->assertNull($user->toggleBookmark($post, ['ignored' => true]));
    $this->assertFalse($user->hasBookmarked($post));

    $this->assertTrue($user->toggleBookmark($post)?->meta->isEmpty());
});

test('the bookmarkable side does the same as the bookmarker side', function () {
    $user = User::create();
    $post = Post::create();

    $bookmark = $post->bookmarkBy($user, ['a' => 1]);
    $this->assertTrue($bookmark->is($user->bookmarkOf($post)));
    $this->assertTrue($post->bookmarkedBy($user));
    $this->assertSame(['b' => 2], $post->bookmarkBy($user, ['b' => 2])->meta->toArray());
    $this->assertSame(['b' => 2], $post->bookmarkBy($user)->meta->toArray());
    $this->assertTrue($post->unbookmarkBy($user));
    $this->assertFalse($post->unbookmarkBy($user));
    $this->assertFalse($post->bookmarkedBy($user));
});

test('a model can bookmark itself and models of its own class', function () {
    $alice = User::create();
    $bob = User::create();

    $alice->bookmark($alice);
    $alice->bookmark($bob);

    $this->assertTrue($alice->hasBookmarked($alice));
    $this->assertTrue($bob->bookmarkedBy($alice));
    $this->assertFalse($alice->bookmarkedBy($bob));
    $this->assertSame(2, $alice->givenBookmarks()->count());
    $this->assertSame(1, $alice->bookmarks()->count());
});

test('writes with an unsaved model throw and reads match nothing', function () {
    $user = User::create();
    $post = Post::create();
    $user->bookmark($post);
    $unsaved = [new User, new Post];

    foreach ([
        fn () => $user->bookmark($unsaved[1]),
        fn () => $unsaved[0]->bookmark($post),
        fn () => $user->unbookmark($unsaved[1]),
        fn () => $user->toggleBookmark($unsaved[1]),
        fn () => $post->bookmarkBy($unsaved[0]),
        fn () => $post->unbookmarkBy($unsaved[0]),
    ] as $write) {
        try {
            $write();
            $this->fail('An unsaved model was written.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('is not saved', $exception->getMessage());
        }
    }

    $this->assertFalse($user->hasBookmarked($unsaved[1]));
    $this->assertFalse($unsaved[0]->hasBookmarked($post));
    $this->assertNull($user->bookmarkOf($unsaved[1]));
    $this->assertFalse($post->bookmarkedBy($unsaved[0]));
    $this->assertFalse($unsaved[0]->exists);
    $this->assertSame(1, Bookmark::query()->count());
});

test('invalid metadata throws before anything is written', function () {
    $user = User::create();
    $post = Post::create();
    $user->bookmark($post, ['a' => 1]);
    $other = Post::create();

    foreach ([['x', 'y'], ['a' => NAN], ['a' => new \DateTime]] as $meta) {
        foreach ([
            fn () => $user->bookmark($post, $meta),
            fn () => $user->bookmark($other, $meta),
            fn () => $user->toggleBookmark($post, $meta),
            fn () => $post->bookmarkBy($user, $meta),
        ] as $write) {
            try {
                $write();
                $this->fail('Invalid metadata was written.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('JSON', $exception->getMessage());
            }
        }
    }

    $this->assertSame(1, Bookmark::query()->count());
    $this->assertSame(['a' => 1], $user->bookmarkOf($post)?->meta->toArray());
});

test('a returned bookmark does not refresh itself', function () {
    $user = User::create();
    $post = Post::create();
    $first = $user->bookmark($post, ['a' => 1]);
    $user->bookmark($post, ['a' => 2]);

    $this->assertSame(1, $first->meta->a);
    $this->assertSame(2, $first->refresh()->meta->a);
});

function countUpdates(\Closure $callback): int
{
    $updates = 0;
    DB::listen(function ($query) use (&$updates) {
        if (stripos($query->sql, 'update') === 0) {
            $updates++;
        }
    });

    $callback();

    return $updates;
}

function storedUpdatedAt(): string
{
    return (string) DB::table('bookmarks')->value('updated_at');
}
