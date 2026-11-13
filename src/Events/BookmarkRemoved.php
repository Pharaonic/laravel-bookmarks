<?php

namespace Pharaonic\Laravel\Bookmarks\Events;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Bookmarks\Models\Bookmark;

/**
 * Dispatched once for each bookmark this package deletes through unbookmark(),
 * unbookmarkBy() or toggleBookmark(), right after the delete.
 */
final class BookmarkRemoved
{
    public function __construct(
        public Bookmark $bookmark,
        public Model $bookmarker,
        public Model $bookmarkable,
    ) {}
}
