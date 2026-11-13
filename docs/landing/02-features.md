---
view: components.packages.features
variant: compact
badge: Key Features
title: Bookmarks that carry their own data
subtitle: One method writes them, native Eloquent relations read them.
items:
  - icon: document
    title: JSON-Object Metadata
    text: 'Store a note, a priority or a whole settings object on each bookmark, and read it back as `$bookmark->meta->preferences->notify`.'
  - icon: switch
    title: Replace or Keep, Never Merge
    text: '`bookmark($post, $meta)` replaces the whole object, `bookmark($post)` keeps it, and `bookmark($post, [])` empties it.'
  - icon: check-circle
    title: No Write When Nothing Changed
    text: Metadata equal to what is stored, in any key order, runs no UPDATE, keeps `updated_at`, and fires no event.
  - icon: lock
    title: Unique by the Database
    text: A unique index keeps each bookmarker and bookmarkable pair to one row, and concurrent writes replace rather than merge.
  - icon: chart
    title: Native Queries
    text: '`whereBookmarkedBy($user)`, `withBookmarkedBy($user)`, `withCount(''bookmarks'')` and JSON `where(''meta->priority'', 5)` run in SQL.'
  - icon: tag
    title: Any Model, Any Key
    text: Users, teams or bots bookmark posts, products or each other, with integer, UUID and ULID keys in one table.
---
