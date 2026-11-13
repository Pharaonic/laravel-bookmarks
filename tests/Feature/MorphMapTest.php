<?php

use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Pharaonic\Laravel\Bookmarks\Models\Bookmark;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Post;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Product;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\User;

test('without a morph map the class names are stored', function () {
    User::create()->bookmark(Post::create());

    $bookmark = Bookmark::query()->firstOrFail();

    $this->assertSame(User::class, $bookmark->bookmarker_type);
    $this->assertSame(Post::class, $bookmark->bookmarkable_type);
});

test('morph map aliases are stored and resolved', function () {
    Relation::morphMap(['user' => User::class, 'post' => Post::class]);

    $user = User::create();
    $post = Post::create();
    $user->bookmark($post);

    $bookmark = Bookmark::query()->firstOrFail();

    $this->assertSame('user', $bookmark->bookmarker_type);
    $this->assertSame('post', $bookmark->bookmarkable_type);
    $this->assertTrue($bookmark->bookmarker->is($user));
    $this->assertTrue($bookmark->bookmarkable->is($post));
    $this->assertTrue($post->bookmarkedBy($user));
    $this->assertSame(1, Post::whereBookmarkedBy($user)->count());
    $this->assertSame(1, (int) Post::withCount('bookmarks')->firstOrFail()->getAttribute('bookmarks_count'));
});

test('an enforced morph map refuses an unmapped model before writing', function () {
    if (! method_exists(Relation::class, 'enforceMorphMap')) {
        $this->markTestSkipped('Relation::enforceMorphMap() needs Laravel 8.');
    }

    Relation::enforceMorphMap(['user' => User::class, 'post' => Post::class]);

    $user = User::create();
    $this->assertTrue($user->bookmark(Post::create())->exists);

    try {
        $user->bookmark(Product::create());
        $this->fail('An unmapped model was bookmarked.');
    } catch (ClassMorphViolationException $exception) {
        $this->assertStringContainsString(Product::class, $exception->getMessage());
    }

    $this->assertSame(1, Bookmark::query()->count());
});
