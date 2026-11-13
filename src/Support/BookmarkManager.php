<?php

namespace Pharaonic\Laravel\Bookmarks\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Pharaonic\Laravel\Bookmarks\Events\BookmarkCreated;
use Pharaonic\Laravel\Bookmarks\Events\BookmarkRemoved;
use Pharaonic\Laravel\Bookmarks\Events\BookmarkUpdated;
use Pharaonic\Laravel\Bookmarks\Models\Bookmark;
use Pharaonic\Laravel\Bookmarks\Relations\BookmarksMorphMany;
use RuntimeException;
use stdClass;
use Stringable;
use UnexpectedValueException;

/**
 * The one place that reads and writes bookmarks, shared by CanBookmark and HasBookmarks.
 *
 * Every write runs in a transaction on the bookmark model's connection. Outside
 * a caller's transaction, Laravel runs it again when the database reports a
 * deadlock or a locked SQLite file, up to ATTEMPTS times; inside one, the
 * error goes to the caller, because the database already rolled the caller's
 * transaction back. Events are dispatched after the write's own transaction,
 * so inside a caller's transaction they come before that transaction commits.
 *
 * @internal
 */
final class BookmarkManager
{
    /**
     * The longest morph type the bookmarks table stores.
     */
    public const TYPE_LENGTH = 191;

    /**
     * The longest key the bookmarks table stores.
     */
    public const KEY_LENGTH = 64;

    /**
     * How many times a write runs when a concurrent request keeps getting in its way.
     */
    private const ATTEMPTS = 3;

    /**
     * The configured bookmark model class.
     *
     * @return class-string<Bookmark>
     *
     * @throws UnexpectedValueException when pharaonic.bookmarks.model is not Bookmark or a subclass
     */
    public static function modelClass(): string
    {
        $class = Config::get('pharaonic.bookmarks.model');

        if ($class === null || $class === '') {
            return Bookmark::class;
        }

        if (! is_string($class) || ! is_a($class, Bookmark::class, true)) {
            throw new UnexpectedValueException(sprintf(
                'The pharaonic.bookmarks.model setting must name %s or a subclass of it, not [%s].',
                Bookmark::class, is_string($class) ? $class : get_debug_type($class)
            ));
        }

        return $class;
    }

    /**
     * A new instance of the configured bookmark model.
     */
    public static function newModel(): Bookmark
    {
        $class = self::modelClass();

        return new $class;
    }

    /**
     * Bookmark the bookmarkable, replacing the metadata when it is given.
     *
     * Null keeps the metadata of an existing bookmark, and a new one starts
     * with {}. Metadata equal to what is stored writes nothing.
     *
     * @param  array<mixed>|stdClass|\Pharaonic\Laravel\Bookmarks\ValueObjects\BookmarkMeta|null  $meta
     */
    public static function bookmark(Model $bookmarker, Model $bookmarkable, mixed $meta): Bookmark
    {
        $meta = $meta === null ? null : MetaNormalizer::normalize($meta);
        $pair = self::pair($bookmarker, $bookmarkable);

        /** @var array{0: Bookmark, 1: class-string<BookmarkCreated|BookmarkUpdated>|null} $result */
        $result = self::newModel()->getConnection()->transaction(function () use ($pair, $meta) {
            return self::write($pair, $meta);
        }, self::ATTEMPTS);

        [$bookmark, $event] = $result;

        if ($event !== null) {
            Event::dispatch(new $event($bookmark, $bookmarker, $bookmarkable));
        }

        return $bookmark;
    }

    /**
     * Remove the bookmark. True when this call deleted it.
     */
    public static function unbookmark(Model $bookmarker, Model $bookmarkable): bool
    {
        $bookmark = self::find(self::pair($bookmarker, $bookmarkable));

        if ($bookmark === null) {
            return false;
        }

        $deleted = (bool) $bookmark->getConnection()->transaction(function () use ($bookmark) {
            return self::delete($bookmark);
        }, self::ATTEMPTS);

        if ($deleted) {
            Event::dispatch(new BookmarkRemoved($bookmark, $bookmarker, $bookmarkable));
        }

        return $deleted;
    }

