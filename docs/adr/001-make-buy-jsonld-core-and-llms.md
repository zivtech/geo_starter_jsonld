# ADR 001: Make vs. Buy — JSON-LD Core and llms.txt Submodule

## Status

Accepted

Retroactive — both decisions were implicit at implementation time (alpha1 shipped
2026-05-30; llms submodule landed in 1.1.0, 2026-06-11). This ADR records the
reasoning and establishes explicit reopen conditions.

**Correction (2026-06-13):** A code-level review of the three JSON-LD modules
(repos cloned and read) corrected this ADR's schemadotorg reasoning and removed a
factual error — a cited `ai_schemadotorg_jsonld` submodule that does not exist.
The *decision* (hand-roll) is unchanged; the *justification* is now render-parity
+ dependency risk, not content-model ownership, and the reopen trigger was
rewritten to be module-agnostic. Changed sections below: Decision 1
(schemadotorg), Evidence, Data Confidence, and the reopen conditions.

## Context

`geo_starter_jsonld` is a zero-config companion to the `geo_starter` recipe. It
ships no content model of its own — it reads the recipe's four frozen bundles
(service, article, answer, evidence_source). This posture creates distinctive
constraints for any JSON-LD or site-index solution.

**Recipe-companion posture.** Because the recipe owns the content model, any
solution that wants to own or reshape it is architecturally incompatible. Adopting
a schema-first content modeling tool would mean either abandoning the recipe's
bundle structure or maintaining a complex mapping layer between the recipe's model
and the tool's schema-derived model. The zero-config orientation would be lost.

**Render-parity rule.** The module's core correctness invariant is the parity
rule — never emit beyond the visibly rendered HTML, gated on the view display. It
is not a preference; it is the stability contract (`README.md` "Stability contract",
rule 1). Solutions that emit from config or tokens rather than from the actual
render path cannot satisfy it without significant adaptation.

**Paragraph-derived @graph composition.** JSON-LD properties are composed from
paragraph contributors (tagged service `geo_starter_jsonld.paragraph_contributor`),
not from node fields alone. The graph for a Service page aggregates contributions
from `section_contact_panel`, `section_faq`, `section_step_list`, and
`section_card_grid` paragraphs. Solutions that work at the node-field level miss
this composition layer.

**Schema correctness maintenance cost, already visible.** Two correctness fixes
shipped before stable: alpha2 relocated `reviewedBy`/`about`/`citation`/
`dateModified` to the `WebPage`; beta1 dropped a rating-less `Review` that
Google's Rich Results Test flagged. This is evidence that owning schema correctness
has real and continuing maintenance cost — a concrete factor in the build-vs-buy
calculus.

**llms.txt design constraints.** The submodule's parity invariant is access, not
display: it lists only pages the requesting user could fetch. The four bundles map
to semantically distinct sections — Services, Articles, Answers, and the
spec-literal `## Optional` heading for Evidence Sources (secondary citation
targets that agents may skip for shorter context). This section-to-bundle mapping
carries deliberate semantic meaning that no generic content-type-to-section
approach would produce without configuration.

## Decision

Two decisions, stated together because they share the same architectural root.

**Decision 1 — JSON-LD core: hand-roll over schemadotorg, schema_metatag, and json_ld_schema.**

We built `JsonLdGraphBuilder`, per-bundle normalizers, and paragraph contributors
from scratch rather than building on any existing JSON-LD contrib.

Three alternatives were evaluated:

*schemadotorg (Schema.org Blueprints):* Schema-first content modeling, JSON-LD
output, JSON:API integration. **Eliminated — but the original reasoning was wrong
(2026-06-13 code-level review of `1.0.0-alpha37`; see Correction above).** The
claim that it "wants to own the content model" is false: its `SchemaDotOrgMapping`
config entity attaches to *pre-existing* bundles and its JSON-LD builder is a
mapping-driven consumer, so the recipe's frozen bundles could be kept. It also
already ships two of this module's three signature patterns — the WebPage-spine
(`schemadotorg_additional_mappings` wraps the primary entity as a `WebPage`'s
`mainEntity`) and a paragraph-composition pipeline (`schemadotorg_paragraphs` /
`schemadotorg_layout_paragraphs`). The real, durable blockers are: (1)
**render-parity** — `SchemaDotOrgJsonLdBuilder::buildMappedEntity()` emits from
field *values* gated on field *access*, never on view-display placement, and it
fires on the moderation `latest-version` route, so it cannot satisfy the parity
rule without post-hoc filtering; and (2) **dependency risk** — alpha for ~4 years
with no stable release and no security-advisory coverage, so binding a production
correctness invariant to it is imprudent. The lost zero-config posture is a minor
factor; render-parity is the decisive one.

*schema_metatag (Schema.org Metatag):* Mature, token-driven JSON-LD via the
Metatag module. Eliminated because token resolution cannot satisfy the render-parity
rule: tokens resolve from field values at request time, not from the actual
rendered output of the view display. The parity guard — which gates emission on
whether the view mode is canonical and whether a paragraph is visible in the
rendered HTML — has no analog in token-driven emission.

