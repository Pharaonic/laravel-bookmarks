## Configuration

Every setting is optional. Publish the file with `php artisan vendor:publish --tag=bookmarks-config` to change them.

```php title="config/pharaonic/bookmarks.php"
return [
    'table' => 'bookmarks',
    'model' => \Pharaonic\Laravel\Bookmarks\Models\Bookmark::class,
    'cleanup_on_delete' => true,
];
```

| Key | Default | Description |
| --- | --- | --- |
| `pharaonic.bookmarks.table` | `bookmarks` | The table the migration creates and the `Bookmark` model uses. |
| `pharaonic.bookmarks.model` | `Pharaonic\Laravel\Bookmarks\Models\Bookmark` | The model the package reads, writes and returns: the package model or a subclass. See [Custom Bookmark Model](#custom-model). |
| `pharaonic.bookmarks.cleanup_on_delete` | `true` | Delete a model's bookmarks when the model is deleted. See [Deletes and Soft Deletes](#deletion-and-soft-deletes). |

### Changing the table name

The migration reads the table name when it runs, so set it **before** the first `php artisan migrate`:

```php title="config/pharaonic/bookmarks.php"
'table' => 'saved_posts',
```

Index names follow the table name (`saved_posts_unique_pair`, `saved_posts_bookmarker_index`).

:::warning
Changing the setting after the migration ran does not rename the table: the package then reads and writes a table that doesn't exist. Rename the table in a migration of your own (`Schema::rename('bookmarks', 'saved_posts')`) at the same time as you change the setting. Rolling back the package migration drops the table the setting names at that moment.
:::
