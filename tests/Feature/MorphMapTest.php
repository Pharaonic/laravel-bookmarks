<?php

namespace Pharaonic\Laravel\Bookmarks\Tests\Feature;

use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Pharaonic\Laravel\Bookmarks\Models\Bookmark;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Post;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Product;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\User;
use Pharaonic\Laravel\Bookmarks\Tests\TestCase;

class MorphMapTest extends TestCase
{
    public function test_without_a_morph_map_the_class_names_are_stored(): void
    {
        User::create()->bookmark(Post::create());

        $bookmark = Bookmark::query()->firstOrFail();

        $this->assertSame(User::class, $bookmark->bookmarker_type);
        $this->assertSame(Post::class, $bookmark->bookmarkable_type);
    }

    public function test_morph_map_aliases_are_stored_and_resolved(): void
    {
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
    }

    public function test_an_enforced_morph_map_refuses_an_unmapped_model_before_writing(): void
    {
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
    }
}
