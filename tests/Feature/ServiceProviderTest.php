<?php

namespace Pharaonic\Laravel\Bookmarks\Tests\Feature;

use Illuminate\Support\ServiceProvider;
use Pharaonic\Laravel\Bookmarks\BookmarksServiceProvider;
use Pharaonic\Laravel\Bookmarks\Models\Bookmark;
use Pharaonic\Laravel\Bookmarks\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_the_provider_is_registered(): void
    {
        $this->assertNotEmpty($this->app->getProviders(BookmarksServiceProvider::class));
    }

    public function test_the_configuration_defaults_are_merged(): void
    {
        $this->assertSame('bookmarks', $this->app['config']->get('pharaonic.bookmarks.table'));
        $this->assertSame(Bookmark::class, $this->app['config']->get('pharaonic.bookmarks.model'));
        $this->assertTrue($this->app['config']->get('pharaonic.bookmarks.cleanup_on_delete'));
    }

    public function test_the_configuration_is_published_to_config_pharaonic(): void
    {
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
    }

    public function test_the_migrations_are_published_to_database_migrations(): void
    {
        $source = realpath(__DIR__.'/../../database/migrations');

        foreach (['pharaonic', 'laravel-bookmarks', 'pharaonic-migrations', 'bookmarks-migrations'] as $tag) {
            $published = [];

            foreach (ServiceProvider::pathsToPublish(BookmarksServiceProvider::class, $tag) as $from => $to) {
                $published[realpath($from) ?: $from] = $to;
            }

            $this->assertArrayHasKey($source, $published, $tag);
            $this->assertSame($this->app->databasePath('migrations'), $published[$source]);
        }
    }
}
