<?php

namespace Pharaonic\Laravel\Bookmarks\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Pharaonic\Laravel\Bookmarks\Models\Bookmark;
use Pharaonic\Laravel\Bookmarks\Support\BookmarkManager;
use Pharaonic\Laravel\Bookmarks\ValueObjects\BookmarkMeta;
use stdClass;

/**
 * For a model that bookmarks other models, such as a user.
 */
trait CanBookmark
{
    /**
     * Delete the bookmarks the model kept when it is deleted (not soft deleted).
     */
    public static function bootCanBookmark(): void
    {
        static::deleted(function (Model $model) {
            BookmarkManager::cleanUp($model, 'bookmarker');
        });
    }

    /**
     * The bookmarks this model kept.
     *
     * @return MorphMany<Bookmark, $this>
     */
    public function givenBookmarks(): MorphMany
    {
        return BookmarkManager::relation($this, 'bookmarker');
    }

    /**
     * Bookmark the model and return the stored bookmark.
     *
     * Metadata replaces the whole metadata of an existing bookmark; null keeps
     * it, and [] empties it to {}. A new bookmark without metadata stores {}.
     *
     * @param  array<mixed>|stdClass|BookmarkMeta|null  $meta  a JSON object
     *
     * @throws \InvalidArgumentException when a model is unsaved or the metadata is not a JSON object
     */
    public function bookmark(Model $bookmarkable, array|stdClass|BookmarkMeta|null $meta = null): Bookmark
    {
        return BookmarkManager::bookmark($this, $bookmarkable, $meta);
    }

    /**
     * The bookmark this model kept of the model, or null.
     */
    public function bookmarkOf(Model $bookmarkable): ?Bookmark
    {
        return BookmarkManager::of($this, $bookmarkable);
    }

    /**
     * Whether this model bookmarked the model.
     */
    public function hasBookmarked(Model $bookmarkable): bool
    {
        return BookmarkManager::has($this, $bookmarkable);
    }

    /**
     * Remove the bookmark. True when this call removed it, false when there was none.
     */
    public function unbookmark(Model $bookmarkable): bool
    {
        return BookmarkManager::unbookmark($this, $bookmarkable);
    }

    /**
     * Bookmark the model with the metadata, or remove the bookmark when it
     * exists. Returns the new bookmark, or null when it was removed.
     *
     * @param  array<mixed>|stdClass|BookmarkMeta|null  $meta  the new bookmark's metadata; unused when removing
     */
    public function toggleBookmark(Model $bookmarkable, array|stdClass|BookmarkMeta|null $meta = null): ?Bookmark
    {
        return BookmarkManager::toggle($this, $bookmarkable, $meta);
    }
}
