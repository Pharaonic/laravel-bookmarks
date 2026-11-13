<?php

namespace Pharaonic\Laravel\Bookmarks\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Bookmarks\Concerns\CanBookmark;

/**
 * A bookmarker that is not a user.
 */
class Bot extends Model
{
    use CanBookmark;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];
}
