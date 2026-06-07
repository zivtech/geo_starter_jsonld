# Changelog

All notable changes to drupal/geo_starter_jsonld are documented here.

## 1.0.0-beta1 - 2026-06-07

First beta. Enters the stability contract (README → "Stability contract"):
within 1.x the top-level entity-type set, the `@id` scheme, and the
visible-HTML parity rule are frozen; nested helper sub-objects and intra-graph
property placement may still be corrected for schema.org/rich-result validity,
each with a release note + regression test.

- **Dropped the rating-less `Review` object** (provenance emission). The
  Service/Answer/Article graph emitted both `reviewedBy` (Person) and a paired
  `review` → `Review` with no `reviewRating`; Google's Rich Results Test flags
  that as an **invalid Review snippet** on every governed page (valid schema,
  invalid rich result — validator.schema.org passed it). `reviewedBy` plus the
  `dateModified` each normalizer already emits on its primary entity fully
  carry the provenance intent, so the `Review` is dropped at its single source
  (`JsonLdFieldTrait::schemaReviewedBy()`). Guarded by a graph-level regression
  assertion in `ReviewedByPlacementTest` (no node emits a `review` property).
  Permitted under contract rule 2 (nested sub-object correction). Re-validated:
  validator.schema.org 0/0 on all five snippets, offline domain check 0/0 on
  all four node types, probe 23/23.
- New `geo_starter_jsonld_markup` submodule (WS-B rendering pass): semantic,
  lightly-styled visible-HTML templates for the recipe's ten section paragraph
  bundles — h2/h3 heading hierarchy, open `<dl>` FAQ (no collapse, per the
  "no hidden claims" parity rule), ordered `<ol>` steps, `<address>` contact
  panel, severity-accented alert (`role="note"`), button-styled CTA, card
  grid, and media/text two-column layout. One lazily-attached ~6 KB CSS
  library consumes Mercury design tokens with hard fallbacks (no
  Tailwind-utility dependence; usable under any theme).
- Registration via `hook_theme()` with `'base hook' => 'paragraph'` — module
  template suggestion files are NOT auto-discovered (theme-only mechanism);
  the `.module` ships exactly this one hook, zero preprocess, zero JS.
- Independently installable/uninstallable: emission-only installs of
  `geo_starter_jsonld` are unaffected; uninstalling the submodule reverts to
  core's classless rendering. Parity guard re-proven: `tools/jsonld-probe.php`
  23/23 after all template work.
- New kernel test `SectionMarkupTest` (36 assertions) guards the
  `hook_theme()` registration through the real view-builder render path.
- phpcs (Drupal, DrupalPractice): clean.

## 1.0.0-alpha3 - 2026-06-02

- Emit schema.org `hoursAvailable` (`OpeningHoursSpecification`) for a Service's
  contact hours when the recipe ships a structured `office_hours` field on the
  contact panel. Hours nest under the provider `ContactPoint`, or fall back to
  the `Service` itself when no phone/email channel is present.
- No runtime dependency on `office_hours`: the field's columns are read
  defensively (`office_hours` is a `require-dev`/test-only dependency), and the
  module emits nothing when the field is absent or not office_hours-shaped.
- Conservative time handling — a midnight close becomes `23:59`, and overnight
  slots are omitted (schema.org cannot express a cross-midnight span as one
  `OpeningHoursSpecification`).
- Backward-compatible with the recipe's previous free-text hours field.
- Requires `drupal/geo_starter` 1.0.0-alpha4+, which ships the office_hours field.

## 1.0.0-alpha2 - 2026-06-02

- Added the Answer-as-`Question`, `Article`, `HowTo` (from `section_step_list`),
  `ItemList` (from `section_card_grid`), and nested `ContactPoint` /
  `PostalAddress` (from `section_contact_panel`) emitters, joining the alpha1
  Service / Evidence Source / FAQPage set.
- schema.org domain correctness: `reviewedBy` / `review` are WebPage-domain-only,
  so they route to the `WebPage` for every bundle rather than the primary entity;
  Service-invalid page metadata (`about`, `citation`, `dateModified`) likewise
  moves to the `WebPage`.
- Added the Unit + Kernel + Functional test suites and Drupal.org GitLab CI, plus
  regression guards: `ReviewedByPlacementTest` and an offline schema.org domain
  checker (`tools/schema-domain-check.py`).

## 1.0.0-alpha1 - 2026-05-30

- Initial release. Per-page schema.org JSON-LD, emitted only on the full
  canonical view of a published node: a `Service` with a `WebPage` spine,
  `CreativeWork` for Evidence Sources (the citation-resolution target), and a
  gated `FAQPage` from `section_faq`. Tagged-service architecture (per-bundle
  normalizers + paragraph contributors), full cache metadata, fail-closed JSON
  encoding.
