<?php

namespace Pharaonic\Laravel\Bookmarks\Events;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Bookmarks\Models\Bookmark;

/**
 * Dispatched once for each bookmark this package inserts, right after the insert.
 */
final class BookmarkCreated
{
    public function __construct(
        public Bookmark $bookmark,
        public Model $bookmarker,
        public Model $bookmarkable,
    ) {}
}
