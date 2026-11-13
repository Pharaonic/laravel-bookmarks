<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pharaonic\Laravel\Bookmarks\Support\BookmarkManager;

/**
 * One row per bookmarker and bookmarkable pair. The ids are strings, so
 * integer, UUID and ULID keys can share the table, and the unique index is
 * what keeps a pair to one bookmark, even under concurrent requests.
 *
 * The table goes on the configured model's connection, with its table name.
 */
class CreatePharaonicBookmarksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $model = BookmarkManager::newModel();
        $name = $model->getTable();
        $schema = Schema::connection($model->getConnectionName());

        if ($schema->hasTable($name)) {
            throw new RuntimeException(sprintf(
                'The [%s] table already exists, so pharaonic/laravel-bookmarks cannot create it. '
                .'Set pharaonic.bookmarks.table to another name, or move the existing table out of the way.',
                $name
            ));
        }

        $schema->create($name, function (Blueprint $table) use ($name) {
            $table->bigIncrements('id');
            $table->string('bookmarker_type', 191);
            $table->string('bookmarker_id', 64);
            $table->string('bookmarkable_type', 191);
            $table->string('bookmarkable_id', 64);
            // Always a JSON object; the package writes {} rather than NULL.
            $table->json('meta');
            $table->timestamps();

            // Also serves "bookmarks of this model": its first two columns.
            $table->unique(['bookmarkable_type', 'bookmarkable_id', 'bookmarker_type', 'bookmarker_id'], $name.'_unique_pair');
            $table->index(['bookmarker_type', 'bookmarker_id'], $name.'_bookmarker_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $model = BookmarkManager::newModel();

        Schema::connection($model->getConnectionName())->dropIfExists($model->getTable());
    }
}