    /**
     * Bookmark the bookmarkable with the metadata, or remove the bookmark when
     * it exists. Returns the new bookmark, or null when the pair has none now.
     *
     * The insert comes first, so the unique index rather than a read decides
     * which branch runs.
     *
     * @param  array<mixed>|stdClass|\Pharaonic\Laravel\Bookmarks\ValueObjects\BookmarkMeta|null  $meta
     */
    public static function toggle(Model $bookmarker, Model $bookmarkable, mixed $meta): ?Bookmark
    {
        $meta = $meta === null ? new stdClass : MetaNormalizer::normalize($meta);
        $pair = self::pair($bookmarker, $bookmarkable);

        /** @var array{0: Bookmark|null, 1: class-string<BookmarkCreated|BookmarkRemoved>|null} $result */
        $result = self::newModel()->getConnection()->transaction(function () use ($pair, $meta) {
            [$bookmark, $created] = self::insert($pair, $meta);

            if ($bookmark === null) {
                // Refused, and a concurrent call already deleted the row.
                return [null, null];
            }

            if ($created) {
                return [$bookmark, BookmarkCreated::class];
            }

            // Refused because the pair had a bookmark: remove it, unless a
            // concurrent call already did. Either way the pair has none now.
            return self::delete($bookmark) ? [$bookmark, BookmarkRemoved::class] : [null, null];
        }, self::ATTEMPTS);

        [$bookmark, $event] = $result;

        if ($bookmark !== null && $event !== null) {
            Event::dispatch(new $event($bookmark, $bookmarker, $bookmarkable));
        }

        return $event === BookmarkCreated::class ? $bookmark : null;
    }

    /**
     * The pair's bookmark, or null. Null when either model is unsaved.
     */
    public static function of(Model $bookmarker, Model $bookmarkable): ?Bookmark
    {
        if (! self::isPersisted($bookmarker) || ! self::isPersisted($bookmarkable)) {
            return null;
        }

        return self::find(self::pair($bookmarker, $bookmarkable));
    }

    /**
     * Whether the bookmarker bookmarked the bookmarkable. False when either one is unsaved.
     */
    public static function has(Model $bookmarker, Model $bookmarkable): bool
    {
        if (! self::isPersisted($bookmarker) || ! self::isPersisted($bookmarkable)) {
            return false;
        }

        return self::unscoped()->where(self::pair($bookmarker, $bookmarkable))->exists();
    }

    /**
     * The bookmarks kept (bookmarker) or received (bookmarkable) by the model.
     */
    public static function relation(Model $parent, string $side): MorphMany
    {
        $bookmark = self::newModel();
        $table = $bookmark->getTable();

        return new BookmarksMorphMany(
            $bookmark->newQuery(),
            $parent,
            $table.'.'.$side.'_type',
            $table.'.'.$side.'_id',
            $parent->getKeyName()
        );
    }

    /**
     * Limit a bookmarks query to one bookmarker or bookmarkable. An unsaved model matches nothing.
     */
    public static function constrain(Builder $query, string $side, Model $model): void
    {
        if (! self::isPersisted($model)) {
            $query->whereRaw('0 = 1');

            return;
        }

        [$type, $key] = self::endpoint($model, $side);

        $query->where($query->qualifyColumn($side.'_type'), $type);
        $query->where($query->qualifyColumn($side.'_id'), $key);
    }

    /**
     * Add an is_bookmarked column to a bookmarkables query: whether the
     * bookmarker bookmarked each row, from one EXISTS subquery.
     */
    public static function selectBookmarkedBy(Builder $query, Model $bookmarkable, Model $bookmarker): void
    {
        $relation = self::relation($bookmarkable, 'bookmarkable');

        $exists = $relation->getRelationExistenceQuery(
            $relation->getRelated()->newQueryWithoutRelationships(), $query, [new Expression('1')]
        );

        self::constrain($exists, 'bookmarker', $bookmarker);

        $base = $query->getQuery();

        if ($base->columns === null) {
            $query->select([$bookmarkable->qualifyColumn('*')]);
        }

        $query->selectRaw(
            'exists('.$exists->toSql().') as '.$base->getGrammar()->wrap('is_bookmarked'),
            $exists->getBindings()
        );
    }

    /**
     * Delete the bookmarks of a model that is being deleted, unless it is only soft deleted.
     */
    public static function cleanUp(Model $model, string $side): void
    {
        if (! (bool) Config::get('pharaonic.bookmarks.cleanup_on_delete', true)) {
            return;
        }

        if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
            return;
        }

        $key = self::key($model);

        if ($key === null) {
            return;
        }

