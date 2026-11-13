<?php

namespace Pharaonic\Laravel\Bookmarks\Tests\Unit;

use InvalidArgumentException;
use LogicException;
use Pharaonic\Laravel\Bookmarks\ValueObjects\BookmarkMeta;
use PHPUnit\Framework\TestCase;
use stdClass;

class BookmarkMetaTest extends TestCase
{
    private function meta(): BookmarkMeta
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

    public function test_keys_read_as_properties_at_every_depth(): void
    {
        $meta = $this->meta();

        $this->assertSame('Read later', $meta->note);
        $this->assertSame(5, $meta->priority);
        $this->assertFalse($meta->read);
        $this->assertInstanceOf(BookmarkMeta::class, $meta->preferences);
        $this->assertTrue($meta->preferences->notify);
        $this->assertSame(['mail', 'push'], $meta->preferences->channels);
        $this->assertSame('laravel', $meta->tags[1]->name);
        $this->assertNull($meta->missing);
        $this->assertSame(5, $meta->{'priority'});
    }

    public function test_get_uses_the_default_only_for_absent_keys(): void
    {
        $meta = $this->meta();

        $this->assertSame('none', $meta->get('missing', 'none'));
        $this->assertNull($meta->get('nothing', 'none'));
        $this->assertFalse($meta->get('read', true));
        $this->assertTrue($meta->get('preferences')->get('notify'));
    }

    public function test_has_tells_a_present_null_from_an_absent_key(): void
    {
        $meta = $this->meta();

        $this->assertTrue($meta->has('nothing'));
        $this->assertFalse($meta->has('missing'));
        $this->assertFalse(isset($meta->nothing));
        $this->assertTrue(isset($meta->read));
        $this->assertTrue(isset($meta->preferences->notify));
    }

    public function test_is_empty(): void
    {
        $this->assertTrue((new BookmarkMeta)->isEmpty());
        $this->assertTrue((new BookmarkMeta([]))->isEmpty());
        $this->assertTrue($this->meta()->empty_object->isEmpty());
        $this->assertFalse($this->meta()->isEmpty());
    }

    public function test_properties_cannot_be_set_or_unset(): void
    {
        $meta = $this->meta();

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
    }

    public function test_returned_values_cannot_reach_the_stored_metadata(): void
    {
        $meta = $this->meta();

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
    }

    public function test_the_constructor_detaches_from_its_input(): void
    {
        $input = (object) ['nested' => (object) ['a' => 1]];
        $meta = new BookmarkMeta($input);

        $input->nested->a = 2;

        $this->assertSame(1, $meta->nested->a);
    }

    public function test_to_object_keeps_empty_objects_and_empty_lists_apart(): void
    {
        $object = $this->meta()->toObject();

        $this->assertInstanceOf(stdClass::class, $object->empty_object);
        $this->assertSame([], $object->empty_list);
        $this->assertInstanceOf(stdClass::class, $object->tags[0]);
        $this->assertSame(
            '{"empty_object":{},"empty_list":[]}',
            json_encode((new BookmarkMeta(['empty_object' => $object->empty_object, 'empty_list' => []]))->toObject())
        );
    }

    public function test_to_array_returns_plain_arrays(): void
    {
        $this->assertSame([
            'note' => 'Read later',
            'priority' => 5,
            'read' => false,
            'nothing' => null,
            'preferences' => ['notify' => true, 'channels' => ['mail', 'push']],
            'tags' => [['name' => 'php'], ['name' => 'laravel']],
            'empty_object' => [],
            'empty_list' => [],
        ], $this->meta()->toArray());
    }

    public function test_json_encoding_writes_a_json_object(): void
    {
        $this->assertSame('{}', json_encode(new BookmarkMeta));
        $this->assertSame(
            '{"note":"Read later","priority":5,"read":false,"nothing":null,"preferences":{"notify":true,"channels":["mail","push"]},'
            .'"tags":[{"name":"php"},{"name":"laravel"}],"empty_object":{},"empty_list":[]}',
            json_encode($this->meta())
        );
    }

    public function test_a_bookmark_meta_round_trips_through_its_own_constructor(): void
    {
        $meta = $this->meta();

        $this->assertSame(json_encode($meta), json_encode(new BookmarkMeta($meta->toObject())));
    }

    public function test_the_constructor_refuses_a_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BookmarkMeta(['a', 'b']);
    }
}
