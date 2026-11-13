<?php

namespace Pharaonic\Laravel\Bookmarks\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Pharaonic\Laravel\Bookmarks\Concerns\CanBookmark;
use Pharaonic\Laravel\Bookmarks\Concerns\HasBookmarks;

/**
 * Bookmarks other models and is bookmarked too.
 */
class User extends Authenticatable
{
    use CanBookmark;
    use HasBookmarks;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];
}
