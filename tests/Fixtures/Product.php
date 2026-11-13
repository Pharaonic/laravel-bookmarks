<?php

namespace Pharaonic\Laravel\Bookmarks\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Uses neither trait: a bookmarker can still bookmark it.
 */
class Product extends Model
{
    /**
     * @var array<int, string>
     */
    protected $guarded = [];
}
