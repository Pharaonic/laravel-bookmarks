<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bookmarks table
    |--------------------------------------------------------------------------
    |
    | The table that stores every bookmark. The package migration creates it
    | with this name, and the Bookmark model reads and writes it. Changing it
    | after the migration ran does not rename the existing table.
    |
    */

    'table' => 'bookmarks',

    /*
    |--------------------------------------------------------------------------
    | Bookmark model
    |--------------------------------------------------------------------------
    |
    | The model the package reads, writes, and returns. It must be the package
    | Bookmark model or a subclass of it. A subclass that sets $table uses that
    | table instead of the one above; one that sets $connection uses that
    | connection, and the package migration creates the table there.
    |
    */

    'model' => \Pharaonic\Laravel\Bookmarks\Models\Bookmark::class,

    /*
    |--------------------------------------------------------------------------
    | Clean up on delete
    |--------------------------------------------------------------------------
    |
    | When a model that uses CanBookmark or HasBookmarks is deleted (or force
    | deleted, when it uses SoftDeletes), delete the bookmarks it kept or
    | received. A soft delete always keeps them, so a restored model keeps
    | its bookmarks.
    |
    */

    'cleanup_on_delete' => true,

];
