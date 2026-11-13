<?php

namespace Pharaonic\Laravel\Bookmarks\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Pharaonic\Laravel\Bookmarks\Concerns\HasBookmarks;

class SoftDeletablePost extends Model
{
    use HasBookmarks;
    use SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];
}
