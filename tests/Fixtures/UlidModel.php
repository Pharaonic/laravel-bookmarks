<?php

namespace Pharaonic\Laravel\Bookmarks\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Bookmarks\Concerns\CanBookmark;
use Pharaonic\Laravel\Bookmarks\Concerns\HasBookmarks;

/**
 * A ULID primary key, set on creation (HasUlids needs Laravel 9.40).
 */
class UlidModel extends Model
{
    use CanBookmark;
    use HasBookmarks;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            $model->setAttribute($model->getKeyName(), self::ulid());
        });
    }

    /**
     * 48 bits of milliseconds and 80 random bits, in Crockford's base32.
     */
    public static function ulid(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $time = (int) floor(microtime(true) * 1000);
        $ulid = '';

        for ($i = 0; $i < 10; $i++) {
            $ulid = $alphabet[$time % 32].$ulid;
            $time = intdiv($time, 32);
        }

        for ($i = 0; $i < 16; $i++) {
            $ulid .= $alphabet[random_int(0, 31)];
        }

        return $ulid;
    }
}