        self::unscoped()->toBase()
            ->where($side.'_type', $model->getMorphClass())
            ->where($side.'_id', $key)
            ->delete();
    }

    /**
     * Whether the database refused a row because of a unique index.
     */
    public static function isUniqueViolation(QueryException $exception): bool
    {
        $errorInfo = is_array($exception->errorInfo) ? $exception->errorInfo : [];
        $sqlState = isset($errorInfo[0]) && is_string($errorInfo[0]) ? $errorInfo[0] : (string) $exception->getCode();
        $driverCode = $errorInfo[1] ?? null;

        // PostgreSQL: unique_violation.
        if ($sqlState === '23505') {
            return true;
        }

        // MySQL and MariaDB: ER_DUP_ENTRY, ER_DUP_ENTRY_WITH_KEY_NAME.
        // SQL Server: duplicate key in a unique index or constraint.
        if (in_array($driverCode, [1062, 1586, 2601, 2627], true)) {
            return true;
        }

        // SQLite reports every constraint failure as code 19.
        return str_contains($exception->getMessage(), 'UNIQUE constraint failed');
    }

    /**
     * Whether the database refused a row because of the pair's own unique
     * index, {table}_unique_pair, rather than any other index on the table.
     *
     * MySQL, MariaDB, PostgreSQL and SQL Server name the index in the message;
     * SQLite lists its columns instead.
     */
    public static function isPairViolation(QueryException $exception, Bookmark $bookmark): bool
    {
        if (! self::isUniqueViolation($exception)) {
            return false;
        }

        $message = $exception->getMessage();
        $table = $bookmark->getTable();

        if (str_contains($message, $table.'_unique_pair')) {
            return true;
        }

        $qualified = $bookmark->getConnection()->getTablePrefix().$table.'.';

        return str_contains($message, 'UNIQUE constraint failed: '.$qualified.'bookmarkable_type, '.$qualified.'bookmarkable_id, '
            .$qualified.'bookmarker_type, '.$qualified.'bookmarker_id');
    }

    /**
     * Create, replace, or keep the pair's bookmark, inside the write's transaction.
     *
     * @param  array{bookmarker_type: string, bookmarker_id: string, bookmarkable_type: string, bookmarkable_id: string}  $pair
     * @return array{0: Bookmark, 1: class-string<BookmarkCreated|BookmarkUpdated>|null}
     */
    private static function write(array $pair, ?stdClass $meta): array
    {
        // A SQLite transaction that reads first cannot wait for another writer
        // when it then writes: SQLite fails it at once ("database is locked").
        // Inserting first takes the write lock before anything else.
        $insertFirst = self::newModel()->getConnection()->getDriverName() === 'sqlite';

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            // The first read may come from the transaction's snapshot. After a
            // miss, a locking read returns the row as it is committed now.
            $locked = $attempt > 1;
            $bookmark = $insertFirst && $attempt === 1 ? null : self::find($pair, $locked);

            if ($bookmark === null) {
                [$bookmark, $created] = self::insert($pair, $meta ?? new stdClass);

                // Refused, then deleted before it could be read: try again.
                if ($bookmark === null) {
                    continue;
                }

                if ($created) {
                    return [$bookmark, BookmarkCreated::class];
                }

                $locked = true;
            }

            if ($meta === null) {
                return [$bookmark, null];
            }

            // Lock the row before comparing, so a concurrent delete cannot
            // turn the update into a write that changed nothing.
            if (! $locked) {
                $bookmark = self::find($pair, true);

                if ($bookmark === null) {
                    continue;
                }
            }

            if (self::holds($bookmark, $meta)) {
                return [$bookmark, null];
            }

            $bookmark->setAttribute('meta', $meta);

            if (! $bookmark->save()) {
                throw self::cancelled('updated');
            }

            return [$bookmark, $bookmark->wasChanged() ? BookmarkUpdated::class : null];
        }

        throw new RuntimeException(sprintf(
            'The bookmark was deleted while it was being written, %d times in a row. Try again.', self::ATTEMPTS
        ));
    }

    /**
     * Insert the pair's bookmark. When the pair's unique index refuses it,
     * returns the row that holds the pair instead, read with a lock: null when
     * a concurrent call deleted that row in the meantime.
     *
     * @param  array{bookmarker_type: string, bookmarker_id: string, bookmarkable_type: string, bookmarkable_id: string}  $pair
     * @return array{0: Bookmark|null, 1: bool} the bookmark, and whether this call created it
     *
     * @throws QueryException when the insert fails for any other reason, such as another unique index
     */
    private static function insert(array $pair, stdClass $meta): array
    {
        $bookmark = self::newModel();
        $bookmark->forceFill($pair);
        $bookmark->setAttribute('meta', $meta);
        $connection = $bookmark->getConnection();

        try {
            // Inside a transaction, a savepoint keeps a refused insert from
            // aborting the transaction (PostgreSQL aborts it otherwise).
            $saved = $connection->transactionLevel() > 0
                ? $connection->transaction(function () use ($bookmark) {
                    return $bookmark->save();
                })
                : $bookmark->save();
        } catch (QueryException $exception) {
            if (self::isPairViolation($exception, $bookmark)) {
                return [self::find($pair, true), false];
            }

            throw $exception;
        }

        if (! $saved) {
            throw self::cancelled('created');
        }

        return [$bookmark, true];
    }

    /**
     * Delete the bookmark's row. True when this call deleted it.
     *
     * A query-builder delete, so Eloquent's deleting and deleted model events do not run.
     */
    private static function delete(Bookmark $bookmark): bool
    {
        $deleted = self::unscoped($bookmark)->toBase()
            ->where($bookmark->getKeyName(), $bookmark->getKey())
            ->delete() > 0;

        if ($deleted) {
            $bookmark->exists = false;
        }

        return $deleted;
    }

    /**
     * Whether the bookmark already holds this metadata. A stored value that is
     * not a JSON object holds nothing, so new metadata replaces it.
     */
    private static function holds(Bookmark $bookmark, stdClass $meta): bool
    {
        try {
            $stored = $bookmark->getAttribute('meta');
        } catch (UnexpectedValueException) {
            return false;
        }

        return $stored !== null && MetaNormalizer::equals(MetaNormalizer::normalize($stored), $meta);
    }

    /**
     * The pair's bookmark, read from the write connection so a lagging replica
     * cannot hide a row the statement before just wrote or kept.
     *
     * With $lock, inside a transaction, the read locks the row for update: on
     * MySQL (REPEATABLE READ) a plain read returns the transaction's snapshot,
     * which misses a row another request committed since; a locking read returns
     * it, and keeps it from being deleted before this transaction ends.
     *
     * @param  array{bookmarker_type: string, bookmarker_id: string, bookmarkable_type: string, bookmarkable_id: string}  $pair
     */
    private static function find(array $pair, bool $lock = false): ?Bookmark
    {
        $query = self::unscoped()->where($pair);
        $query->getQuery()->useWritePdo();

        if ($lock && $query->getQuery()->getConnection()->transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        $bookmark = $query->first();

        return $bookmark instanceof Bookmark ? $bookmark : null;
    }

    /**
     * A query on the bookmarks table without the model's global scopes, which
     * must not hide the row that holds a pair.
     */
    private static function unscoped(?Bookmark $bookmark = null): Builder
    {
        $bookmark = $bookmark ?? self::newModel();

        return (new Builder($bookmark->getConnection()->query()))->setModel($bookmark);
    }

    private static function cancelled(string $action): RuntimeException
    {
        return new RuntimeException(sprintf(
            'A model event cancelled saving the bookmark, so it was not %s.', $action
        ));
    }

    /**
     * The four columns that identify the bookmark of a pair.
     *
     * @return array{bookmarker_type: string, bookmarker_id: string, bookmarkable_type: string, bookmarkable_id: string}
     */
    private static function pair(Model $bookmarker, Model $bookmarkable): array
    {
        [$bookmarkerType, $bookmarkerId] = self::endpoint($bookmarker, 'bookmarker');
        [$bookmarkableType, $bookmarkableId] = self::endpoint($bookmarkable, 'bookmarkable');

        return [
            'bookmarker_type' => $bookmarkerType,
            'bookmarker_id' => $bookmarkerId,
            'bookmarkable_type' => $bookmarkableType,
            'bookmarkable_id' => $bookmarkableId,
        ];
    }

    /**
     * The morph type and key the bookmarks table stores for the model.
     *
     * @return array{0: string, 1: string}
     *
     * @throws InvalidArgumentException when the model is unsaved, or its type or key does not fit
     */
    private static function endpoint(Model $model, string $side): array
    {
        if (! $model->exists) {
            throw new InvalidArgumentException(sprintf(
                'The %s [%s] is not saved: save it before it bookmarks or is bookmarked.', $side, get_class($model)
            ));
        }

        $key = self::key($model);

        if ($key === null) {
            throw new InvalidArgumentException(sprintf(
                'The %s [%s] has no usable primary key.', $side, get_class($model)
            ));
        }

        if (mb_strlen($key) > self::KEY_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'The key of the %s [%s] is %d characters long; the bookmarks table stores keys of up to %d characters.',
                $side, get_class($model), mb_strlen($key), self::KEY_LENGTH
            ));
        }

        $type = $model->getMorphClass();

        if (mb_strlen($type) > self::TYPE_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'The morph type of the %s [%s] is %d characters long; the bookmarks table stores types of up to %d characters. '
                .'Register a shorter alias with Relation::morphMap().',
                $side, get_class($model), mb_strlen($type), self::TYPE_LENGTH
            ));
        }

        return [$type, $key];
    }

    /**
     * Whether the model is saved and has a usable key.
     */
    private static function isPersisted(Model $model): bool
    {
        return $model->exists && self::key($model) !== null;
    }

    /**
     * The model key as the string the bookmarks table stores, or null when it has none.
     */
    private static function key(Model $model): ?string
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key) && ! $key instanceof Stringable) {
            return null;
        }

        $key = (string) $key;

        return $key === '' ? null : $key;
    }
}
