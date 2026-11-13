<?php

/*
 * One worker of ConcurrencyTest: boots the package, waits for the shared
 * start time, then acts on one pair and prints the result as JSON.
 *
 * Usage: php worker.php <bookmark|unbookmark|toggle> <user id> <post id> <start time> <worker number>
 */

use Illuminate\Support\Facades\Event;
use Pharaonic\Laravel\Bookmarks\Events\BookmarkCreated;
use Pharaonic\Laravel\Bookmarks\Events\BookmarkUpdated;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\Post;
use Pharaonic\Laravel\Bookmarks\Tests\Fixtures\User;
use Pharaonic\Laravel\Bookmarks\Tests\Processes\WorkerApplication;

require __DIR__.'/../../vendor/autoload.php';

[, $action, $userId, $postId, $start, $worker] = $argv;

(new WorkerApplication('worker'))->createApplication();

$events = ['created' => 0, 'updated' => 0];
Event::listen(BookmarkCreated::class, function () use (&$events) {
    $events['created']++;
});
Event::listen(BookmarkUpdated::class, function () use (&$events) {
    $events['updated']++;
});

$user = User::query()->findOrFail($userId);
$post = Post::query()->findOrFail($postId);

if ((float) $start > microtime(true)) {
    time_sleep_until((float) $start);
}

switch ($action) {
    case 'bookmark':
        $bookmark = $user->bookmark($post, ['worker'.$worker => true]);
        $result = ['id' => $bookmark->getKey(), 'meta' => $bookmark->meta];

        break;
    case 'unbookmark':
        $result = ['removed' => $user->unbookmark($post)];

        break;
    default:
        $result = ['bookmarked' => $user->toggleBookmark($post) !== null];
}

echo json_encode($result + $events);
