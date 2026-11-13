<?php

namespace Pharaonic\Laravel\Bookmarks\Tests\Fixtures;

use Pharaonic\Laravel\Bookmarks\Models\Bookmark;

/**
 * A bookmark model on a connection of its own.
 */
class SecondaryBookmark extends Bookmark
{
    protected $connection = 'secondary';
}
