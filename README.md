<p align="center"><a href="https://pharaonic.dev/packages/laravel/bookmarks" target="_blank"><img src="https://raw.githubusercontent.com/Pharaonic/laravel-bookmarks/develop/docs/cover.webp"></a></p>

<p align="center">
  <a href="https://php.net" target="_blank"><img src="https://img.shields.io/static/v1?label=PHP&message=8.1%20|%208.2%20|%208.3&color=blue&style=flat-square" alt="PHP Version : 8.1 | 8.2 | 8.3"></a>
  <a href="https://laravel.com" target="_blank"><img src="https://img.shields.io/static/v1?label=Laravel&message=10.x&color=F05340&style=flat-square" alt="Laravel Version : 10.x"></a>
  <img src="https://img.shields.io/static/v1?label=License&message=MIT&color=brightgreen&style=flat-square" alt="License">
  <img src="https://github.com/Pharaonic/laravel-bookmarks/actions/workflows/build.yml/badge.svg?branch=10.x" alt="Build">
  <br>
  <a href="https://packagist.org/packages/Pharaonic/laravel-bookmarks" target="_blank"><img src="https://img.shields.io/static/v1?label=Packagist&message=pharaonic/laravel-bookmarks&color=blue&logo=packagist&logoColor=white" alt="Source"></a>
  <a href="https://packagist.org/packages/pharaonic/laravel-bookmarks" target="_blank"><img src="https://poser.pugx.org/pharaonic/laravel-bookmarks/v" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/pharaonic/laravel-bookmarks" target="_blank"><img src="https://poser.pugx.org/pharaonic/laravel-bookmarks/downloads" alt="Packagist Downloads"></a>
</p>

<h3 align="center">Polymorphic bookmarks for Eloquent models, with JSON-object metadata.</h3>
<br>

## Installation

```bash
composer require pharaonic/laravel-bookmarks
php artisan migrate
```

The service provider is registered automatically, and `migrate` creates the `bookmarks` table. Publish the migration only to change it (`--tag=bookmarks-migrations`), and the configuration only to rename the table, use your own Bookmark model, or turn off cleanup on delete:

```bash
php artisan vendor:publish --tag=bookmarks-config
```

## Usage

Add `CanBookmark` to the model that bookmarks (it does not have to be a user) and `HasBookmarks` to the model that is bookmarked. A model can use both.

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use Pharaonic\Laravel\Bookmarks\Concerns\CanBookmark;

class User extends Authenticatable
{
    use CanBookmark;
}
```

```php
use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Bookmarks\Concerns\HasBookmarks;

class Post extends Model
{
    use HasBookmarks;
}
```

```php
$bookmark = $user->bookmark($post, ['note' => 'Read later', 'preferences' => ['notify' => true]]);

$bookmark->meta->note;                 // 'Read later'
$bookmark->meta->preferences->notify;  // true
$bookmark->meta->get('priority', 0);   // 0: the key is absent

$user->bookmark($post, ['priority' => 2]); // replaces the whole metadata: note is gone
$user->bookmark($post);                    // keeps {"priority":2}
$user->bookmark($post, []);                // empties it to {}

$user->bookmarkOf($post);      // the Bookmark, or null
$user->hasBookmarked($post);   // true
$user->unbookmark($post);      // true: this call removed it, false when there was none
$user->toggleBookmark($post);  // the new Bookmark, or null when it was removed

Post::whereBookmarkedBy($user)->latest()->paginate();
Post::withBookmarkedBy($user)->withCount('bookmarks')->get(); // each post has is_bookmarked
$user->givenBookmarks()->with('bookmarkable')->latest()->get();
```

## Documentation

Full documentation: [Laravel Bookmarks Documentation](https://pharaonic.dev/packages/laravel/bookmarks).

## Support & Security

See [SUPPORT.md](SUPPORT.md) and [SECURITY.md](SECURITY.md). Contributions are welcome; see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT. See [LICENSE](LICENSE).
