<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pharaonic\Laravel\Bookmarks\Models\Bookmark;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Post;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\User;

test('migrate creates the bookmarks table', function () {
    $this->assertTrue(Schema::hasTable('bookmarks'));
    $this->assertTrue(Schema::hasColumns('bookmarks', [
        'id', 'bookmarker_type', 'bookmarker_id', 'bookmarkable_type', 'bookmarkable_id', 'meta', 'created_at', 'updated_at',
    ]));
    $this->assertSame(1, DB::table('migrations')->where('migration', BOOKMARKS_MIGRATION)->count());
});

test('the unique index refuses a second row for a pair', function () {
    $row = ['bookmarker_type' => 'user', 'bookmarker_id' => '1', 'bookmarkable_type' => 'post', 'bookmarkable_id' => '1', 'meta' => '{}'];
    DB::table('bookmarks')->insert($row);

    $this->expectException(QueryException::class);

    DB::table('bookmarks')->insert($row);
});

test('the meta column refuses null', function () {
    $this->expectException(QueryException::class);

    DB::table('bookmarks')->insert(['bookmarker_type' => 'user', 'bookmarker_id' => '1', 'bookmarkable_type' => 'post', 'bookmarkable_id' => '1', 'meta' => null]);
});

test('the unique index allows the same ids with other types', function () {
    DB::table('bookmarks')->insert(['bookmarker_type' => 'user', 'bookmarker_id' => '1', 'bookmarkable_type' => 'post', 'bookmarkable_id' => '1', 'meta' => '{}']);
    DB::table('bookmarks')->insert(['bookmarker_type' => 'user', 'bookmarker_id' => '1', 'bookmarkable_type' => 'comment', 'bookmarkable_id' => '1', 'meta' => '{}']);
    DB::table('bookmarks')->insert(['bookmarker_type' => 'bot', 'bookmarker_id' => '1', 'bookmarkable_type' => 'post', 'bookmarkable_id' => '1', 'meta' => '{}']);

    $this->assertSame(3, DB::table('bookmarks')->count());
});

test('rollback drops the table and migrate creates it again', function () {
    runArtisan('migrate:rollback');
    $this->assertFalse(Schema::hasTable('bookmarks'));

    runArtisan('migrate');
    $this->assertTrue(Schema::hasTable('bookmarks'));
    $this->assertTrue(User::create()->bookmark(Post::create())->exists);
});

test('the configured table name is used by the migration and the model', function () {
    runArtisan('migrate:rollback');
    $this->app['config']->set('pharaonic.bookmarks.table', 'saved_posts');
    runArtisan('migrate');

    $this->assertTrue(Schema::hasTable('saved_posts'));
    $this->assertFalse(Schema::hasTable('bookmarks'));
    $this->assertSame('saved_posts', (new Bookmark)->getTable());

    $user = User::create();
    $post = Post::create();
    $this->assertSame($user->bookmark($post)->getKey(), $user->bookmark($post)->getKey());
    $this->assertSame(1, DB::table('saved_posts')->count());
    $this->assertSame(1, Post::whereBookmarkedBy($user)->count());
    $this->assertSame(1, (int) Post::withCount('bookmarks')->firstOrFail()->getAttribute('bookmarks_count'));
});

test('an existing bookmarks table stops the migration with an explanation', function () {
    runArtisan('migrate:rollback');

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
        runArtisan('migrate');
        $this->fail('The migration ran over an existing table.');
    } catch (RuntimeException $exception) {
        $this->assertStringContainsString('The [bookmarks] table already exists', $exception->getMessage());
        $this->assertStringContainsString('Set pharaonic.bookmarks.table to another name', $exception->getMessage());
    }

    $this->assertSame(0, DB::table('migrations')->where('migration', BOOKMARKS_MIGRATION)->count());
});
