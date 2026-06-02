# Changelog

All notable changes to drupal/geo_starter_jsonld are documented here.

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
