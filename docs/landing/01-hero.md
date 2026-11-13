---
view: components.packages.package-hero
badges:
  - label: Laravel Package
    color: blue
  - label: "{package.latestVersionLabel}"
    color: green
  - label: "{package.license} License"
    color: purple
  - label: "{package.downloadsShort}+ downloads"
    color: blue
eyebrow: "{package.name}"
title: Bookmarks that remember
highlight: why they were saved
buttons:
  - label: View Full Documentation
    href: "{card.docsUrl}"
    style: primary
    icon: arrow-right
  - label: View on GitHub
    href: "{package.githubUrl}"
    style: ghost
    external: true
install: "{card.install}"
labels:
  copy: Copy
  copied: Copied!
---

Add `CanBookmark` to the model that bookmarks and `HasBookmarks` to the model that is bookmarked, run one migration, and write `$user->bookmark($post, ['note' => 'Read later'])`. Each bookmark carries a JSON object you read as `$bookmark->meta->note`, and a unique index keeps each pair to one row, even when two requests arrive at once.
