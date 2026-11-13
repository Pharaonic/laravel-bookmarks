<?php

namespace Pharaonic\Laravel\Bookmarks\ValueObjects;

use JsonSerializable;
use LogicException;
use Pharaonic\Laravel\Bookmarks\Support\MetaNormalizer;
use stdClass;

/**
 * The read-only metadata of a bookmark: always a JSON object.
 *
 * Keys read as properties ($meta->priority) or through get(); a nested object
 * is a BookmarkMeta too, and a list is a PHP array whose objects are BookmarkMeta.
 * Nothing it returns can change it: write new metadata through bookmark() or
 * by assigning the bookmark's meta attribute.
 */
final class BookmarkMeta implements JsonSerializable
{
    private stdClass $data;

    /**
     * @param  array<mixed>|stdClass  $data  a JSON object: an array with string keys, or a stdClass
     *
     * @throws \InvalidArgumentException when the data is not a JSON object
     */
    public function __construct(array|stdClass $data = [])
    {
        $this->data = MetaNormalizer::normalize($data);
    }

    /**
     * The value of the key, or null when it is absent.
     */
    public function __get(string $key): mixed
    {
        return $this->get($key);
    }

    /**
     * Whether the key is present and not null, as isset() means.
     */
    public function __isset(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * @throws LogicException always: the metadata is read-only
     */
    public function __set(string $key, mixed $value): void
    {
        throw new LogicException(sprintf(
            'Bookmark metadata is read-only, so [%s] cannot be set. Pass the whole new metadata to bookmark(), or assign the bookmark\'s meta attribute.',
            $key
        ));
    }

    /**
     * @throws LogicException always: the metadata is read-only
     */
    public function __unset(string $key): void
    {
        throw new LogicException(sprintf(
            'Bookmark metadata is read-only, so [%s] cannot be unset. Pass the whole new metadata to bookmark(), or assign the bookmark\'s meta attribute.',
            $key
        ));
    }

    /**
     * The value of the key, or the default when the key is absent. A key that
     * is present with a null value returns null, not the default.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (! $this->has($key)) {
            return $default;
        }

        return self::wrap($this->data->{$key});
    }

    /**
     * Whether the key is present, even with a null value.
     */
    public function has(string $key): bool
    {
        return property_exists($this->data, $key);
    }

    /**
     * Whether the metadata is the empty object {}.
     */
    public function isEmpty(): bool
    {
        return get_object_vars($this->data) === [];
    }

    /**
     * The metadata as nested PHP arrays.
     *
     * PHP arrays cannot tell an empty object from an empty list: both {} and []
     * become [], and PHP turns a numeric key such as "1" into the integer 1.
     * Use toObject() or the BookmarkMeta itself to keep the JSON shape.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        $array = self::plain($this->data);

        return is_array($array) ? $array : [];
    }

    /**
     * A detached copy as stdClass objects and PHP lists, which keeps {} and [] apart.
     */
    public function toObject(): stdClass
    {
        $copy = MetaNormalizer::copy($this->data);

        return $copy instanceof stdClass ? $copy : new stdClass;
    }

    /**
     * The metadata as json_encode() writes it: always a JSON object.
     */
    public function jsonSerialize(): stdClass
    {
        return $this->toObject();
    }

    /**
     * Wrap nested objects so a returned value cannot reach the stored tree.
     */
    private static function wrap(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            return new self($value);
        }

        if (is_array($value)) {
            return array_map([self::class, 'wrap'], $value);
        }

        return $value;
    }

    /**
     * Objects as associative arrays, at every depth.
     */
    private static function plain(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            return array_map([self::class, 'plain'], $value);
        }

        return $value;
    }
}
