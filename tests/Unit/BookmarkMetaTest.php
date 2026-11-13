<?php

use Pharaonic\Laravel\Bookmarks\ValueObjects\BookmarkMeta;

function sampleBookmarkMeta(): BookmarkMeta
{
    return new BookmarkMeta([
        'note' => 'Read later',
        'priority' => 5,
        'read' => false,
        'nothing' => null,
        'preferences' => ['notify' => true, 'channels' => ['mail', 'push']],
        'tags' => [['name' => 'php'], ['name' => 'laravel']],
        'empty_object' => new stdClass,
        'empty_list' => [],
    ]);
}

test('keys read as properties at every depth', function () {
    $meta = sampleBookmarkMeta();

    $this->assertSame('Read later', $meta->note);
    $this->assertSame(5, $meta->priority);
    $this->assertFalse($meta->read);
    $this->assertInstanceOf(BookmarkMeta::class, $meta->preferences);
    $this->assertTrue($meta->preferences->notify);
    $this->assertSame(['mail', 'push'], $meta->preferences->channels);
    $this->assertSame('laravel', $meta->tags[1]->name);
    $this->assertNull($meta->missing);
    $this->assertSame(5, $meta->{'priority'});
});

test('get uses the default only for absent keys', function () {
    $meta = sampleBookmarkMeta();

    $this->assertSame('none', $meta->get('missing', 'none'));
    $this->assertNull($meta->get('nothing', 'none'));
    $this->assertFalse($meta->get('read', true));
    $this->assertTrue($meta->get('preferences')->get('notify'));
});

test('has tells a present null from an absent key', function () {
    $meta = sampleBookmarkMeta();

    $this->assertTrue($meta->has('nothing'));
    $this->assertFalse($meta->has('missing'));
    $this->assertFalse(isset($meta->nothing));
    $this->assertTrue(isset($meta->read));
    $this->assertTrue(isset($meta->preferences->notify));
});

test('is empty', function () {
    $this->assertTrue((new BookmarkMeta)->isEmpty());
    $this->assertTrue((new BookmarkMeta([]))->isEmpty());
    $this->assertTrue(sampleBookmarkMeta()->empty_object->isEmpty());
    $this->assertFalse(sampleBookmarkMeta()->isEmpty());
});

test('properties cannot be set or unset', function () {
    $meta = sampleBookmarkMeta();

    foreach ([
        function () use ($meta) {
            $meta->priority = 10;
        },
        function () use ($meta) {
            $meta->preferences->notify = false;
        },
        function () use ($meta) {
            unset($meta->priority);
        },
    ] as $write) {
        try {
            $write();
            $this->fail('The metadata was written.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('read-only', $exception->getMessage());
        }
    }

    $this->assertSame(5, $meta->priority);
    $this->assertTrue($meta->preferences->notify);
});

test('returned values cannot reach the stored metadata', function () {
    $meta = sampleBookmarkMeta();

    $tags = $meta->tags;
    $tags[0] = 'changed';
    $object = $meta->toObject();
    $object->priority = 10;
    $object->preferences->notify = false;
    $object->tags[0]->name = 'changed';
    $array = $meta->toArray();
    $array['preferences']['notify'] = false;

    $this->assertSame(5, $meta->priority);
    $this->assertTrue($meta->preferences->notify);
    $this->assertSame('php', $meta->tags[0]->name);
});

test('the constructor detaches from its input', function () {
    $input = (object) ['nested' => (object) ['a' => 1]];
    $meta = new BookmarkMeta($input);

    $input->nested->a = 2;

    $this->assertSame(1, $meta->nested->a);
});

test('to object keeps empty objects and empty lists apart', function () {
    $object = sampleBookmarkMeta()->toObject();

    $this->assertInstanceOf(stdClass::class, $object->empty_object);
    $this->assertSame([], $object->empty_list);
    $this->assertInstanceOf(stdClass::class, $object->tags[0]);
    $this->assertSame(
        '{"empty_object":{},"empty_list":[]}',
        json_encode((new BookmarkMeta(['empty_object' => $object->empty_object, 'empty_list' => []]))->toObject())
    );
});

test('to array returns plain arrays', function () {
    $this->assertSame([
        'note' => 'Read later',
        'priority' => 5,
        'read' => false,
        'nothing' => null,
        'preferences' => ['notify' => true, 'channels' => ['mail', 'push']],
        'tags' => [['name' => 'php'], ['name' => 'laravel']],
        'empty_object' => [],
        'empty_list' => [],
    ], sampleBookmarkMeta()->toArray());
});

test('json encoding writes a json object', function () {
    $this->assertSame('{}', json_encode(new BookmarkMeta));
    $this->assertSame(
        '{"note":"Read later","priority":5,"read":false,"nothing":null,"preferences":{"notify":true,"channels":["mail","push"]},'
        .'"tags":[{"name":"php"},{"name":"laravel"}],"empty_object":{},"empty_list":[]}',
        json_encode(sampleBookmarkMeta())
    );
});

test('a bookmark meta round trips through its own constructor', function () {
    $meta = sampleBookmarkMeta();

    $this->assertSame(json_encode($meta), json_encode(new BookmarkMeta($meta->toObject())));
});

test('the constructor refuses a list', function () {
    $this->expectException(InvalidArgumentException::class);

    new BookmarkMeta(['a', 'b']);
});
