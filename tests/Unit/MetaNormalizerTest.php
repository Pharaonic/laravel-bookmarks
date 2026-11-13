<?php

namespace Pharaonic\Laravel\Bookmarks\Tests\Unit;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Pharaonic\Laravel\Bookmarks\Support\MetaNormalizer;
use Pharaonic\Laravel\Bookmarks\ValueObjects\BookmarkMeta;
use PHPUnit\Framework\TestCase;
use stdClass;
use UnexpectedValueException;

class MetaNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public function comparisons(): array
    {
        return [
            'key order' => ['{"a":1,"b":2}', '{"b":2,"a":1}', true],
            'integer and string' => ['{"a":1}', '{"a":"1"}', false],
            'boolean and integer' => ['{"a":true}', '{"a":1}', false],
            'null and missing' => ['{"a":null}', '{}', false],
            'list order' => ['{"a":[1,2]}', '{"a":[2,1]}', false],
            'nested key order' => ['{"a":{"x":1,"y":2}}', '{"a":{"y":2,"x":1}}', true],
            'empty list and empty object' => ['{"a":[]}', '{"a":{}}', false],
            'empty objects' => ['{}', '{}', true],
            'integer and float' => ['{"a":1}', '{"a":1.0}', false],
            'false and zero' => ['{"a":false}', '{"a":0}', false],
            'objects in lists' => ['{"a":[{"x":1,"y":2}]}', '{"a":[{"y":2,"x":1}]}', true],
            'unicode' => ['{"a":"é"}', '{"a":"é"}', true],
        ];
    }

    /**
     * @dataProvider comparisons
     */
    public function test_comparison_follows_json_semantics(string $a, string $b, bool $equal): void
    {
        $this->assertSame($equal, MetaNormalizer::equals(MetaNormalizer::decode($a), MetaNormalizer::decode($b)));
    }

    public function test_the_empty_array_is_the_empty_object_at_the_root(): void
    {
        $this->assertSame('{}', MetaNormalizer::encode(MetaNormalizer::normalize([])));
    }

    public function test_arrays_below_the_root_keep_their_shape(): void
    {
        $tree = MetaNormalizer::normalize([
            'list' => [1, 'two', 3.5, true, null],
            'empty' => [],
            'object' => new stdClass,
            'nested' => ['a' => ['b' => ['c' => 1]]],
            'numbered' => [1 => 'a', 2 => 'b'],
        ]);

        $this->assertSame(
            '{"empty":[],"list":[1,"two",3.5,true,null],"nested":{"a":{"b":{"c":1}}},"numbered":{"1":"a","2":"b"},"object":{}}',
            MetaNormalizer::encode($tree)
        );
    }

    public function test_encoding_sorts_keys_and_keeps_float_types(): void
    {
        $tree = MetaNormalizer::normalize(['b' => 1.0, 'a' => 1, 'c' => ['z' => 0.1, 'y' => '/path']]);

        $this->assertSame('{"a":1,"b":1.0,"c":{"y":"/path","z":0.1}}', MetaNormalizer::encode($tree));
        $this->assertIsFloat(MetaNormalizer::decode(MetaNormalizer::encode($tree))->b);
    }

    public function test_large_integers_and_precise_floats_survive_a_round_trip(): void
    {
        $tree = MetaNormalizer::normalize(['max' => PHP_INT_MAX, 'min' => PHP_INT_MIN, 'pi' => M_PI, 'tiny' => 1.0E-300]);
        $decoded = MetaNormalizer::decode(MetaNormalizer::encode($tree));

        $this->assertSame(PHP_INT_MAX, $decoded->max);
        $this->assertSame(PHP_INT_MIN, $decoded->min);
        $this->assertSame(M_PI, $decoded->pi);
        $this->assertSame(1.0E-300, $decoded->tiny);
    }

    public function test_a_stdclass_and_a_bookmark_meta_are_accepted(): void
    {
        $object = new stdClass;
        $object->a = new stdClass;

        $this->assertSame('{"a":{}}', MetaNormalizer::encode(MetaNormalizer::normalize($object)));
        $this->assertSame('{"a":{}}', MetaNormalizer::encode(MetaNormalizer::normalize(new BookmarkMeta($object))));
        $this->assertSame('{"m":{"a":{}}}', MetaNormalizer::encode(MetaNormalizer::normalize(['m' => new BookmarkMeta($object)])));
    }

    public function test_normalizing_detaches_the_tree_from_the_input(): void
    {
        $object = new stdClass;
        $object->a = new stdClass;
        $tree = MetaNormalizer::normalize($object);

        $object->a->b = 1;

        $this->assertSame('{"a":{}}', MetaNormalizer::encode($tree));
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public function invalidRoots(): array
    {
        return [
            'list' => [['a', 'b'], 'not a list'],
            'integer list' => [[1, 2], 'not a list'],
            'string' => ['{"a":1}', 'not string'],
            'integer' => [1, 'not int'],
            'float' => [1.5, 'not float'],
            'boolean' => [true, 'not bool'],
            'collection' => [new Collection(['a' => 1]), 'not Illuminate\Support\Collection'],
            'closure' => [function () {
                return 1;
            }, 'not Closure'],
        ];
    }

    /**
     * @dataProvider invalidRoots
     */
    public function test_a_root_that_is_not_a_json_object_is_refused(mixed $meta, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        MetaNormalizer::normalize($meta);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public function invalidValues(): array
    {
        $resource = fopen('php://memory', 'r');

        return [
            'nan' => [['a' => NAN], 'NAN or INF (at meta.a)'],
            'infinity' => [['a' => ['b' => [INF]]], 'NAN or INF (at meta.a.b[0])'],
            'invalid utf-8 value' => [['a' => "\xB1\x31"], 'valid UTF-8 (at meta.a)'],
            'invalid utf-8 key' => [["\xB1\x31" => 1], 'valid UTF-8 (at meta)'],
            'nul key' => [['a' => (object) ["\0" => 1]], 'NUL byte (at meta.a)'],
            'object' => [['a' => new \DateTime], 'not DateTime (at meta.a)'],
            'collection' => [['a' => new Collection], 'not Illuminate\Support\Collection (at meta.a)'],
            'resource' => [['a' => $resource], 'not resource (stream) (at meta.a)'],
        ];
    }

    /**
     * @dataProvider invalidValues
     *
     * @param  array<string, mixed>  $meta
     */
    public function test_a_value_json_cannot_store_is_refused_with_its_path(array $meta, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        MetaNormalizer::normalize($meta);
    }

    public function test_a_cycle_is_refused(): void
    {
        $object = new stdClass;
        $object->self = $object;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot contain itself (at meta.self)');

        MetaNormalizer::normalize($object);
    }

    public function test_an_object_used_twice_without_a_cycle_is_accepted(): void
    {
        $shared = (object) ['x' => 1];

        $this->assertSame('{"a":{"x":1},"b":{"x":1}}', MetaNormalizer::encode(MetaNormalizer::normalize(['a' => $shared, 'b' => $shared])));
    }

    public function test_nesting_deeper_than_json_allows_is_refused(): void
    {
        $meta = [];

        for ($i = 0; $i < MetaNormalizer::DEPTH; $i++) {
            $meta = ['a' => $meta ?: 1];
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nested deeper than 512 levels');

        MetaNormalizer::normalize($meta);
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public function corruptValues(): array
    {
        return [
            'null' => [null, 'is NULL, not a JSON object'],
            'integer' => [1, 'is int, not a JSON object'],
            'invalid json' => ['{"a":', 'not valid JSON'],
            'json scalar' => ['"text"', 'is a JSON string, not a JSON object'],
            'json number' => ['1', 'is a JSON int, not a JSON object'],
            'json null' => ['null', 'is a JSON null, not a JSON object'],
            'json list' => ['[1,2]', 'is a JSON list, not a JSON object'],
            'empty string' => ['', 'not valid JSON'],
        ];
    }

    /**
     * @dataProvider corruptValues
     */
    public function test_a_stored_value_that_is_not_a_json_object_is_refused(mixed $raw, string $message): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        MetaNormalizer::decode($raw);
    }
}
