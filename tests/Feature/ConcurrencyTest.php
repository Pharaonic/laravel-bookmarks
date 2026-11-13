<?php

namespace Pharaonic\Laravel\Bookmarks\Tests\Feature;

use Pharaonic\Laravel\Bookmarks\Models\Bookmark;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Post;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\User;
use Pharaonic\Laravel\Bookmarks\Tests\TestCase;
use Symfony\Component\Process\Process;

/**
 * Separate PHP processes, each with its own connection, act on one pair at
 * the same moment. On SQLite the database file serializes the writes; on
 * MySQL and PostgreSQL (BOOKMARKS_DB) they really run in parallel.
 */
class ConcurrencyTest extends TestCase
{
    private const WORKERS = 8;

    public function test_simultaneous_bookmarks_create_one_row_and_the_last_writer_wins(): void
    {
        [$user, $post, $results] = $this->race('bookmark');

        $this->assertSame(1, Bookmark::query()->count());
        $this->assertSame(1, array_sum(array_column($results, 'created')));
        $this->assertCount(1, array_unique(array_column($results, 'id')));

        // Whole replacement, never a merge: one worker's metadata, alone.
        $stored = $user->bookmarkOf($post)?->meta->toArray() ?? [];
        $this->assertCount(1, $stored);
        $this->assertContains(array_key_first($stored), array_map(function ($worker) {
            return 'worker'.$worker;
        }, range(1, self::WORKERS)));

        // Each call returned the metadata it wrote.
        foreach ($results as $number => $result) {
            $this->assertSame(['worker'.($number + 1) => true], $result['meta']);
        }
    }

    public function test_simultaneous_unbookmarks_delete_the_row_once(): void
    {
        [$user, $post, $results] = $this->race('unbookmark', true);

        $this->assertSame(1, array_sum(array_column($results, 'removed')));
        $this->assertSame(0, Bookmark::query()->count());
        $this->assertFalse($user->hasBookmarked($post));
    }

    public function test_simultaneous_toggles_never_duplicate_the_bookmark(): void
    {
        [$user, $post] = $this->race('toggle');

        $this->assertLessThanOrEqual(1, Bookmark::query()->count());
        $this->assertSame(Bookmark::query()->count() === 1, $user->hasBookmarked($post));
    }

    /**
     * @return array{0: User, 1: Post, 2: array<int, array<string, mixed>>}
     */
    private function race(string $action, bool $bookmarked = false): array
    {
        $env = $this->useSharedDatabase();
        $user = User::create();
        $post = Post::create();

        if ($bookmarked) {
            $user->bookmark($post);
        }

        // Leave the workers time to boot, then let them all start together.
        $start = (string) (microtime(true) + 3);
        $processes = [];

        for ($i = 1; $i <= self::WORKERS; $i++) {
            $process = new Process(
                [PHP_BINARY, __DIR__.'/../Concurrency/worker.php', $action, (string) $user->getKey(), (string) $post->getKey(), $start, (string) $i],
                null,
                $env,
                null,
                60
            );
            $process->start();
            $processes[] = $process;
        }

        $results = [];

        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $result = json_decode($process->getOutput(), true);
            $this->assertIsArray($result, $process->getOutput());
            $results[] = $result;
        }

        return [$user, $post, $results];
    }
}
