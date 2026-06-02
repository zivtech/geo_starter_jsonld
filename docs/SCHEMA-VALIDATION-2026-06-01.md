# External schema.org validation — 2026-06-01

External validation of the emitted JSON-LD against the **schema.org Markup
Validator** (validator.schema.org), the gate flagged in
`[[geo-starter-jsonld-release-ordering]]` before any public rich-result claim.

> **STATUS: RESOLVED 2026-06-02.** All recommendations below were implemented and
> a full-surface re-validation now reports **0 errors / 0 warnings across all 21
> published nodes** (Service, Article, EvidenceSource, Answer). The analysis below
> is the pre-fix diagnosis; see **[Resolution — 2026-06-02](#resolution--2026-06-02)**
> at the end for what changed, one deliberate divergence, an additional bug the
> single-node pass could not see, and the (calibrated) method used.

## What was validated

- **Subject:** the emergency-assistance Service node (`/apply-emergency-food-and-utility-assistance`, nid 15) — the richest content node, exercising the most normalizers/contributors at once.
- **Module version:** local `main` (commit `603a759`, the 10-commit-ahead-of-`1.0.0-alpha1` tree), swapped into the geostarter-reval DDEV harness over the composer-installed alpha1.
- **Source:** the real emitted `<script type="application/ld+json">` from the live page (HTTP 200), not a fixture.
- **Emitted types:** one `@graph` with `WebPage`, `Service` (with nested `ContactPoint`), `FAQPage`, `HowTo`, `ItemList`.

## Result: 0 ERRORS · 8 WARNINGS

**No errors — the markup is structurally valid schema.org.** All 8 warnings are
"property not expected for type" advisories, all on the `Service` node and its
nested `ContactPoint`. Nothing on `WebPage`(own), `FAQPage`, `HowTo`, or `ItemList`.

| Node | Property | schema.org domain | Note |
|---|---|---|---|
| Service | `about` | CreativeWork, Event | Service ∉ domain |
| Service | `citation` (×2 — 2-item list) | CreativeWork | Service ∉ domain |
| Service | `dateModified` | CreativeWork, DataFeedItem | Service ∉ domain |
| Service | `reviewedBy` | CreativeWork | Service ∉ domain |
| Service | `contactPoint` | Organization | Service ∉ domain |
| ContactPoint | `address` | Person/Org/Place/GeoCoordinates | ContactPoint ∉ domain |
| ContactPoint | `openingHours` | LocalBusiness, CivicStructure | use `hoursAvailable` instead |

= 8 (the `citation` list counts twice).

## How to read it

These are **warnings, not errors** — schema.org marks nothing as required, and
permissive consumers (Google, LLMs) tolerate out-of-domain properties. They do
**not** block a release. But this module's whole thesis is clean,
machine-inspectable structured data for AI visibility, so domain-correct emission
is worth more here than on a typical site. Recommended before a public
"clean structured data" claim — **✅ all three implemented 2026-06-02, see
[Resolution](#resolution--2026-06-02):**

1. **Move the CreativeWork-domain props off `Service` onto `WebPage`.** `about`,
   `citation`, `dateModified`, `reviewedBy` are all page-level metadata, and the
   page already emits a `WebPage` node (a CreativeWork → domain-valid). Relocating
   them in `ServiceNormalizer` clears 5 warnings and is more semantically correct.
2. **`contactPoint`:** schema.org expects it on an `Organization`. Either nest the
   `ContactPoint` under `provider` (the Organization) instead of `Service`, or
   accept the advisory (Service→contactPoint is common in the wild). Nesting under
   `provider` clears 1 warning. Owner: the `contactPointFromSections` trait helper.
3. **`ContactPoint` shape:** replace `openingHours` (a string) with
   `hoursAvailable` (an `OpeningHoursSpecification`), and move `address` to the
   provider `Organization` (Organizations take `address`). Clears 2 warnings.

## Separate (NOT a schema.org issue): HowTo has no `name`

The `HowTo` node emits `step[]` but no `name`. schema.org doesn't flag this (0
warnings on HowTo), but **Google's HowTo structured data requires `name`**, and a
nameless HowTo is incomplete for LLM consumption. `StepListContributor` should map
the section_step_list paragraph's heading to `HowTo.name`. (Caveat: Google
**deprecated** HowTo rich results in 2023, and narrowed FAQ rich results to
gov/health authorities — so for this module the value is schema completeness / GEO
legibility, not a Google rich card. That's also why validator.schema.org, not
Google's Rich Results Test, was the right tool here.)

## Negative space (what this did NOT validate)

One node, the richest. It covered WebPage / Service / FAQPage / HowTo / ItemList /
ContactPoint. It did **not** exercise the `Article`, `Answer`, or `EvidenceSource`
(CreativeWork) normalizers — no Article/Question/EvidenceSource content sits on
this node. Validate a node that triggers those before any blanket
"all emission types are schema-clean" claim.

## Harness note

The geostarter-reval project's `geo_starter_jsonld` was swapped from the
composer-installed `1.0.0-alpha1` to local `main` for this run and left that way
(it now reflects current main). `ddev composer install` in that project reverts to
alpha1 if the clean published state is wanted back.

## Resolution — 2026-06-02

All recommendations above were implemented, and a full-surface re-validation
closed the negative space the 2026-06-01 pass left open. **Every published node
now emits schema.org-clean JSON-LD: 0 errors / 0 warnings across all 21 nodes**
(4 Service, 3 Article, 6 EvidenceSource, 8 Answer).

### What changed (module `main`, uncommitted working tree)

- **New page-level property channel.** `JsonLdContext` gained a `webPageProperties`
  bag + `addWebPageProperty()`; `JsonLdGraphBuilder` merges it onto the WebPage
  spine (spine keys win on collision; an empty bag is a no-op). A normalizer can
  now route a property to the WebPage without moving field-reading into the
  builder.
- **`ServiceNormalizer`.** `about`, `citation`, `dateModified`, and
  `reviewedBy` (+ its paired `review`) moved off `Service` (not a CreativeWork)
  onto the WebPage. `contactPoint` and `address` now nest under the **provider
  `Organization`** — their schema.org domain — not the Service or the
  ContactPoint. `audience` stays on Service (domain-valid).
- **`reviewedBy` is `WebPage`-domain-only** (verified live against schema.org;
  range Person/Organization) — *not* CreativeWork as the table above assumed. So
  the same routing was applied to **every** normalizer that emits it:
  `ArticleNormalizer` and `AnswerNormalizer` route `reviewedBy`+`review` to the
  WebPage too. Their genuinely CreativeWork-valid props
  (`about`/`citation`/`dateModified`) stay on the entity.
- **`StepListContributor`.** `HowTo.name` is now set from the step-list section
  heading (`field_section_heading`); an absent heading yields a nameless HowTo
  (parity-safe), not a fabricated one.

### One deliberate divergence from recommendation #3

It said replace `openingHours` with `hoursAvailable` (an
`OpeningHoursSpecification`). `field_section_hours` is a **free-text string**;
there is no structured source to build an `OpeningHoursSpecification`, and a
fabricated/partial one is a worse signal than none (the module's own rule). So
hours are **omitted** from JSON-LD — they remain in the visible HTML
(parity-safe). The faithful fix is a structured hours field in the content
model, which is out of scope for this cleanup.

> **Resolved 2026-06-02.** `field_section_hours` is now an `office_hours`
> (contrib) field in the `geo_starter` recipe, so the structured source exists.
> `OpeningHoursMapper` reads its columns and emits `hoursAvailable`
> (`OpeningHoursSpecification`) on the Service's `ContactPoint` — or on the
> Service itself when no reachable channel is present. See
> `docs/plans/2026-06-02-hoursavailable-via-office-hours.md`.

### A bug the single-node pass structurally could not see

The 2026-06-01 validation checked one node and explicitly did not exercise the
Answer normalizer. The full-surface sweep caught **`Question.reviewedBy` on all 8
Answer nodes** — the identical out-of-domain `reviewedBy` — now fixed. This is
exactly the negative space the prior doc flagged; closing it required validating
the whole emitted surface, not the richest single node.

### How it was validated (method note)

`validator.schema.org`'s inline-code submission could not be used: its API
fetch-mode wants a public URL, and this content sits on a local `.ddev.site`
host it cannot reach. The "property not expected for type" warnings are purely a
domain check, so it was reproduced locally against the **official**
`schemaorg-current-https.jsonld` vocabulary, walking each type's supertypes
(`subClassOf`) before testing `domainIncludes`. The checker is committed at
**`tools/schema-domain-check.py`** (run: `python3 tools/schema-domain-check.py
<vocab.jsonld> <emitted.jsonld>...`; the file's docstring has the fetch/extract
commands). It was **calibrated**:
run against the *pre-change* Service emission it reproduces the documented 8
warnings exactly (7 distinct properties, `citation`×2) with 0 on
WebPage/FAQPage/HowTo/ItemList — so its 0/0 on the new output is evidence, not a
self-report.

**Negative space of this method:** it reproduces only the domain class — which
is all 8 warnings that were cleared. It does not check value-type (range)
warnings; the original had none, and the new values (Person, Review,
ContactPoint, PostalAddress, Thing, Audience) introduce none.

### Verification

- **PHPUnit Unit+Kernel: 24 tests / 174 assertions, OK** (was 22/147; +2 tests
  pin the new bag-merge channel and that spine keys win on collision). The lone
  reported deprecation is `paragraphs_module_implements_alter` — paragraphs' own,
  out of scope.
- **Functional (BrowserTestBase): 3 / 32, OK** — exercises the real
  `ServiceNormalizer` through a rendered page. (The 1 FunctionalJavascript
  FAQ-authoring test was not re-run; it covers AJAX authoring untouched here.)
- **Live domain re-validation: 0 / 0 across all 21 published nodes.**

### Still open (recommended follow-ups, not done here)

- **Regression guards — added 2026-06-02 (was: none).** `ReviewedByPlacementTest`
  (Kernel) asserts `reviewedBy` only ever lands on the WebPage across the
  service/article/answer normalizers, and runs in the standard Drupal.org CI
  phpunit matrix — so the bug class that shipped as `Question.reviewedBy` is now
  guarded automatically. `tools/jsonld-probe.php` was repaired (it had asserted
  the pre-fix `Service.contactPoint`/`Service.citation` shape) and now carries
  inverse guards locking the relocation. The full-vocab
  `tools/schema-domain-check.py` cannot run in the module's standard CI — it needs
  rendered content, which lives in the `geo_starter` recipe, and Drupal.org CI
  does not serve a site — so by decision it is a documented **release gate** (see
  the README "Release validation" section), run via `drush scr` against a recipe
  site. It could optionally be gated in the recipe repo's pipeline later.
- ~~**Structured hours field** if `OpeningHoursSpecification` output is wanted.~~
  **Resolved 2026-06-02** — the recipe now ships an `office_hours` field and the
  module emits `hoursAvailable` (`OpeningHoursSpecification`). See
  `docs/plans/2026-06-02-hoursavailable-via-office-hours.md`.
