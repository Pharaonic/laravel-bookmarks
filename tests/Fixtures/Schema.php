<?php

namespace Pharaonic\Laravel\Bookmarks\Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema as SchemaFacade;

/**
 * The fixture tables. The bookmarks table comes from the package migration.
 */
final class Schema
{
    public static function create(): void
    {
        foreach (['users', 'bots', 'posts', 'comments', 'products'] as $name) {
            SchemaFacade::create($name, function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name')->nullable();
                $table->timestamps();
            });
        }

        foreach (['soft_deletable_posts', 'soft_deletable_members'] as $name) {
            SchemaFacade::create($name, function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        SchemaFacade::create('uuid_models', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        SchemaFacade::create('ulid_models', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }
}
