<?php

namespace Pharaonic\Laravel\Bookmarks;

use Illuminate\Support\ServiceProvider;

final class BookmarksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bookmarks.php', 'pharaonic.bookmarks');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/bookmarks.php' => $this->app->configPath('pharaonic/bookmarks.php'),
            ], ['pharaonic', 'laravel-bookmarks', 'pharaonic-config', 'bookmarks-config']);

            $this->publishes([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], ['pharaonic', 'laravel-bookmarks', 'pharaonic-migrations', 'bookmarks-migrations']);
        }
    }
}
