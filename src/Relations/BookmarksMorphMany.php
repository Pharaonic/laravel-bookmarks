<?php

namespace Pharaonic\Laravel\Bookmarks\Relations;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Pharaonic\Laravel\Bookmarks\Models\Bookmark;
use Pharaonic\Laravel\Bookmarks\Support\BookmarkManager;

/**
 * The bookmarks relation of a bookmarker or bookmarkable model.
 *
 * The bookmarks table stores ids as strings, so integer, UUID and ULID keys can
 * share it. This relation compares the parent key as a string too: PostgreSQL
 * refuses to compare a bigint or uuid column with a varchar one, and on MySQL
 * and SQLite the string comparison is what lets the unique index be used.
 *
 * @internal
 *
 * @template TDeclaringModel of Model
 *
 * @extends MorphMany<Bookmark, TDeclaringModel>
 */
final class BookmarksMorphMany extends MorphMany
{
    /**
     * Declared here so PHPStan 2.1.0, the line's lowest, infers TDeclaringModel
     * from `new BookmarksMorphMany(...)`: it does not read the inherited constructor.
     *
     * @param  Builder<Bookmark>  $query
     * @param  TDeclaringModel  $parent
     * @param  string  $type
     * @param  string  $id
     * @param  string  $localKey
     */
    public function __construct(Builder $query, Model $parent, $type, $id, $localKey)
    {
        parent::__construct($query, $parent, $type, $id, $localKey);
    }

    /**
     * The parent key, as the string the bookmarks table stores.
     *
     * @return mixed
     */
    public function getParentKey()
    {
        $key = parent::getParentKey();

        return BookmarkManager::keyToString($key) ?? $key;
    }

    /**
     * Constrain eager loading to the parents' keys, as strings.
     *
     * @param  array<int, TDeclaringModel>  $models
     * @return void
     */
    public function addEagerConstraints(array $models)
    {
        $keys = [];

        foreach ($this->getKeys($models, $this->localKey) as $key) {
            $key = BookmarkManager::keyToString($key);

            if ($key !== null) {
                $keys[] = $key;
            }
        }

        $this->query->whereIn($this->foreignKey, $keys);
        $this->query->where($this->morphType, $this->morphClass);
    }

    /**
     * The subquery behind has(), whereHas() and withCount().
     *
     * @param  Builder<Bookmark>  $query
     * @param  Builder<TDeclaringModel>  $parentQuery
     * @param  mixed  $columns
     * @return Builder<Bookmark>
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        $grammar = $query->getQuery()->getGrammar();
        $parentKey = $grammar->wrap($this->getQualifiedParentKeyName());

        $connection = $query->getQuery()->getConnection();
        $driver = $connection instanceof Connection ? $connection->getDriverName() : null;

        $parentKeyAsString = match ($driver) {
            // CONCAT() takes the collation of the bookmarks column, so a connection
            // collation that differs from the table's cannot make it fail.
            'mysql', 'mariadb' => 'concat('.$parentKey.')',
            'pgsql' => 'cast('.$parentKey.' as varchar)',
            'sqlite' => 'cast('.$parentKey.' as text)',
            default => null,
        };

        if ($parentKeyAsString === null || $query->getQuery()->from == $parentQuery->getQuery()->from) {
            return parent::getRelationExistenceQuery($query, $parentQuery, $columns);
        }

        $query->select($columns);
        $query->whereRaw($parentKeyAsString.' = '.$grammar->wrap($this->getExistenceCompareKey()));
        $query->where($query->qualifyColumn($this->getMorphType()), $this->morphClass);

        return $query;
    }
}
