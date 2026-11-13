<?php

namespace Pharaonic\Laravel\Bookmarks\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Pharaonic\Laravel\Bookmarks\Support\BookmarkManager;
use Pharaonic\Laravel\Bookmarks\Support\MetaNormalizer;
use Pharaonic\Laravel\Bookmarks\ValueObjects\BookmarkMeta;
use UnexpectedValueException;

/**
 * A bookmark a bookmarker model keeps of a bookmarkable model, with JSON-object metadata.
 *
 * @property int $id
 * @property string $bookmarker_type
 * @property string $bookmarker_id
 * @property string $bookmarkable_type
 * @property string $bookmarkable_id
 * @property BookmarkMeta $meta
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Bookmark extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'bookmarker_type',
        'bookmarker_id',
        'bookmarkable_type',
        'bookmarkable_id',
    ];

    /**
     * A new bookmark starts with the empty object.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'meta' => '{}',
    ];

    /**
     * The decoded metadata, kept for the raw value it was decoded from.
     *
     * @var array{0: string, 1: BookmarkMeta}|null
     */
    private ?array $decodedMeta = null;

    /**
     * The table from the pharaonic.bookmarks.table setting, unless a subclass sets $table.
     *
     * @return string
     */
    public function getTable()
    {
        if ($this->table) {
            return $this->table;
        }

        $table = Config::get('pharaonic.bookmarks.table', 'bookmarks');

        return is_string($table) && $table !== '' ? $table : 'bookmarks';
    }

    /**
     * The model that keeps the bookmark.
     *
     * @return MorphTo<Model, $this>
     */
    public function bookmarker(): MorphTo
    {
        return $this->morphTo('bookmarker', 'bookmarker_type', 'bookmarker_id');
    }

    /**
     * The model that is bookmarked.
     *
     * @return MorphTo<Model, $this>
     */
    public function bookmarkable(): MorphTo
    {
        return $this->morphTo('bookmarkable', 'bookmarkable_type', 'bookmarkable_id');
    }

    /**
     * Only the bookmarks kept by the bookmarker.
     *
     * @param  Builder<Bookmark>  $query
     */
    public function scopeWhereBookmarker(Builder $query, Model $bookmarker): void
    {
        BookmarkManager::constrain($query, 'bookmarker', $bookmarker);
    }

    /**
     * Only the bookmarks of the bookmarkable.
     *
     * @param  Builder<Bookmark>  $query
     */
    public function scopeWhereBookmarkable(Builder $query, Model $bookmarkable): void
    {
        BookmarkManager::constrain($query, 'bookmarkable', $bookmarkable);
    }

    /**
     * The metadata, or null when the query did not select the meta column.
     *
     * @throws UnexpectedValueException when the stored value is not a JSON object
     */
    public function getMetaAttribute(mixed $value): ?BookmarkMeta
    {
        if (! array_key_exists('meta', $this->attributes)) {
            return null;
        }

        if (! is_string($value)) {
            throw $this->corrupt(null, $value);
        }

        if ($this->decodedMeta === null || $this->decodedMeta[0] !== $value) {
            try {
                $meta = new BookmarkMeta(MetaNormalizer::decode($value));
            } catch (UnexpectedValueException $exception) {
                throw $this->corrupt($exception, $value);
            }

            $this->decodedMeta = [$value, $meta];
        }

        return $this->decodedMeta[1];
    }

    /**
     * Replace the metadata with a JSON object.
     *
     * Metadata equal to what the row holds keeps the stored value as it is, so
     * the attribute stays clean and saving it runs no UPDATE.
     *
     * @param  mixed  $value  an array with string keys, a stdClass, or a BookmarkMeta
     *
     * @throws InvalidArgumentException when the value is not a JSON object
     */
    public function setMetaAttribute(mixed $value): void
    {
        if ($value === null) {
            throw new InvalidArgumentException(
                'Bookmark metadata cannot be null: assign [] to store the empty object {}.'
            );
        }

        $encoded = MetaNormalizer::encode(MetaNormalizer::normalize($value));
        $original = $this->original['meta'] ?? null;

        if (is_string($original) && $original !== $encoded && self::encodes($original, $encoded)) {
            $encoded = $original;
        }

        $this->attributes['meta'] = $encoded;
    }

    /**
     * Whether the stored value holds the same JSON as the canonical encoding.
     */
    private static function encodes(string $stored, string $encoded): bool
    {
        try {
            return MetaNormalizer::encode(MetaNormalizer::decode($stored)) === $encoded;
        } catch (UnexpectedValueException) {
            return false;
        }
    }

    private function corrupt(?UnexpectedValueException $previous, mixed $value): UnexpectedValueException
    {
        return new UnexpectedValueException(sprintf(
            'The meta column of bookmark [%s] in the [%s] table holds %s, not a JSON object. '
            .'Repair the row, or assign new metadata to replace it.',
            is_scalar($this->getKey()) ? (string) $this->getKey() : '?',
            $this->getTable(),
            $previous ? 'an invalid value ('.$previous->getMessage().')' : ($value === null ? 'NULL' : get_debug_type($value))
        ), 0, $previous);
    }
}
