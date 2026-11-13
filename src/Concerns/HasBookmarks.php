<?php

namespace Pharaonic\Laravel\Bookmarks\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Pharaonic\Laravel\Bookmarks\Models\Bookmark;
use Pharaonic\Laravel\Bookmarks\Support\BookmarkManager;
use Pharaonic\Laravel\Bookmarks\ValueObjects\BookmarkMeta;
use stdClass;

/**
 * For a model that other models bookmark, such as a post.
 */
trait HasBookmarks
{
    /**
     * Delete the bookmarks the model received when it is deleted (not soft deleted).
     */
    public static function bootHasBookmarks(): void
    {
        static::deleted(function (Model $model) {
            BookmarkManager::cleanUp($model, 'bookmarkable');
        });
    }

    /**
     * The bookmarks this model received.
     */
    public function bookmarks(): MorphMany
    {
        return BookmarkManager::relation($this, 'bookmarkable');
    }

    /**
     * Whether the bookmarker bookmarked this model.
     */
    public function bookmarkedBy(Model $bookmarker): bool
    {
        return BookmarkManager::has($bookmarker, $this);
    }

    /**
     * Bookmark this model as the bookmarker, the same as $bookmarker->bookmark($this, $meta).
     *
     * @param  array<mixed>|stdClass|BookmarkMeta|null  $meta  a JSON object
     *
     * @throws \InvalidArgumentException when a model is unsaved or the metadata is not a JSON object
     */
    public function bookmarkBy(Model $bookmarker, array|stdClass|BookmarkMeta|null $meta = null): Bookmark
    {
        return BookmarkManager::bookmark($bookmarker, $this, $meta);
    }

    /**
     * Remove the bookmarker's bookmark. True when this call removed it.
     */
    public function unbookmarkBy(Model $bookmarker): bool
    {
        return BookmarkManager::unbookmark($bookmarker, $this);
    }

    /**
     * Only the models the bookmarker bookmarked.
     */
    public function scopeWhereBookmarkedBy(Builder $query, Model $bookmarker): void
    {
        $query->whereHas('bookmarks', function (Builder $bookmarks) use ($bookmarker) {
            BookmarkManager::constrain($bookmarks, 'bookmarker', $bookmarker);
        });
    }

    /**
     * Only the models the bookmarker did not bookmark.
     */
    public function scopeWhereNotBookmarkedBy(Builder $query, Model $bookmarker): void
    {
        $query->whereDoesntHave('bookmarks', function (Builder $bookmarks) use ($bookmarker) {
            BookmarkManager::constrain($bookmarks, 'bookmarker', $bookmarker);
        });
    }

    /**
     * Add an is_bookmarked column: whether the bookmarker bookmarked each model.
     * The query keeps its own columns, or selects every column of the table.
     */
    public function scopeWithBookmarkedBy(Builder $query, Model $bookmarker): void
    {
        BookmarkManager::selectBookmarkedBy($query, $this, $bookmarker);
    }
}
