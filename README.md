# GEO Starter JSON-LD

Emits parity-correct schema.org JSON-LD in the `<head>` of rendered node pages
so [GEO Starter](https://www.drupal.org/project/geo_starter)'s governed content
is machine-inspectable by retrieval systems and answer engines.

This is the companion module for the `drupal/geo_starter` recipe. A Drupal 11
recipe is a configuration artifact and cannot ship a module on disk, so this
ships as its own Composer package that the recipe requires.

## Stability contract

From `1.0.0-beta1`, within the `1.x` line:

1. **Frozen:** the top-level entity-type set (`Service`, `Question`/`Answer`,
   `Article`, `CreativeWork`, `WebPage`, `FAQPage`, `HowTo`, `ItemList`), the
   `@id` scheme (`{url}` for the `WebPage`, `{url}#evidence-source` for the
   `CreativeWork`), and the **parity rule** — never emit beyond the visibly
   rendered HTML. New top-level types may be added.
2. **Not frozen:** nested helper sub-objects (`Person`, `Organization`,
   `Audience`, `ContactPoint`, `PostalAddress`, `OpeningHoursSpecification`,
   `HowToStep`, `ListItem`, …) and intra-graph **property placement**.
   schema.org- and rich-result-correctness fixes may move, correct, or drop
   such properties within the `@graph`; every change ships with a release note
   and a regression test. (Precedents: alpha2 relocated
   `reviewedBy`/`about`/`citation`/`dateModified` to the `WebPage`; beta1
   dropped a rating-less `Review`.)
3. **Settings keys are stable;** new keys default to current behavior.

Releases are fresh-install-only — no in-place upgrade path ships (the
Drupal CMS site-template posture). See `CHANGELOG.md` for per-release notes.

## What it emits

One schema.org `@graph` per page, attached as a single
`<script type="application/ld+json">`, **only on the full canonical view of a
published node**. The graph never exceeds the visible rendered HTML.

- **Service** → a `Service` (name, description, potentialAction, audience, and a
  `provider` Organization that nests the `ContactPoint` and `PostalAddress`). When
  the contact panel carries a structured hours field, the `ContactPoint` also
  carries `hoursAvailable` (`OpeningHoursSpecification`); hours with no reachable
  channel fall back to `Service.hoursAvailable`. Page-level metadata schema.org
  does not place on a Service — `about`, `citation`, `dateModified`, `reviewedBy`
  — is emitted on the `WebPage`.
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
- Why this is hand-rolled rather than built on `schemadotorg`,
  `schema_metatag`, or `json_ld_schema` — and why the llms.txt submodule does
  not depend on the contrib generators — is recorded, with explicit reopen
  conditions, in
  [ADR 001](docs/adr/001-make-buy-jsonld-core-and-llms.md).

## What it does not do

No ranking, rich-result, or answer-engine-placement promises. No required AI
provider, no RDFa, no duplication of field content into conflicting
representations. (Hours are a case in point: the recipe renders them with
office_hours' table formatter and this module emits the `hoursAvailable`
JSON-LD, so office_hours' own `office_hours_schema_org` formatter stays off to
avoid double-emitting opening hours.) It makes governed content inspectable; it
promises no outcomes.

## llms.txt (submodule)

The `geo_starter_jsonld_llms` submodule serves a spec-conformant
[`/llms.txt`](https://llmstxt.org) — a markdown site index of your governed
content for LLM crawlers and agents. Where the JSON-LD answers "what is this
page", llms.txt answers "what does this site offer": every published,
anonymously-viewable Service, Article, Answer, and Evidence Source, as a
markdown link to the node's canonical URL with a one-line description drawn
from the same governed field the JSON-LD normalizers read (`field_summary`,
`field_direct_answer`, `field_publisher`). Auto-generated, no curation UI.

- Services, Articles, and Answers list under their own H2 sections; Evidence
  Sources list under the spec's literal `## Optional` section — the one
  heading llms.txt assigns machine semantics to (skippable when an agent
  needs shorter context), which is exactly the role of secondary
  citation-resolution targets.
- The parity invariant here is access, not display: the index only ever lists
  pages the requesting user could itself fetch (access-checked, published-only
  queries). Descriptions are the plain-text projection of the governed field,
  not a byte-for-byte mirror of the rendered page — an index is not a render.
- Fully page-cacheable for anonymous crawlers; the response invalidates when
  any listed node changes, bundle membership changes (add/remove/unpublish),
  or the site name/slogan/summary changes.

**Coexistence with contrib `llms_txt`:** Pronovix's
[`llms_txt`](https://www.drupal.org/project/llms_txt) module also registers a
route at `/llms.txt`. Drupal does not error on duplicate route paths — one
route silently wins, so co-enabling the two leaves which document gets served
effectively undefined. Pick one: this submodule for zero-config output over
the governed bundles, or `llms_txt` for its broader, manually-curated feature
set.

## Settings

`geo_starter_jsonld.settings` (simple config): `service_type`, `article_type`,
`faqpage_on_service` (default `true`), `faqpage_on_answer` (default `false`),
`emit_howto`, `organization_name` (falls back to the site name),
`evidence_default_type`.

`geo_starter_jsonld_llms.settings` (submodule, simple config): `site_summary`
— the llms.txt blockquote text; empty (the default) falls back to the site
slogan, then to a generic line built from the site name.

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
