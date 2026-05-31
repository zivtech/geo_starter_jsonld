# GEO Starter JSON-LD

Emits parity-correct schema.org JSON-LD in the `<head>` of rendered node pages
so [GEO Starter](https://www.drupal.org/project/geo_starter)'s governed content
is machine-inspectable by retrieval systems and answer engines.

This is the companion module for the `drupal/geo_starter` recipe. A Drupal 11
recipe is a configuration artifact and cannot ship a module on disk, so this
ships as its own Composer package that the recipe requires.

## What it emits

One schema.org `@graph` per page, attached as a single
`<script type="application/ld+json">`, **only on the full canonical view of a
published node**. The graph never exceeds the visible rendered HTML.

- **Service** → `Service` + `WebPage` (name, description, potentialAction,
  about, audience, dateModified, citation, provider).
- **Evidence Source** → `CreativeWork` at `{url}#evidence-source` (url/sameAs +
  publisher) — the resolution target for citations.
- **`section_faq`** → a gated `FAQPage`, emitted only when at least two
  `section_faq_item` children have a non-empty question and answer.

Cross-node `citation` references resolve by `@id`: a citing node points at
`{evidence_url}#evidence-source`, where the Evidence Source page declares the
full `CreativeWork`. Unpublished targets are suppressed (no dangling `@id`).

## Design

- `hook_node_view_alter()` is thin: it applies the view-mode/canonical/preview
  guards, calls `geo_starter_jsonld.graph_builder`, and attaches the result.
- `JsonLdGraphBuilder` orchestrates: published guard, base `WebPage`, per-bundle
  normalizers (tagged `geo_starter_jsonld.node_normalizer`), paragraph
  contributors (tagged `geo_starter_jsonld.paragraph_contributor`), cache
  metadata, and fail-closed JSON encoding.
- Add a new type by registering a tagged service — no plugin manager.

## What it does not do

No ranking, rich-result, or answer-engine-placement promises. No required AI
provider, no RDFa, no duplication of field content into conflicting
representations. It makes governed content inspectable; it promises no outcomes.

## Settings

`geo_starter_jsonld.settings` (simple config): `service_type`, `article_type`,
`faqpage_on_service` (default `true`), `faqpage_on_answer` (default `false`),
`emit_howto`, `organization_name` (falls back to the site name),
`evidence_default_type`.

## Acceptance probe

```
ddev drush scr web/modules/custom/geo_starter_jsonld/tools/jsonld-probe.php
```

## License

GPL-2.0-or-later.
