# Drupal.org Project Page Draft

**Status:** Draft for Community alpha. Do not use for Marketplace listing without a final copy/proposal review, and do not make rich-result claims until external schema.org validation has run.

## Project Tagline (short description, ~255 char limit)

> Adds schema.org JSON-LD to GEO Starter pages so answer engines and AI crawlers can read the services, FAQs, and cited sources your content already shows. Emits only what the visible page supports. Companion module to the GEO Starter recipe.

(240 characters. "FAQs" is used rather than "answers" so the line does not imply the `Answer` content type, which this module does not emit yet.)

## Summary

Drupal is the CMS for an age of agents. GEO Starter JSON-LD is the companion module that turns GEO Starter's governed content into schema.org JSON-LD, so answer engines and AI crawlers can read what your pages already show.

## Description

Drupal is the CMS for an age of agents.

A governed content model only helps machines if they can read it. GEO Starter JSON-LD builds that reading surface: one schema.org graph per page, emitted only on the full canonical view of a published node, and only for what the page actually renders.

The structured data never claims more than the visible page shows: if a fact is not rendered, it is not emitted. Invalid or inflated structured data is worse than none, so when in doubt the module emits nothing.

This is the companion module for the GEO Starter recipe. It reads GEO Starter's content model. It is not a general-purpose JSON-LD tool.

## What It Emits

- Service pages emit a `Service` object plus the page's `WebPage`, carrying the service summary, primary action, topic, audience, review date, and cited sources.
- Answer pages emit a `Question` with its accepted answer: the page's title is the question, and the direct-answer field is the answer, with cited sources.
- Article pages emit an `Article` with headline, summary, author, reviewer, dates, and cited sources.
- Evidence Source pages emit a `CreativeWork` with the external source link and publisher. This is what makes a citation on another page resolve to a real, inspectable source.
- FAQ sections emit a `FAQPage`, but only when the section has at least two reviewed question-and-answer pairs. Thin or empty FAQs emit nothing.
- Citations between pages resolve by stable `@id`. If a cited source is unpublished, its citation is dropped, not left dangling.

## Emission Rules

Structured data is only as good as its honesty, so the module enforces these rules:

- It emits only on a published node's own full page. Teasers, search results, and editor previews emit nothing.
- It emits only fields the page actually renders, so the JSON-LD cannot exceed the visible content.
- It strips markup, encodes safely, and emits nothing at all on any error.
- It carries full cache metadata, including every cited source, so the structured data refreshes when the node or a source it cites changes.

## Relationship To GEO Starter

- It is required by the GEO Starter recipe and installed automatically with it.
- It reads GEO Starter's fields and Paragraph types. On a site without that content model, it has nothing to emit.
- It ships as its own Composer package because a Drupal recipe cannot carry a module on disk. The recipe requires it; you do not install it separately.

## Scope And Honest Limits

- Implemented and validated on a fresh install: `Service`, `Answer`, `Article`, `Evidence Source`, and the gated `FAQPage`.
- Not yet built: `HowTo`, contact-point, and item-list emission.
- No PHPUnit test suite yet. The slice is validated with a repeatable probe script (`tools/jsonld-probe.php`).
- External schema.org and Rich Results validation has not been run. Do not make public rich-result claims until it has.
- No guaranteed AI citations, rankings, rich results, or answer-engine placement. The module makes content inspectable. It does not promise outcomes.

## Requirements

- The GEO Starter recipe, or a site that uses its content model.
- Drupal 11 / Drupal CMS.
- Core only. No contrib dependencies and no patches.

## Documentation And Support

- `README.md` for design and settings.
- Use the Drupal.org issue queue for Community alpha support.
