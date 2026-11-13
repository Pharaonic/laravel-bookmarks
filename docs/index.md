---
name: Bookmarks

action:
  label: View on Packagist
  href: "{package.packagistUrl}"

views: components.packages

breadcrumbs:
  - label: Home
    href: route:home
  - label: Packages
    href: route:packages.index
  - label: "{technology.name} Packages"
    href: "url:/packages/{technology.slug}"
  - label: "{package.name}"

card:
  topic: database
  icon: tag
  tags: bookmarks bookmark save saved items read later favorites metadata json eloquent polymorphic morph users posts uuid ulid soft deletes events unique index
  description: "Let any Eloquent model bookmark any other, with JSON-object metadata on each bookmark: replace or keep it in one call, read it through an immutable value object, and rely on a unique index that keeps each pair to one row."

seo:
  title: "{package.fullName} - Polymorphic Bookmarks with Metadata for Eloquent"
  description: "{package.name} adds bookmarks between any two Laravel Eloquent models, each with JSON-object metadata: replace-or-keep writes, an immutable metadata object, database-enforced uniqueness, native relations, UUID and ULID keys."
  keywords: laravel bookmarks, laravel bookmark package, eloquent bookmarks, laravel polymorphic bookmarks, laravel saved items, laravel read later, laravel favorites with metadata, laravel bookmark notes, laravel morph bookmarks, laravel json metadata
  author: Pharaonic
  images:
    - "{package.cover}"
  openGraph:
    type: website
    siteName: Pharaonic
  twitter:
    card: summary_large_image

schema:
  "@type": SoftwareSourceCode
  name: "{package.name}"
  description: "{package.name} is a Laravel package that lets any Eloquent model bookmark any other, with JSON-object metadata per bookmark, a unique index per pair, native relations, events, and cleanup when a model is deleted."
  keywords: laravel, eloquent, bookmarks, metadata, json, polymorphic relations, uuid, ulid, soft deletes, events
  image: "{package.cover}"
  codeRepository: "{package.githubUrl}"
  programmingLanguage: PHP
  runtimePlatform: "{technology.name}"
  version: "{package.version}"
  datePublished: "{package.publishedAt}"
  dateModified: "{package.updatedAt}"
  license: "https://opensource.org/licenses/{package.license}"
  isAccessibleForFree: true
  sameAs:
    - "{package.githubUrl}"
    - "{package.packagistUrl}"
  author:
    "@id": url:/#organization
  publisher:
    "@id": url:/#organization
  interactionStatistic:
    "@type": InteractionCounter
    interactionType: https://schema.org/DownloadAction
    userInteractionCount: "{package.downloads}"
---
