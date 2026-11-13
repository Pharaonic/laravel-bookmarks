<?php

use Illuminate\Support\Collection;
use Pharaonic\Laravel\Bookmarks\Support\MetaNormalizer;
use Pharaonic\Laravel\Bookmarks\ValueObjects\BookmarkMeta;

test('comparison follows json semantics', function (string $a, string $b, bool $equal) {
    $this->assertSame($equal, MetaNormalizer::equals(MetaNormalizer::decode($a), MetaNormalizer::decode($b)));
})->with([
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
]);

test('the empty array is the empty object at the root', function () {
    $this->assertSame('{}', MetaNormalizer::encode(MetaNormalizer::normalize([])));
});

test('arrays below the root keep their shape', function () {
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
});

test('encoding sorts keys and keeps float types', function () {
    $tree = MetaNormalizer::normalize(['b' => 1.0, 'a' => 1, 'c' => ['z' => 0.1, 'y' => '/path']]);

    $this->assertSame('{"a":1,"b":1.0,"c":{"y":"/path","z":0.1}}', MetaNormalizer::encode($tree));
    $this->assertIsFloat(MetaNormalizer::decode(MetaNormalizer::encode($tree))->b);
});

test('large integers and precise floats survive a round trip', function () {
    $tree = MetaNormalizer::normalize(['max' => PHP_INT_MAX, 'min' => PHP_INT_MIN, 'pi' => M_PI, 'tiny' => 1.0E-300]);
    $decoded = MetaNormalizer::decode(MetaNormalizer::encode($tree));

    $this->assertSame(PHP_INT_MAX, $decoded->max);
    $this->assertSame(PHP_INT_MIN, $decoded->min);
    $this->assertSame(M_PI, $decoded->pi);
    $this->assertSame(1.0E-300, $decoded->tiny);
});

test('a stdclass and a bookmark meta are accepted', function () {
    $object = new stdClass;
    $object->a = new stdClass;

    $this->assertSame('{"a":{}}', MetaNormalizer::encode(MetaNormalizer::normalize($object)));
    $this->assertSame('{"a":{}}', MetaNormalizer::encode(MetaNormalizer::normalize(new BookmarkMeta($object))));
    $this->assertSame('{"m":{"a":{}}}', MetaNormalizer::encode(MetaNormalizer::normalize(['m' => new BookmarkMeta($object)])));
});

test('normalizing detaches the tree from the input', function () {
    $object = new stdClass;
    $object->a = new stdClass;
    $tree = MetaNormalizer::normalize($object);

    $object->a->b = 1;

    $this->assertSame('{"a":{}}', MetaNormalizer::encode($tree));
});

test('a root that is not a json object is refused', function (mixed $meta, string $message) {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage($message);

    MetaNormalizer::normalize($meta);
})->with([
    'list' => [['a', 'b'], 'not a list'],
    'integer list' => [[1, 2], 'not a list'],
    'string' => ['{"a":1}', 'not string'],
    'integer' => [1, 'not int'],
    'float' => [1.5, 'not float'],
    'boolean' => [true, 'not bool'],
    'collection' => [new Collection(['a' => 1]), 'not Illuminate\Support\Collection'],
]);

// Pest calls a closure it finds in a dataset, so this one has a test of its own.
test('a closure root is refused', function () {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('not Closure');

    MetaNormalizer::normalize(function () {
        return 1;
    });
});

test('a value json cannot store is refused with its path', function (array $meta, string $message) {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage($message);

    MetaNormalizer::normalize($meta);
})->with(function () {
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
});

test('a cycle is refused', function () {
    $object = new stdClass;
    $object->self = $object;

    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('cannot contain itself (at meta.self)');

    MetaNormalizer::normalize($object);
});

test('an object used twice without a cycle is accepted', function () {
    $shared = (object) ['x' => 1];

    $this->assertSame('{"a":{"x":1},"b":{"x":1}}', MetaNormalizer::encode(MetaNormalizer::normalize(['a' => $shared, 'b' => $shared])));
});

test('nesting deeper than json allows is refused', function () {
    $meta = [];

    for ($i = 0; $i < MetaNormalizer::DEPTH; $i++) {
        $meta = ['a' => $meta ?: 1];
    }

    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('nested deeper than 512 levels');

    MetaNormalizer::normalize($meta);
});

test('a stored value that is not a json object is refused', function (mixed $raw, string $message) {
    $this->expectException(UnexpectedValueException::class);
    $this->expectExceptionMessage($message);

    MetaNormalizer::decode($raw);
})->with([
    'null' => [null, 'is NULL, not a JSON object'],
    'integer' => [1, 'is int, not a JSON object'],
    'invalid json' => ['{"a":', 'not valid JSON'],
    'json scalar' => ['"text"', 'is a JSON string, not a JSON object'],
    'json number' => ['1', 'is a JSON int, not a JSON object'],
    'json null' => ['null', 'is a JSON null, not a JSON object'],
    'json list' => ['[1,2]', 'is a JSON list, not a JSON object'],
    'empty string' => ['', 'not valid JSON'],
]);
