<?php

namespace Pharaonic\Laravel\Bookmarks\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pharaonic\Laravel\Bookmarks\Models\Bookmark;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Post;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\User;
use Pharaonic\Laravel\Bookmarks\Tests\TestCase;
use RuntimeException;

class MigrationTest extends TestCase
{
    private const MIGRATION = '2026_11_13_000001_create_pharaonic_bookmarks_table';

    public function test_migrate_creates_the_bookmarks_table(): void
    {
        $this->assertTrue(Schema::hasTable('bookmarks'));
        $this->assertTrue(Schema::hasColumns('bookmarks', [
            'id', 'bookmarker_type', 'bookmarker_id', 'bookmarkable_type', 'bookmarkable_id', 'meta', 'created_at', 'updated_at',
        ]));
        $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRATION)->count());
    }

    public function test_the_unique_index_refuses_a_second_row_for_a_pair(): void
    {
        $row = ['bookmarker_type' => 'user', 'bookmarker_id' => '1', 'bookmarkable_type' => 'post', 'bookmarkable_id' => '1', 'meta' => '{}'];
        DB::table('bookmarks')->insert($row);

        $this->expectException(QueryException::class);

        DB::table('bookmarks')->insert($row);
    }

    public function test_the_meta_column_refuses_null(): void
    {
        $this->expectException(QueryException::class);

        DB::table('bookmarks')->insert(['bookmarker_type' => 'user', 'bookmarker_id' => '1', 'bookmarkable_type' => 'post', 'bookmarkable_id' => '1', 'meta' => null]);
    }

    public function test_the_unique_index_allows_the_same_ids_with_other_types(): void
    {
        DB::table('bookmarks')->insert(['bookmarker_type' => 'user', 'bookmarker_id' => '1', 'bookmarkable_type' => 'post', 'bookmarkable_id' => '1', 'meta' => '{}']);
        DB::table('bookmarks')->insert(['bookmarker_type' => 'user', 'bookmarker_id' => '1', 'bookmarkable_type' => 'comment', 'bookmarkable_id' => '1', 'meta' => '{}']);
        DB::table('bookmarks')->insert(['bookmarker_type' => 'bot', 'bookmarker_id' => '1', 'bookmarkable_type' => 'post', 'bookmarkable_id' => '1', 'meta' => '{}']);

        $this->assertSame(3, DB::table('bookmarks')->count());
    }

    public function test_rollback_drops_the_table_and_migrate_creates_it_again(): void
    {
        $this->artisanCall('migrate:rollback');
        $this->assertFalse(Schema::hasTable('bookmarks'));

        $this->artisanCall('migrate');
        $this->assertTrue(Schema::hasTable('bookmarks'));
        $this->assertTrue(User::create()->bookmark(Post::create())->exists);
    }

    public function test_the_configured_table_name_is_used_by_the_migration_and_the_model(): void
    {
        $this->artisanCall('migrate:rollback');
        $this->app['config']->set('pharaonic.bookmarks.table', 'saved_posts');
        $this->artisanCall('migrate');

        $this->assertTrue(Schema::hasTable('saved_posts'));
        $this->assertFalse(Schema::hasTable('bookmarks'));
        $this->assertSame('saved_posts', (new Bookmark)->getTable());

        $user = User::create();
        $post = Post::create();
        $this->assertSame($user->bookmark($post)->getKey(), $user->bookmark($post)->getKey());
        $this->assertSame(1, DB::table('saved_posts')->count());
        $this->assertSame(1, Post::whereBookmarkedBy($user)->count());
        $this->assertSame(1, (int) Post::withCount('bookmarks')->firstOrFail()->getAttribute('bookmarks_count'));
    }

    public function test_an_existing_bookmarks_table_stops_the_migration_with_an_explanation(): void
    {
        $this->artisanCall('migrate:rollback');

        // A bookmarks table the application already had, without the unique index.
        Schema::create('bookmarks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('bookmarkable_id');
            $table->string('bookmarkable_type');
            $table->string('bookmarker_id');
            $table->string('bookmarker_type');
            $table->timestamps();
        });

        try {
            $this->artisanCall('migrate');
            $this->fail('The migration ran over an existing table.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('The [bookmarks] table already exists', $exception->getMessage());
            $this->assertStringContainsString('Set pharaonic.bookmarks.table to another name', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('migrations')->where('migration', self::MIGRATION)->count());
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function artisanCall(string $command, array $parameters = []): void
    {
        $this->assertSame(0, $this->app->make(Kernel::class)->call($command, $parameters));
    }
}