*json_ld_schema (JSON LD Schema API):* Plugin-driven developer API for JSON-LD;
the closest architectural analog to the hand-roll. This deserved more explicit
evaluation than it received. The gap it does not close: it provides an API for
emitting JSON-LD but does not provide the paragraph-contributor composition layer,
the render-parity gating, or the WebPage-spine pattern this module uses. Building
those on top of `json_ld_schema` would add an API dependency with a thin benefit —
the hand-roll is already a clean tagged-service architecture with the same
plugin-like extensibility. The dependency cost exceeds the gain. If `json_ld_schema`
gains an active ecosystem of schema-correctness plugins, the calculus changes (see
Consequences — reopen condition).

The hand-roll is defensible given these constraints. The correctness-maintenance
cost is real; the tradeoff is that a solution satisfying the parity rule, paragraph
composition, and zero-config posture cannot outsource correctness to a
general-purpose library without substantial adaptation.

**Decision 2 — llms.txt submodule: hand-roll over contrib generators.**

We built `LlmsTxtBuilder` and `LlmsTxtDocument` rather than depending on any of
the five contrib llms.txt modules available at the time of implementation.

Five alternatives were evaluated against the design constraints stated in Context:

| Module | Approach | Gap |
|---|---|---|
| `llms_txt_exporter` | Export-on-demand | No auto-generated route; requires editorial action |
| `llms_txt_gen` | Published nodes by content type, sections from content type | Closest analog — "sections from published nodes by content type" matches the per-bundle section structure. Lacks: curated bundle ordering, `## Optional` heading semantics, access-parity invariant, and field-level description sourcing mirroring the JSON-LD normalizers |
| `llms_txt_generator` | Template-based generation | Project page exists; no evidence of active maintenance found at evaluation time |
| `llms_txt_ai` | AI-assisted generation | Wrong direction: the module's output is deterministic from governed field values, not AI-generated |
| `group_llms_txt` | Group-scoped llms.txt | Group entity scope; this module operates at the site level |
| Pronovix `llms_txt` | Broad, manually-curated feature set | Manual curation is the opposite of the zero-config posture; registers the same `/llms.txt` route (Drupal does not error on duplicate route paths — one silently wins, so co-enabling is unsupported, as documented in `README.md`) |

The llms.txt defense is weaker than the JSON-LD defense. `llms_txt_gen`'s
per-content-type sections are functionally close; the delta that justifies the
hand-roll is the `## Optional` semantic placement of Evidence Sources and the
access-parity invariant (listing only what the requesting user can fetch). These
are small but load-bearing for the recipe-companion contract.

The hand-roll is the right call for 1.1.0. The reopen conditions are narrow and
explicit — see Consequences.

## Evidence

All contrib evaluations below are search-level (project-page) assessments, not
code-level reviews. See Data Confidence for the implications of that scope.

*JSON-LD core:*

- `schemadotorg` architecture — **code-level review (2026-06-13)** of
  `1.0.0-alpha37`: `SchemaDotOrgMapping` (`src/Entity/SchemaDotOrgMapping.php`)
  maps existing bundles; `SchemaDotOrgJsonLdBuilder::buildMappedEntity()` emits
  from field values gated on field access (no view-display gating);
  `schemadotorg_additional_mappings` implements the WebPage `mainEntity` spine;
  `schemadotorg_paragraphs` / `schemadotorg_layout_paragraphs` provide paragraph
  composition. **Correction:** the `ai_schemadotorg_jsonld` submodule cited in the
  original draft does not exist in the project.
- `schema_metatag` token-driven emission: drupal.org/project/schema_metatag —
  token resolution model is documented in the project README; token resolution is
  request-time field-value projection, not render-path output.
- `json_ld_schema` plugin API: drupal.org/project/json_ld_schema — provides a
  service and plugin system for emitting JSON-LD; no paragraph-composition or
  render-parity layer.
- Schema correctness maintenance cost evidence: `CHANGELOG.md` — alpha2 property
  relocations (reviewedBy, about, citation, dateModified routed to WebPage), beta1
  rating-less Review drop; `docs/SCHEMA-VALIDATION-2026-06-01.md` — the pre-fix
  diagnosis and full-surface re-validation record.
- Render-parity rule: `README.md` "Stability contract" rule 1;
  `geo_starter_jsonld.module` view-mode/canonical/preview guards in
  `hook_node_view_alter()`.
- Paragraph contributor architecture: `geo_starter_jsonld.services.yml`
  tagged-service registration; `src/Contributor/` (CardGridContributor,
  FaqContributor, StepListContributor).

*llms.txt submodule:*

- `llms_txt_gen` — drupal.org/project/llms_txt_gen — content-type-to-section
  approach without `## Optional` semantics.
- `llms_txt_exporter` — drupal.org/project/llms_txt_exporter.
- `llms_txt_generator` — drupal.org/project/llms_txt_generator — project page
  exists; no evidence of active maintenance found at evaluation time.
