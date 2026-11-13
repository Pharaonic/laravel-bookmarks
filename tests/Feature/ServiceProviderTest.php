<?php

use Illuminate\Support\ServiceProvider;
use Pharaonic\Laravel\Bookmarks\BookmarksServiceProvider;
use Pharaonic\Laravel\Bookmarks\Models\Bookmark;

test('the provider is registered', function () {
    $this->assertNotEmpty($this->app->getProviders(BookmarksServiceProvider::class));
});

test('the configuration defaults are merged', function () {
    $this->assertSame('bookmarks', $this->app['config']->get('pharaonic.bookmarks.table'));
    $this->assertSame(Bookmark::class, $this->app['config']->get('pharaonic.bookmarks.model'));
    $this->assertTrue($this->app['config']->get('pharaonic.bookmarks.cleanup_on_delete'));
});

test('the configuration is published to config pharaonic', function () {
    $source = realpath(__DIR__.'/../../config/bookmarks.php');

    foreach (['pharaonic', 'laravel-bookmarks', 'pharaonic-config', 'bookmarks-config'] as $tag) {
        $paths = ServiceProvider::pathsToPublish(BookmarksServiceProvider::class, $tag);
        $published = [];

        foreach ($paths as $from => $to) {
            $published[realpath($from) ?: $from] = $to;
        }

        $this->assertArrayHasKey($source, $published, $tag);
        $this->assertSame($this->app->configPath('pharaonic/bookmarks.php'), $published[$source]);
    }
});

test('the migrations are published to database migrations', function () {
    $source = realpath(__DIR__.'/../../database/migrations');

    foreach (['pharaonic', 'laravel-bookmarks', 'pharaonic-migrations', 'bookmarks-migrations'] as $tag) {
        $published = [];

        foreach (ServiceProvider::pathsToPublish(BookmarksServiceProvider::class, $tag) as $from => $to) {
            $published[realpath($from) ?: $from] = $to;
        }

        $this->assertArrayHasKey($source, $published, $tag);
        $this->assertSame($this->app->databasePath('migrations'), $published[$source]);
    }
});
