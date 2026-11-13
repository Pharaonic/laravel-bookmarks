<?php

namespace Pharaonic\Laravel\Bookmarks\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Pharaonic\Laravel\Bookmarks\Concerns\CanBookmark;

class SoftDeletableMember extends Model
{
    use CanBookmark;
    use SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];
}
