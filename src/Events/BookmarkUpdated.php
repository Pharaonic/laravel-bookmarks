<?php

namespace Pharaonic\Laravel\Bookmarks\Events;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Bookmarks\Models\Bookmark;

/**
 * Dispatched once each time this package replaces the metadata of a bookmark with
 * different metadata, right after the update.
 */
final class BookmarkUpdated
{
    public function __construct(
        public Bookmark $bookmark,
        public Model $bookmarker,
        public Model $bookmarkable,
    ) {}
}
