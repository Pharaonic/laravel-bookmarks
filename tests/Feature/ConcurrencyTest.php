<?php

use Pharaonic\Laravel\Bookmarks\Models\Bookmark;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Post;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\User;
use Symfony\Component\Process\Process;

/*
 * Separate PHP processes, each with its own connection, act on one pair at
 * the same moment. On SQLite the database file serializes the writes; on
 * MySQL and PostgreSQL (BOOKMARKS_DB) they really run in parallel.
 */

const CONCURRENCY_WORKERS = 8;

test('simultaneous bookmarks create one row and the last writer wins', function () {
    [$user, $post, $results] = bookmarkRace($this->useSharedDatabase(), 'bookmark');

    $this->assertSame(1, Bookmark::query()->count());
    $this->assertSame(1, array_sum(array_column($results, 'created')));
    $this->assertCount(1, array_unique(array_column($results, 'id')));

    // Whole replacement, never a merge: one worker's metadata, alone.
    $stored = $user->bookmarkOf($post)?->meta->toArray() ?? [];
    $this->assertCount(1, $stored);
    $this->assertContains(array_key_first($stored), array_map(function ($worker) {
        return 'worker'.$worker;
    }, range(1, CONCURRENCY_WORKERS)));

    // Each call returned the metadata it wrote.
    foreach ($results as $number => $result) {
        $this->assertSame(['worker'.($number + 1) => true], $result['meta']);
    }
});

test('simultaneous unbookmarks delete the row once', function () {
    [$user, $post, $results] = bookmarkRace($this->useSharedDatabase(), 'unbookmark', true);

    $this->assertSame(1, array_sum(array_column($results, 'removed')));
    $this->assertSame(0, Bookmark::query()->count());
    $this->assertFalse($user->hasBookmarked($post));
});

test('simultaneous toggles never duplicate the bookmark', function () {
    [$user, $post] = bookmarkRace($this->useSharedDatabase(), 'toggle');

    $this->assertLessThanOrEqual(1, Bookmark::query()->count());
    $this->assertSame(Bookmark::query()->count() === 1, $user->hasBookmarked($post));
});

/**
 * Start the workers on one pair at the same moment and collect what each returned.
 *
 * @param  array<string, string>  $env
 * @return array{0: User, 1: Post, 2: array<int, array<string, mixed>>}
 */
function bookmarkRace(array $env, string $action, bool $bookmarked = false): array
{
    $user = User::create();
    $post = Post::create();

    if ($bookmarked) {
        $user->bookmark($post);
    }

    // Leave the workers time to boot, then let them all start together.
    $start = (string) (microtime(true) + 3);
    $processes = [];

    for ($i = 1; $i <= CONCURRENCY_WORKERS; $i++) {
        $process = new Process(
            [PHP_BINARY, __DIR__.'/../Processes/worker.php', $action, (string) $user->getKey(), (string) $post->getKey(), $start, (string) $i],
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
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
        $result = json_decode($process->getOutput(), true);
        expect($result)->toBeArray($process->getOutput());
        $results[] = $result;
    }

    return [$user, $post, $results];
}
