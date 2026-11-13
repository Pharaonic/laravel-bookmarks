<?php

namespace Pharaonic\Laravel\Bookmarks\Support;

use InvalidArgumentException;
use JsonException;
use Pharaonic\Laravel\Bookmarks\ValueObjects\BookmarkMeta;
use stdClass;
use UnexpectedValueException;

/**
 * Turns bookmark metadata into one JSON-object tree, writes it as canonical
 * JSON, reads it back, and compares two trees.
 *
 * In a tree, a JSON object is a stdClass and a JSON list is a PHP list, so
 * {} and [] stay distinct at every depth. Strings, integers, floats, booleans
 * and null keep their JSON type: 1, 1.0, "1" and true are four different values.
 *
 * @internal
 */
final class MetaNormalizer
{
    /**
     * The deepest nesting accepted, the same as json_encode() and json_decode().
     */
    public const DEPTH = 512;

    private const ENCODE_FLAGS = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * The metadata as a detached tree whose root is an object.
     *
     * An array is a JSON object unless it is a non-empty list; the empty array
     * is the empty object at the root and the empty list below it. Non-list
     * arrays keep their keys as strings ([1 => 'a'] is {"1":"a"}).
     *
     * @throws InvalidArgumentException when the value is not a JSON object, or holds something JSON cannot store
     */
    public static function normalize(mixed $meta): stdClass
    {
        if ($meta instanceof BookmarkMeta) {
            return $meta->toObject();
        }

        if (is_array($meta) && $meta !== [] && self::isList($meta)) {
            throw new InvalidArgumentException(
                'Bookmark metadata must be a JSON object, not a list: pass an array with string keys, a stdClass, or a BookmarkMeta.'
            );
        }

        if (! is_array($meta) && ! $meta instanceof stdClass) {
            throw new InvalidArgumentException(sprintf(
                'Bookmark metadata must be a JSON object: pass an array with string keys, a stdClass, or a BookmarkMeta, not %s.',
                get_debug_type($meta)
            ));
        }

        $stack = [];

        return self::object($meta, 'meta', 1, $stack);
    }

    /**
     * The tree as canonical JSON: object keys sorted at every depth, list order
     * kept, floats written with a fraction so they read back as floats.
     */
    public static function encode(stdClass $tree): string
    {
        return json_encode(self::sorted($tree), self::ENCODE_FLAGS);
    }

    /**
     * The tree a stored value holds.
     *
     * @throws UnexpectedValueException when the stored value is not a JSON object
     */
    public static function decode(mixed $raw): stdClass
    {
        if (! is_string($raw)) {
            throw new UnexpectedValueException(sprintf(
                'The stored bookmark metadata is %s, not a JSON object.', $raw === null ? 'NULL' : get_debug_type($raw)
            ));
        }

        try {
            $tree = json_decode($raw, false, self::DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException(
                'The stored bookmark metadata is not valid JSON: '.$exception->getMessage().'.', 0, $exception
            );
        }

        if (! $tree instanceof stdClass) {
            throw new UnexpectedValueException(sprintf(
                'The stored bookmark metadata is a JSON %s, not a JSON object.', is_array($tree) ? 'list' : get_debug_type($tree)
            ));
        }

        return $tree;
    }

    /**
     * Whether two trees hold the same JSON: key order does not matter, list
     * order and value types do.
     */
    public static function equals(stdClass $a, stdClass $b): bool
    {
        return self::encode($a) === self::encode($b);
    }

    /**
     * A detached copy of a tree, so a caller can change it without changing ours.
     */
    public static function copy(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $copy = new stdClass;

            foreach (get_object_vars($value) as $key => $item) {
                $copy->{(string) $key} = self::copy($item);
            }

            return $copy;
        }

        if (is_array($value)) {
            return array_map([self::class, 'copy'], $value);
        }

        return $value;
    }

    /**
     * @param  array<mixed>|stdClass  $value
     * @param  array<int, true>  $stack  the objects being normalized, to catch cycles
     */
    private static function object(array|stdClass $value, string $path, int $depth, array &$stack): stdClass
    {
        $object = new stdClass;

        if ($value instanceof stdClass) {
            $id = spl_object_id($value);

            if (isset($stack[$id])) {
                throw new InvalidArgumentException(sprintf('Bookmark metadata cannot contain itself (at %s).', $path));
            }

            $stack[$id] = true;
        }

        $pairs = $value instanceof stdClass ? get_object_vars($value) : $value;

        foreach ($pairs as $key => $item) {
            $key = (string) $key;

            if (str_starts_with($key, "\0")) {
                throw new InvalidArgumentException(sprintf(
                    'Bookmark metadata keys cannot start with a NUL byte (at %s).', $path
                ));
            }

            self::assertUtf8($key, $path);

            $object->{$key} = self::value($item, $path.'.'.$key, $depth + 1, $stack);
        }

        if ($value instanceof stdClass) {
            unset($stack[spl_object_id($value)]);
        }

        return $object;
    }

    /**
     * @param  array<int, true>  $stack
     */
    private static function value(mixed $value, string $path, int $depth, array &$stack): mixed
    {
        if ($depth > self::DEPTH) {
            throw new InvalidArgumentException(sprintf(
                'Bookmark metadata is nested deeper than %d levels (at %s).', self::DEPTH, $path
            ));
        }

        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException(sprintf('JSON cannot store NAN or INF (at %s).', $path));
            }

            return $value;
        }

        if (is_string($value)) {
            self::assertUtf8($value, $path);

            return $value;
        }

        if ($value instanceof BookmarkMeta) {
            return $value->toObject();
        }

        if ($value instanceof stdClass) {
            return self::object($value, $path, $depth, $stack);
        }

        if (is_array($value)) {
            if (! self::isList($value)) {
                return self::object($value, $path, $depth, $stack);
            }

            $list = [];

            foreach ($value as $index => $item) {
                $list[] = self::value($item, $path.'['.$index.']', $depth + 1, $stack);
            }

            return $list;
        }

        throw new InvalidArgumentException(sprintf(
            'Bookmark metadata can only hold JSON values: strings, numbers, booleans, null, lists, and objects '
            .'(arrays with string keys, stdClass, or BookmarkMeta), not %s (at %s).',
            get_debug_type($value), $path
        ));
    }

    private static function assertUtf8(string $value, string $path): void
    {
        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('Bookmark metadata must be valid UTF-8 (at %s).', $path));
        }
    }

    /**
     * Whether the array's keys are 0, 1, 2… in order (array_is_list() needs PHP 8.1).
     *
     * @param  array<mixed>  $array
     */
    private static function isList(array $array): bool
    {
        $expected = 0;

        foreach ($array as $key => $_) {
            if ($key !== $expected++) {
                return false;
            }
        }

        return true;
    }

    private static function sorted(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $pairs = get_object_vars($value);
            ksort($pairs, SORT_STRING);
            $sorted = new stdClass;

            foreach ($pairs as $key => $item) {
                $sorted->{(string) $key} = self::sorted($item);
            }

            return $sorted;
        }

        if (is_array($value)) {
            return array_map([self::class, 'sorted'], $value);
        }

        return $value;
    }
}
