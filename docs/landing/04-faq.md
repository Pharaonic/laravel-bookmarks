---
view: components.home.faq
badge: FAQ
title: "{package.name}"
highlight: Questions
subtitle: "Quick answers about installing and using {package.name}."
---

## What is {package.name}?

{card.description} It's a free, open-source {technology.name} package by Pharaonic.

## How do I install {package.name}?

Run `composer require {package.composer}` in your project's root directory, then `php artisan migrate`.

## What does {package.name} require?

The latest release requires {package.requiresText}.

## Does bookmarking again merge the metadata?

No. New metadata replaces the whole object, so a key you leave out is gone. Call `bookmark()` without metadata to keep what is stored, or pass `[]` to empty it.

## What can the metadata hold?

A JSON object: strings, numbers, booleans, null, lists and nested objects. A list, a string or an object such as a Collection at the top is refused before anything is written.

## What happens when the same user bookmarks twice at the same moment?

The table keeps one row for the pair. Both calls return that row, and the metadata ends up as one call's object, whole, never a mix of the two.

## Is {package.name} free to use?

Yes. {package.name} is open source under the {package.license} license, so you can use it in personal and commercial projects.

## Where can I find the {package.name} documentation?

Read the [{package.name} documentation]({package.docsUrl}) for setup, configuration, and usage examples.

## How do I report a bug or contribute to {package.name}?

Open an issue or a pull request on [GitHub]({package.githubUrl}), or ask in the [Pharaonic Discord](https://discord.gg/XQG9RhvEvf).
