<?php

namespace Pharaonic\Laravel\Bookmarks\Tests\Fixtures;

use Pharaonic\Laravel\Bookmarks\Models\Bookmark;

/**
 * An application's own bookmark model, on its own table.
 */
class CustomBookmark extends Bookmark
{
    protected $table = 'saved_items';

    public function isPinned(): bool
    {
        return $this->meta->get('pinned') === true;
    }
}
