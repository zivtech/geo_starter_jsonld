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

- **Service** → a `Service` (name, description, potentialAction, audience, and a
  `provider` Organization that nests the `ContactPoint` and `PostalAddress`).
  Page-level metadata schema.org does not place on a Service — `about`,
  `citation`, `dateModified`, `reviewedBy` — is emitted on the `WebPage`.
- **Answer** → a `Question` with `acceptedAnswer` (the page title is the
  question; `field_direct_answer` is the canonical answer), plus `about` and
  citations; `reviewedBy` is on the `WebPage`.
- **Article** → an `Article` (headline, description, author, about,
  dateModified, datePublished, citation); `reviewedBy` is on the `WebPage`.
  `author` and reviewer are distinct fields and are never conflated.
- **Evidence Source** → `CreativeWork` at `{url}#evidence-source` (url/sameAs +
  publisher) — the resolution target for citations.
- **`section_faq`** → a gated `FAQPage`, emitted only when at least two
  `section_faq_item` children have a non-empty question and answer.

Cross-node `citation` references resolve by `@id`: a citing node points at
`{evidence_url}#evidence-source`, where the Evidence Source page declares the
full `CreativeWork`. Unpublished targets are suppressed (no dangling `@id`).

Every page also emits a `WebPage` linked to the primary entity by `mainEntity`.
Properties schema.org does not scope to the primary type are emitted there
instead — notably `reviewedBy`, which is `WebPage`-domain-only and so is **never**
placed on the primary entity. `tools/schema-domain-check.py` and the acceptance
probe both guard that placement.

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

## Release validation

Before tagging a release, run all three — together they are the gate for the
"clean, machine-inspectable structured data" claim:

1. **PHPUnit** (also runs automatically in Drupal.org CI) — the Unit + Kernel +
   Functional suites, including `ReviewedByPlacementTest`, which asserts
   `reviewedBy` only ever lands on the `WebPage`.
2. **Acceptance probe** — full-surface fidelity against a recipe-installed site:
   ```
   ddev drush scr web/modules/custom/geo_starter_jsonld/tools/jsonld-probe.php
   ```
3. **schema.org domain check** — confirms every emitted property sits on a
   domain-valid type (0 errors / 0 warnings); the check behind
   `docs/SCHEMA-VALIDATION-2026-06-01.md`. The hosted validator.schema.org can't
   fetch a local site, so run it offline against the official vocabulary:
   ```
   # once: fetch the vocabulary
   curl -sL https://schema.org/version/latest/schemaorg-current-https.jsonld -o /tmp/schemaorg.jsonld
   # per published node — extract the @graph, then check it:
   curl -sk https://SITE/path | python3 -c "import sys,re,json;b=re.findall(r'<script type=\"application/ld\+json\">(.*?)</script>',sys.stdin.read(),re.S);print([x for x in b if '@graph' in x][0])" > /tmp/page.jsonld
   python3 tools/schema-domain-check.py /tmp/schemaorg.jsonld /tmp/page.jsonld
   ```
   Sweep **all** node types (Service, Article, Answer, Evidence Source), not one:
   the 2026-06-01 single-node validation missed an Answer-node violation that
   only an all-types sweep caught.

## License

GPL-2.0-or-later.
