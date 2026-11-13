<?php

namespace Pharaonic\Laravel\Bookmarks\Tests\Feature;

use Pharaonic\Laravel\Bookmarks\Models\Bookmark;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Post;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\UlidModel;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\User;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\UuidModel;
use Pharaonic\Laravel\Bookmarks\Tests\TestCase;

class KeyTypesTest extends TestCase
{
    public function test_integer_uuid_and_ulid_keys_share_the_table(): void
    {
        $user = User::create();
        $uuid = UuidModel::create();
        $ulid = UlidModel::create();
        $post = Post::create();

        $this->assertSame(36, strlen($uuid->getKey()));
        $this->assertSame(26, strlen($ulid->getKey()));

        $user->bookmark($uuid);
        $uuid->bookmark($post);
        $first = $ulid->bookmark($uuid);
        $uuid->bookmark($ulid);
        $this->assertSame($first->getKey(), $ulid->bookmark($uuid)->getKey());

        $this->assertTrue($uuid->bookmarkedBy($user));
        $this->assertTrue($post->bookmarkedBy($uuid));
        $this->assertTrue($ulid->hasBookmarked($uuid));
        $this->assertSame(4, Bookmark::query()->count());

        $bookmark = Bookmark::whereBookmarker($ulid)->firstOrFail();
        $this->assertSame((string) $ulid->getKey(), $bookmark->bookmarker_id);
        $this->assertTrue($bookmark->bookmarker->is($ulid));
        $this->assertTrue($bookmark->bookmarkable->is($uuid));
    }

    public function test_integer_keys_are_stored_as_strings(): void
    {
        $user = User::create();
        $post = Post::create();
        $user->bookmark($post);

        $bookmark = Bookmark::query()->firstOrFail();

        $this->assertSame((string) $user->getKey(), $bookmark->bookmarker_id);
        $this->assertSame((string) $post->getKey(), $bookmark->bookmarkable_id);
    }

    public function test_queries_work_on_string_keyed_parents(): void
    {
        $user = User::create();
        [$first, $second] = [UuidModel::create(), UuidModel::create()];
        $ulid = UlidModel::create();

        $user->bookmark($first);
        $ulid->bookmark($first);
        $ulid->bookmark($second);

        $this->assertSame([$first->id], UuidModel::whereBookmarkedBy($user)->pluck('id')->all());
        $this->assertSame(2, UuidModel::whereBookmarkedBy($ulid)->count());

        $counts = UuidModel::withCount('bookmarks')->get()->pluck('bookmarks_count', 'id')->map(function ($value) {
            return (int) $value;
        });
        $this->assertSame(2, $counts[$first->id]);
        $this->assertSame(1, $counts[$second->id]);

        $models = UuidModel::with('bookmarks.bookmarker')->get()->keyBy('id');
        $this->assertCount(2, $models[$first->id]->bookmarks);
        $this->assertCount(2, UlidModel::with('givenBookmarks.bookmarkable')->firstOrFail()->givenBookmarks);
    }
}
