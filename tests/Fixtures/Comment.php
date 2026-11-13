<?php

namespace Pharaonic\Laravel\Bookmarks\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Bookmarks\Concerns\HasBookmarks;

class Comment extends Model
{
    use HasBookmarks;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];
}
