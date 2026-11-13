<?php

namespace Pharaonic\Laravel\Bookmarks\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Pharaonic\Laravel\Bookmarks\Models\Bookmark;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Post;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\User;
use Pharaonic\Laravel\Bookmarks\Tests\TestCase;
use Pharaonic\Laravel\Bookmarks\ValueObjects\BookmarkMeta;
use UnexpectedValueException;

class MetaAttributeTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_assigning_metadata_and_saving_replaces_it(): void
    {
        $bookmark = User::create()->bookmark(Post::create(), ['a' => 1]);

        $bookmark->meta = ['priority' => 5];
        $this->assertTrue($bookmark->isDirty('meta'));
        $bookmark->save();

        $this->assertTrue($bookmark->wasChanged('meta'));
        $this->assertSame(['priority' => 5], $bookmark->fresh()?->meta->toArray());
    }

    public function test_assigning_equal_metadata_leaves_the_attribute_clean(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 10:00:00'));
        $bookmark = User::create()->bookmark(Post::create(), ['a' => 1, 'b' => ['x' => [], 'y' => new \stdClass]]);
        $bookmark = $bookmark->fresh();
        $this->assertInstanceOf(Bookmark::class, $bookmark);

        Carbon::setTestNow(Carbon::parse('2026-01-02 10:00:00'));
        $bookmark->meta = $bookmark->meta->toObject();
        $this->assertFalse($bookmark->isDirty('meta'));

        $bookmark->meta = ['b' => ['y' => new \stdClass, 'x' => []], 'a' => 1];
        $this->assertFalse($bookmark->isDirty('meta'));

        $bookmark->save();
        $this->assertFalse($bookmark->wasChanged());
        $this->assertSame('2026-01-01 10:00:00', (string) DB::table('bookmarks')->value('updated_at'));
    }

    public function test_assigning_equal_metadata_keeps_a_stored_value_the_database_formatted(): void
    {
        $bookmark = User::create()->bookmark(Post::create(), ['b' => 2, 'a' => 1]);

        // MySQL returns JSON columns reformatted: spaces, and its own key order.
        DB::table('bookmarks')->update(['meta' => '{"a": 1, "b": 2}']);
        $bookmark = $bookmark->fresh();
        $this->assertInstanceOf(Bookmark::class, $bookmark);

        $bookmark->meta = ['b' => 2, 'a' => 1];

        $this->assertFalse($bookmark->isDirty('meta'));
        $this->assertSame('{"a": 1, "b": 2}', $bookmark->getAttributes()['meta']);
    }

    public function test_metadata_stays_a_json_object_in_serialization(): void
    {
        $bookmark = User::create()->bookmark(Post::create(), ['empty' => new \stdClass, 'list' => [], 'n' => 1]);

        $expected = (object) ['empty' => new \stdClass, 'list' => [], 'n' => 1];

        $this->assertInstanceOf(BookmarkMeta::class, $bookmark->toArray()['meta']);
        $this->assertEquals($expected, json_decode($bookmark->toJson())->meta);
        $this->assertEquals($expected, json_decode((string) json_encode($bookmark->fresh()))->meta);
    }

    public function test_a_new_bookmark_model_starts_with_the_empty_object(): void
    {
        $bookmark = new Bookmark;

        $this->assertTrue($bookmark->meta->isEmpty());
        $this->assertSame('{}', $bookmark->getAttributes()['meta']);
    }

    public function test_null_and_non_objects_cannot_be_assigned(): void
    {
        $bookmark = User::create()->bookmark(Post::create(), ['a' => 1]);

        foreach ([null, '{"a":2}', ['x', 'y'], 5] as $value) {
            try {
                $bookmark->meta = $value;
                $this->fail('A value that is not a JSON object was assigned.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }

        $this->assertFalse($bookmark->isDirty());
        $this->assertSame(1, $bookmark->meta->a);
    }

    public function test_metadata_reads_with_json_where_clauses(): void
    {
        $user = User::create();
        $user->bookmark(Post::create(), ['priority' => 5, 'flags' => ['pinned' => true]]);
        $user->bookmark(Post::create(), ['priority' => 2]);

        $this->assertSame(1, $user->givenBookmarks()->where('meta->priority', 5)->count());
        $this->assertSame(1, $user->givenBookmarks()->where('meta->flags->pinned', true)->count());
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public function corruptRows(): array
    {
        return [
            'json scalar' => ['"text"', 'JSON string'],
            'json list' => ['[1]', 'JSON list'],
            'invalid json' => ['{', 'not valid JSON'],
        ];
    }

    /**
     * @dataProvider corruptRows
     */
    public function test_a_corrupt_stored_value_is_reported_not_masked(string $raw, string $message): void
    {
        $user = User::create();
        $post = Post::create();
        $user->bookmark($post);

        try {
            DB::table('bookmarks')->update(['meta' => $raw]);
        } catch (QueryException $exception) {
            $this->markTestSkipped('This database refuses to store it: '.$exception->getMessage());
        }

        $bookmark = $user->bookmarkOf($post);
        $this->assertInstanceOf(Bookmark::class, $bookmark);

        try {
            $bookmark->meta;
            $this->fail('Corrupt metadata was read.');
        } catch (UnexpectedValueException $exception) {
            $this->assertStringContainsString('in the [bookmarks] table', $exception->getMessage());
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        // Omitted metadata keeps the row as it is; new metadata replaces it.
        $this->assertSame($raw, DB::table('bookmarks')->value('meta'));
        $this->assertSame(['a' => 1], $user->bookmark($post, ['a' => 1])->meta->toArray());
    }

    public function test_a_null_stored_value_is_reported(): void
    {
        $bookmark = new Bookmark;
        $bookmark->setRawAttributes(['id' => 1, 'meta' => null], true);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('holds NULL, not a JSON object');

        $bookmark->meta;
    }

    public function test_metadata_left_out_of_the_select_reads_as_null(): void
    {
        $user = User::create();
        $post = Post::create();
        $user->bookmark($post, ['a' => 1]);

        $bookmark = Bookmark::query()->select('id')->firstOrFail();

        $this->assertNull($bookmark->meta);
        $this->assertFalse(isset($bookmark->meta));
        $this->assertArrayNotHasKey('meta', $bookmark->toArray());
    }
}