- `llms_txt_ai` — drupal.org/project/llms_txt_ai.
- `group_llms_txt` — drupal.org/project/group_llms_txt.
- Pronovix `llms_txt` — drupal.org/project/llms_txt — route conflict documented
  in `README.md` "Coexistence with contrib `llms_txt`".
- `## Optional` semantic: llmstxt.org spec — the heading is the one
  machine-actionable semantic in the format. Verified in
  `LlmsTxtBuilder.php:53` where `'title' => 'Optional'` is set for the
  `evidence_source` bundle; the document renderer emits it as `## Optional`.
- Access-parity invariant: `modules/geo_starter_jsonld_llms/src/LlmsTxtBuilder.php`,
  `sectionEntries()` — `accessCheck(TRUE)` and
  `condition('status', NodeInterface::PUBLISHED)`.
- `CHANGELOG.md` 1.1.0 entry: the submodule release note documents that the
  module does not depend on or integrate with contrib `llms_txt`.

## Data Confidence

JSON-LD core: **High** for schema_metatag elimination — token-vs-render-path
emission is structural, confirmed at code level (no `hook_entity_view`, no
view-display awareness). **schemadotorg:** the original "content-model ownership"
reasoning was wrong (see Correction — 2026-06-13 code-level review); elimination
now rests on render-parity (field-value/access-gated emission, which also fires on
the moderation route) and alpha/no-SA dependency risk. **json_ld_schema:**
code-level review confirms the original conclusion — zero shipped plugins (all are
test fixtures), feature-complete and minimally maintained, no schema-correctness
ecosystem; the reopen condition is further from being met, not closer.

llms.txt submodule: **Medium.** Five modules evaluated at project-page level only;
no code-level review of `llms_txt_gen` was performed. The `## Optional` semantic
gap and access-parity gap are claims about what `llms_txt_gen` does NOT do,
inferred from its documented "sections from published nodes by content type"
approach. If `llms_txt_gen` has added access-checking or per-section semantic
configuration since this evaluation, the reopen condition below may already be met.

## Consequences

*Ongoing:*

- Schema correctness maintenance is owned by this module. Every schema.org property
  placement decision is ours to defend, fix, and test. The
  `tools/schema-domain-check.py` + `ReviewedByPlacementTest` harness is the
  mitigation; it must be run on every release.
- The llms submodule's hand-rolled markdown grammar (`LlmsTxtDocument`) is owned
  code: backslash escaping, truncation, section omission, URL wrapping. Spec drift
  in llmstxt.org would require a code change here.

*Reopen conditions — JSON-LD core:*

- **Module-agnostic parity trigger (the real one):** if any contrib module emits
  JSON-LD **from the rendered view display** — gating on field placement and
  paragraph visibility, the way this module's parity rule requires — **and**
  reaches a stable release with security-advisory coverage, re-evaluate adopting
  it. As of 2026-06-13 none does: schemadotorg ships the WebPage-spine and a
  paragraph pipeline but emits from field values (not the render path) and is
  alpha/no-SA; json_ld_schema is a bare framework with no parity logic. The bar is
  render-from-display **and** stable **and** SA-covered.
- If `json_ld_schema` specifically lands an active ecosystem of schema-correctness
  plugins covering the WebPage-spine, paragraph composition, or parity-gating
  patterns, the original narrower trigger applies as well.
- If a third normalizer type (beyond node and paragraph) appears, or if a
  non-recipe site profile wants to adopt this module: re-evaluate whether a
  general-purpose JSON-LD API reduces integration cost.

*Reopen conditions — llms.txt submodule (contrib-alignment assessment):*

The llms defense is weaker than the JSON-LD defense. These are the specific,
measurable trigger conditions under which adopting or depending on a contrib module
is the correct call rather than building further:

**Trigger 1 — Feature parity gap closes in contrib:** If `llms_txt_gen` (or any
successor) adds (a) per-content-type section ordering, (b) a way to designate one
section as the spec-literal `## Optional` heading, AND (c) access-parity gating
(list only what the requesting user can fetch) — evaluate replacing `LlmsTxtBuilder`
with a thin recipe-specific config layer over contrib.

**Trigger 2 — New feature requested that contrib already covers:** If a site
operator requests a feature (e.g., pagination, manual curation, AI-assisted
descriptions, group-scoped output) that one of the five evaluated contrib modules
already provides — adopt that module rather than building the feature here. Do not
build features that put this submodule further into the same space as a maintained
contrib alternative.

**Trigger 3 — Maintenance burden exceeds recipe-companion value:** If the llms
submodule accumulates more than three independently-requested features that are not
direct projections of the four governed bundles' governed fields — treat that as a
signal that the zero-config recipe-companion scope has been exceeded, and
re-evaluate against `llms_txt_gen` or a depend-on posture.

**Current recommendation (as of 1.1.0):** No action. The submodule is lean (two
classes, three test files), correct, and within scope. Watch Triggers 1–3; do not
accrete features preemptively.
