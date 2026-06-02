# hoursAvailable via office_hours — Implementation Plan

> **For Claude:** Use drupal-planner protocol. Invoke drupal-critic at each checkpoint marked with review checkpoint.
> **Drupal Version:** 11 (CMS recipe ecosystem)
> **Companion skills:** test-driven-development, drupal-critic, drupal-coding-standards, executing-plans

> **POST-REVIEW CORRECTION (2026-06-02):** drupal-critic caught that this plan's
> `formatTime` spec and edge-case matrix only guarded a `2400` value that
> office_hours never *stores*. office_hours stores a midnight CLOSE as integer
> `0` (rendered "24:00") and an overnight slot as `endhours < starthours`. The
> shipped mapper therefore normalizes a `0` close to end-of-day (→ `23:59`,
> parity-safe vs. the "24:00" HTML) and DROPS overnight slots (schema.org cannot
> express a cross-midnight span as one `OpeningHoursSpecification`). A midnight
> OPEN (`starthours 0`) is preserved as `00:00`. Unit cases are pinned to these
> real stored shapes. The sections below predate the fix; the code + tests are
> authoritative.

**Feature:** Emit schema.org `hoursAvailable` (`OpeningHoursSpecification`) on Service JSON-LD, sourced from a structured `office_hours` field replacing the free-text string on `section_contact_panel`.
**Risk Level:** Medium (cross-repo field-type change + parity-gated JSON-LD emission + no hard module dependency on contrib)
**Existing Architecture:** `geo_starter` recipe ships `section_contact_panel` paragraph with `field_section_hours` (type: string, cardinality: 1). `geo_starter_jsonld` reads that paragraph in `JsonLdFieldTrait::organizationContactFromSections()` but deliberately drops hours (free-text cannot produce `OpeningHoursSpecification`). The module has NO dependency on `office_hours` and must not gain one.

---

## Feature Overview

The `geo_starter_jsonld` module currently omits contact hours from JSON-LD because the source field (`field_section_hours`) is a free-text string, and the module's design rule is "a wrong property is a worse signal than an absent one." This plan reverses that omission by replacing the free-text field with the `drupal/office_hours` contrib field in the recipe, then teaching the module to read its structured storage columns defensively and emit `OpeningHoursSpecification` objects.

The work spans two repos (recipe and module) that release independently. The module change is backward-compatible (graceful no-op when the field is absent or not office_hours-shaped), so the module releases first. The recipe then adds the `office_hours` dependency, rewrites the field storage/instance/display config, and updates the sample content paragraph.

`hoursAvailable` is domain-valid on both `ContactPoint` and `Service` (verified against `schemaorg-current-https.jsonld`). Placement: attach to `ContactPoint` when one exists (has phone/email), else fall back to `Service` so hours still emit when a panel has hours but no reachable channel.

---

## Entity Relationship Diagram

```
Node (service)
  └── field_sections (entity_reference_revisions, unlimited)
        └── Paragraph (section_contact_panel)
              ├── field_section_phone      → ContactPoint.telephone
              ├── field_section_email      → ContactPoint.email
              ├── field_section_address    → Organization.address (PostalAddress)
              └── field_section_hours      → ContactPoint.hoursAvailable (or Service.hoursAvailable fallback)
                    [type: office_hours, cardinality: -1]
                    Each delta: {day: 0-6, starthours: int HHMM, endhours: int HHMM, all_day: bool, comment: string}

Schema.org emission:
  Service
    ├── provider: Organization
    │     ├── contactPoint: ContactPoint
    │     │     ├── telephone
    │     │     ├── email
    │     │     └── hoursAvailable: [OpeningHoursSpecification, ...]  ← NEW (when ContactPoint exists)
    │     └── address: PostalAddress
    └── hoursAvailable: [OpeningHoursSpecification, ...]              ← NEW (fallback when no ContactPoint)

OpeningHoursSpecification:
  { @type, dayOfWeek: "https://schema.org/Monday", opens: "09:00", closes: "17:00" }
```

---

## A. Recipe Config Changes (geo_starter)

All files below are in `/Users/AlexUA_1/claude/ai-initiative-modules/geo_starter/`.

### A1. composer.json — add office_hours dependency

**File:** `composer.json`
**Change:** Add `"drupal/office_hours": "^1.29"` to `require`.
**Rationale:** The composer facade maps drupal.org tag `8.x-1.29` to semver `1.29.0`. The `^1.29` constraint allows bugfix updates within the 1.x line. office_hours hard deps are core-only (`field`, `datetime`) — both already in the recipe's install list.

### A2. recipe.yml — add office_hours to install list

**File:** `recipe.yml`
**Change:** Add `office_hours` to the `install:` list (alphabetical, after `node`).
**Rationale:** The recipe must install the module before config that depends on it is imported. The `config: strict: false` setting means config actions are not needed — the field config files in `config/` are imported directly.

### A3. field.storage.paragraph.field_section_hours.yml — string to office_hours

**File:** `config/field.storage.paragraph.field_section_hours.yml`
**Changes:**
- `type: string` → `type: office_hours`
- `cardinality: 1` → `cardinality: -1` (unlimited — office_hours requires multi-delta for multi-day/multi-slot)
- Remove `settings.max_length`, `settings.case_sensitive`, `settings.is_ascii`
- Add office_hours storage settings (VERIFY: populate from `office_hours.schema.yml` or a UI export — do not fabricate)
- `module: core` → `module: office_hours`
- Add `dependencies.module: [office_hours, paragraphs]` (paragraphs stays; office_hours added because the field type requires it)

**VERIFY AT INSTALL:** Export `field.storage.paragraph.field_section_hours` from a fresh site with office_hours installed to confirm the correct `settings:` keys. office_hours may have `settings: {}` at storage level or may require specific keys. Do not guess.

### A4. field.field.paragraph.section_contact_panel.field_section_hours.yml — instance config

**File:** `config/field.field.paragraph.section_contact_panel.field_section_hours.yml`
**Changes:**
- `field_type: string` → `field_type: office_hours`
- `settings: {}` → office_hours instance settings (VERIFY: same export approach — may include `element_type`, `comment`, `valhrs`, `cardinality_per_day`, etc.)
- Label stays "Hours"

**VERIFY AT INSTALL:** Same as A3 — export the real config to get the correct settings shape.

### A5. core.entity_form_display.paragraph.section_contact_panel.default.yml — widget

**File:** `config/core.entity_form_display.paragraph.section_contact_panel.default.yml`
**Changes to `content.field_section_hours`:**
- `type: string_textfield` → `type: office_hours_default`
- Remove `settings.size`, `settings.placeholder`
- Add office_hours widget settings (VERIFY from export)
- Weight stays 5, region stays content

**Dependency changes:** Add `office_hours` to `dependencies.module`.

### A6. core.entity_view_display.paragraph.section_contact_panel.default.yml — formatter (PARITY ANCHOR)

**File:** `config/core.entity_view_display.paragraph.section_contact_panel.default.yml`
**Changes to `content.field_section_hours`:**
- `type: string` → `type: office_hours_table` (renders a human-friendly weekly table — this is the parity HTML surface)
- Remove `settings.link_to_entity`
- Add office_hours formatter settings (VERIFY from export)
- Weight stays 5, region stays content, label stays hidden

**Dependency changes:** Add `office_hours` to `dependencies.module`.

**PARITY ANCHOR:** This formatter being present and visible in the paragraph view display is what guarantees the JSON-LD emission is parity-safe. The module reads hours from the paragraph field; the formatter renders them in the visible HTML. JSON-LD <= visible HTML.

### A7. paragraphs.paragraphs_type.section_contact_panel.yml — no changes needed

The paragraph type definition is field-agnostic; it does not reference field types. Its `description` mentions "hours" already. No edit required.

---

## B. Sample Content Rewrite (geo_starter)

**File:** `content/paragraph/46000000-0000-4000-8000-000000000041.yml`

**Current shape (free-text string):**
```yaml
field_section_hours:
  -
    value: 'Mo-Fr 09:00-17:00'
```

**New shape (office_hours — Mon-Fri 09:00-17:00, Sat-Sun closed):**
```yaml
field_section_hours:
  -
    day: 1
    starthours: 900
    endhours: 1700
    comment: ''
  -
    day: 2
    starthours: 900
    endhours: 1700
    comment: ''
  -
    day: 3
    starthours: 900
    endhours: 1700
    comment: ''
  -
    day: 4
    starthours: 900
    endhours: 1700
    comment: ''
  -
    day: 5
    starthours: 900
    endhours: 1700
    comment: ''
```

Day mapping: 0=Sunday, 1=Monday ... 6=Saturday. Days 0 and 6 are absent (closed — no row emitted). Days 1-5 carry starthours=900 (09:00), endhours=1700 (17:00).

**KNOWN UNKNOWN — VERIFY AT INSTALL:** Does `default_content` import write these column-keyed rows correctly? The repo has learned (memory: "do NOT use drush content:export — this repo learned it silently drops/garbles content; author the YAML by hand"). The risk is that default_content's paragraph import may not correctly map multi-column field values for a contrib field type. Verification:

```bash
# After fresh site:install with the recipe:
ddev drush ev "\$p = \Drupal::entityTypeManager()->getStorage('paragraph')->loadByProperties(['uuid' => '46000000-0000-4000-8000-000000000041']); \$p = reset(\$p); print_r(\$p->get('field_section_hours')->getValue());"
```

Confirm the output shows 5 rows with `day`, `starthours`, `endhours` integer columns. If import silently drops the values, the `all_day` key may be needed or the YAML shape may need adjustment. This is the #1 blocker risk — test early.

**CAVEAT — Existing DDEV sites:** Changing `field_section_hours` from string to office_hours is NOT an in-place migration. Existing DDEV test sites need the field dropped+recreated or a fresh `site:install`. This is fine — the recipe is for fresh installs. Flag in release notes.

---

## C. Module Changes (geo_starter_jsonld)

All files below are in `/Users/AlexUA_1/claude/ai-initiative-modules/geo_starter_jsonld/`.

### C1. New pure helper: OpeningHoursMapper

**File:** `src/OpeningHoursMapper.php` (NEW)
**Responsibility:** Pure-function mapper from office_hours row arrays to schema.org `OpeningHoursSpecification` arrays. No Drupal dependencies. Stateless. Testable with UnitTestCase.

**Methods:**

#### `mapRows(array $rows): array`
- Input: array of office_hours value rows, each `['day' => int, 'starthours' => int, 'endhours' => int, 'all_day' => bool, 'comment' => string]`
- Output: array of `OpeningHoursSpecification` arrays, or `[]`
- Behavior:
  - Skip rows missing required keys (`day`, `starthours`, `endhours`) — defensive, no fatal
  - Skip rows where `day` is outside 0-6 (exception rows use date-keyed ints > 6)
  - Skip rows where both `starthours` and `endhours` are 0/null/empty (closed that day)
  - For `all_day: true` → emit `opens: "00:00"`, `closes: "23:59"` (design decision: not "24:00" which is not a valid Time in schema.org ISO 8601 partial time)
  - For midnight close (`endhours` = 2400) → clamp to `"23:59"` (same reasoning)
  - Multi-slot per day: two rows with same `day` → two separate `OpeningHoursSpecification` objects (not merged)
  - Each valid row produces one spec

#### `dayOfWeekIri(int $day): ?string`
- Maps office_hours day index to schema.org DayOfWeek full IRI
- 0 → `https://schema.org/Sunday`, 1 → `https://schema.org/Monday`, ... 6 → `https://schema.org/Saturday`
- Returns NULL for out-of-range (caller skips)

#### `formatTime(int $hhmm): string`
- Formats integer HHMM to "HH:MM" string
- 900 → "09:00", 1700 → "17:00", 930 → "09:30", 0 → "00:00", 2400 → "23:59" (clamped)
- Implementation: `sprintf('%02d:%02d', intdiv($hhmm, 100), $hhmm % 100)` with 2400 clamp

**Design constraints:**
- `declare(strict_types=1)`
- All methods static or on a readonly class (stateless)
- `final class` — no extension needed
- Functions < 50 lines each
- No `\Drupal::` calls, no service injection — pure PHP

### C2. Modify JsonLdFieldTrait::organizationContactFromSections()

**File:** `src/JsonLdFieldTrait.php` (lines 171-231)

**Current behavior:** Returns `['contactPoint' => ...|null, 'address' => ...|null]` or null. Hours are deliberately dropped (docblock at line 173-179 explains why).

**New return shape:** `['contactPoint' => ...|null, 'address' => ...|null, 'hoursAvailable' => array|null]` — adds a third key.

**Changes:**
1. **Update docblock** (lines 172-186): Remove the paragraph explaining why hours are dropped. Replace with: hours are now read from the office_hours field defensively (no module dependency; graceful no-op if field absent or not office_hours-shaped).
2. **Update `@return` type** to `array{contactPoint: ..., address: ..., hoursAvailable: array|null}|null`
3. **Add hours reading block** after the address block (after line 223), before the `if ($contact_point !== NULL || $address !== NULL)` gate:

Logic (pseudocode — NOT implementation code):
```
$hours = null;
if ($section->hasField('field_section_hours') && !$section->get('field_section_hours')->isEmpty()) {
    $rows = $section->get('field_section_hours')->getValue();
    $specs = OpeningHoursMapper::mapRows($rows);
    if ($specs !== []) {
        $hours = $specs;
    }
}
```

4. **Update the return gate** (line 225): add `$hours !== NULL` to the condition.
5. **Update return value**: `['contactPoint' => $contact_point, 'address' => $address, 'hoursAvailable' => $hours]`

**CRITICAL: No display check.** The existing phone/email/address readers in this method (lines 200-223) use raw `hasField() && !isEmpty()` with NO paragraph display check. Mirror that exactly. The parity gate is the RECIPE shipping `office_hours_table` in the paragraph view display (section A6). Do not pass the node `$display` to gate a paragraph subfield — `getComponent('field_section_hours')` on the node display returns NULL unconditionally (it's a paragraph field, not a node field), producing a silent always-skip bug.

**CRITICAL: No office_hours import.** The trait reads `getValue()` which returns raw arrays. `OpeningHoursMapper` operates on arrays. Neither requires `use Drupal\office_hours\...` anything.

### C3. Modify ServiceNormalizer::normalize()

**File:** `src/Normalizer/ServiceNormalizer.php` (lines 96-113)

**Current behavior:** Reads `organizationContactFromSections()`, places contactPoint and address on the provider Organization.

**New behavior:** Also reads `hoursAvailable` from the return value and places it:
- On `contactPoint` if a contactPoint exists (has at least one channel — phone/email)
- Else on the `$service` object directly (Service.hoursAvailable fallback)

Both placements are domain-valid per schema.org (verified: `hoursAvailable` domainIncludes = Service, ContactPoint, LocationFeatureSpecification).

**Changes to the contact-handling block (after line 103):**
```
// (pseudocode — NOT implementation code)
if ($contact !== NULL) {
    if ($contact['contactPoint'] !== NULL) {
        $cp = $contact['contactPoint'];
        if ($contact['hoursAvailable'] !== NULL) {
            $cp['hoursAvailable'] = $contact['hoursAvailable'];
        }
        $provider['contactPoint'] = $cp;
    }
    if ($contact['address'] !== NULL) {
        $provider['address'] = $contact['address'];
    }
    // Fallback: hours without a reachable channel → attach to Service
    if ($contact['contactPoint'] === NULL && $contact['hoursAvailable'] !== NULL) {
        $service['hoursAvailable'] = $contact['hoursAvailable'];
    }
}
```

### C4. Pure placement helper (optional but testable)

If the ServiceNormalizer placement logic is more than a few lines, extract a pure static method:

**`OpeningHoursMapper::placeHours(array $service, ?array $contactPoint, ?array $hoursSpecs): array`**
- Returns `['service' => $service, 'contactPoint' => $contactPoint]` with hoursAvailable attached to the right target
- Unit-testable for both branches (with-contactPoint, without-contactPoint)

This is optional — the logic in C3 is small enough to inline. Decide at implementation time.

---

## D. Tests

### D1. Unit test: OpeningHoursMapperTest (NEW)

**File:** `tests/src/Unit/OpeningHoursMapperTest.php` (NEW)
**Type:** UnitTestCase (no Drupal bootstrap — pure PHP)
**Covers:** `OpeningHoursMapper`

**Edge-case matrix (each is one test method or data-provider row):**

| Case | Input rows | Expected output | Pin exact values |
|---|---|---|---|
| Normal Mon-Fri 9-5 | days 1-5, starthours=900, endhours=1700 | 5 specs, each with correct dayOfWeek IRI | opens="09:00", closes="17:00" |
| Half-hour time | day=1, starthours=930, endhours=1730 | 1 spec | opens="09:30", closes="17:30" |
| Closed day omitted | day=0, starthours=0, endhours=0 | [] (skipped) | |
| Multi-slot day | two rows day=1 (9-12, 13-17) | 2 separate specs both with Monday IRI | opens="09:00"/"13:00", closes="12:00"/"17:00" |
| all_day flag | day=3, all_day=true | 1 spec | opens="00:00", closes="23:59" |
| Midnight close (2400) | day=1, starthours=900, endhours=2400 | 1 spec | closes="23:59" (clamped) |
| Empty field (no rows) | [] | [] | |
| Missing keys (malformed) | [{'day': 1}] (no starthours/endhours) | [] (skipped gracefully) | |
| Out-of-range day (exception row) | day=20260715 (date-keyed) | [] (skipped — >6) | |
| Mixed valid/invalid | [valid day=1, malformed, valid day=3] | 2 specs (skips malformed) | |

**dayOfWeekIri tests:**
- 0 → `https://schema.org/Sunday`
- 1 → `https://schema.org/Monday`
- 6 → `https://schema.org/Saturday`
- 7 → null
- -1 → null

**formatTime tests:**
- 900 → "09:00"
- 930 → "09:30"
- 1700 → "17:00"
- 0 → "00:00"
- 2400 → "23:59"
- 2359 → "23:59"

**WHY UNIT, NOT KERNEL:** The module has no composer/info.yml dependency on office_hours. drupal.org CI installs only declared deps. A kernel test that instantiates a real office_hours field would either force an office_hours require (violating constraint #3) or fail in CI. Pure-function extraction makes the mapper testable without Drupal.

### D2. Unit test: HoursPlacementTest (NEW, if C4 extracted)

**File:** `tests/src/Unit/HoursPlacementTest.php` (NEW, conditional)
**Type:** UnitTestCase
**Covers:** placement decision — contactPoint branch vs Service fallback

| Case | ContactPoint | hoursSpecs | Expected placement |
|---|---|---|---|
| Hours on ContactPoint | has telephone | 5 specs | contactPoint.hoursAvailable |
| Hours on Service (fallback) | null (no channel) | 5 specs | service.hoursAvailable |
| No hours, has ContactPoint | has telephone | null | neither has hoursAvailable |
| No hours, no ContactPoint | null | null | neither has hoursAvailable |

### D3. Probe update: tools/jsonld-probe.php

**File:** `tools/jsonld-probe.php`
**Changes:**
1. **Remove** the assertion at line 68: `$check('provider.contactPoint omits free-text openingHours', !isset($provider['contactPoint']['openingHours']));`
2. **Add** new assertions:

```
// hoursAvailable nests under ContactPoint when a channel exists (the sample
// has phone+email, so this is the expected path).
$cp = $provider['contactPoint'] ?? [];
$check(
  'provider.contactPoint.hoursAvailable is an array of OpeningHoursSpecification',
  isset($cp['hoursAvailable'])
    && is_array($cp['hoursAvailable'])
    && count($cp['hoursAvailable']) === 5  // Mon-Fri
);

// Verify one spec's shape: Monday 09:00-17:00.
$monday = $cp['hoursAvailable'][0] ?? [];
$check(
  'First hoursAvailable is Monday 09:00-17:00',
  ($monday['@type'] ?? '') === 'OpeningHoursSpecification'
    && ($monday['dayOfWeek'] ?? '') === 'https://schema.org/Monday'
    && ($monday['opens'] ?? '') === '09:00'
    && ($monday['closes'] ?? '') === '17:00'
);

// hoursAvailable does NOT appear on Service (ContactPoint carries it).
$check(
  'Service does not carry hoursAvailable when ContactPoint has it',
  !isset($service['hoursAvailable'])
);
```

3. **Update check count** comment if present.

### D4. schema-domain-check.py — no changes needed

`hoursAvailable` on `ContactPoint` and `Service` are both domain-valid. `OpeningHoursSpecification` with `dayOfWeek`, `opens`, `closes` are all domain-valid. The checker should report 0 errors / 0 warnings after the change. This is part of the release gate sweep (run against all node types).

---

## E. DDEV Validation Sequence

The repo's established validation workflow, in the reval workspace at `/Users/AlexUA_1/Documents/Codex/ddev-tests/geostarter-reval-20260529-171053`:

### E1. Install office_hours
```bash
cd /Users/AlexUA_1/Documents/Codex/ddev-tests/geostarter-reval-20260529-171053
ddev composer require drupal/office_hours:^1.29
```

### E2. Rsync recipe (to BOTH locations the harness uses)
```bash
rsync -av --delete /Users/AlexUA_1/claude/ai-initiative-modules/geo_starter/ \
  packages/geo_starter/
rsync -av --delete /Users/AlexUA_1/claude/ai-initiative-modules/geo_starter/ \
  recipes/geo_starter/
```

### E3. Rsync module
```bash
rsync -av --delete /Users/AlexUA_1/claude/ai-initiative-modules/geo_starter_jsonld/ \
  web/modules/custom/geo_starter_jsonld/
```

### E4. Fresh site install (field-type change requires clean install)
```bash
ddev drush site:install recipes/geo_starter -y
```

### E5. Verify default_content import (THE BLOCKER CHECK)
```bash
ddev drush ev "\$p = \Drupal::entityTypeManager()->getStorage('paragraph')->loadByProperties(['uuid' => '46000000-0000-4000-8000-000000000041']); \$p = reset(\$p); print_r(\$p->get('field_section_hours')->getValue());"
```
Expected: 5 rows with integer `day`/`starthours`/`endhours` columns.

### E6. Run probe
```bash
ddev drush scr web/modules/custom/geo_starter_jsonld/tools/jsonld-probe.php
```
Expected: all checks pass including the new hoursAvailable assertions.

### E7. Run schema-domain-check
```bash
# Extract JSON-LD from the Service page
curl -sk https://geostarter-reval-20260529-171053.ddev.site/apply-emergency-food-and-utility-assistance | \
  python3 -c "import sys,re,json;b=re.findall(r'<script type=\"application/ld\+json\">(.*?)</script>',sys.stdin.read(),re.S);print([x for x in b if '@graph' in x][0])" \
  > /tmp/service.jsonld

python3 tools/schema-domain-check.py /tmp/schemaorg.jsonld /tmp/service.jsonld
```
Expected: 0 errors / 0 warnings. Run against ALL node types (Service, Article, Answer, EvidenceSource) — lesson from the 2026-06-01 single-node miss.

### E8. Playwright screenshot of rendered hours
```bash
# Verify the office_hours_table formatter renders human-readable hours
# Wait for networkidle before capture
```

### E9. Run PHPUnit (module test suite)
```bash
ddev exec phpunit web/modules/custom/geo_starter_jsonld/tests/ --group=geo_starter_jsonld
```
Expected: all existing tests + new OpeningHoursMapperTest pass.

---

## F. Release Ordering (CRITICAL)

**Safe order: MODULE first, then RECIPE.**

### Step 1: Release module (geo_starter_jsonld 1.0.0-alpha3)

The module change is **backward-compatible**:
- Old recipe (string field) + new module → `OpeningHoursMapper::mapRows()` receives `[['value' => 'Mo-Fr 09:00-17:00']]` → rows have no `day`/`starthours`/`endhours` keys → mapper returns `[]` → no hoursAvailable emitted → same behavior as before. Graceful no-op.
- New recipe (office_hours field) + old module → hours still don't emit (old code drops them). No regression.
- New recipe + new module → hours emit correctly.

**Actions:**
1. Commit all module changes (OpeningHoursMapper, trait update, normalizer update, unit tests, probe update, README update, SCHEMA-VALIDATION doc update)
2. Tag `1.0.0-alpha3`
3. Push to BOTH remotes: `git push origin main --tags && git push drupalcode main --tags`
4. Create drupal.org release node for 1.0.0-alpha3
5. Wait for drupal.org CI to pass (phpunit matrix)

### Step 2: Release recipe (geo_starter next alpha)

**Actions:**
1. Commit all recipe changes (composer.json, recipe.yml, field storage/instance/display configs, sample content rewrite)
2. Tag next alpha (1.0.0-alpha3 if current is alpha2)
3. Push to BOTH remotes
4. Create drupal.org release node
5. Wait for CI

### Why this order is safe both directions

| Scenario | Behavior |
|---|---|
| Old module + old recipe | No hours in JSON-LD (current state) |
| **New module + old recipe** | Mapper sees string field rows with no day/starthours keys → `[]` → no hours. No regression. |
| Old module + new recipe | Hours field stores structured data, old code drops hours. No regression. |
| New module + new recipe | Hours emit correctly as OpeningHoursSpecification. Target state. |

---

## G. Documentation Updates

### G1. Module README.md

**File:** `README.md`
**Section:** "What it emits" → Service bullet

**Current (line 17-19):**
> **Service** → a `Service` (name, description, potentialAction, audience, and a `provider` Organization that nests the `ContactPoint` and `PostalAddress`).

**Add after "PostalAddress":**
> When the recipe ships a structured `office_hours` field on the contact panel, the `ContactPoint` also carries `hoursAvailable` (`OpeningHoursSpecification`) with day-of-week and open/close times. Without a structured hours field, hours are omitted (parity-safe).

### G2. SCHEMA-VALIDATION-2026-06-01.md

**File:** `docs/SCHEMA-VALIDATION-2026-06-01.md`
**Section:** "Still open (recommended follow-ups)" at bottom

**Current (line 180-181):**
> - **Structured hours field** if `OpeningHoursSpecification` output is wanted.

**Replace with:**
> - ~~**Structured hours field** if `OpeningHoursSpecification` output is wanted.~~ **Resolved 2026-06-02** — see `docs/plans/2026-06-02-hoursavailable-via-office-hours.md`. The recipe now ships `office_hours` and the module emits `hoursAvailable` with `OpeningHoursSpecification`.

### G3. Module docs: no SCHEMA_MAP.md exists

The trait docblock references `SCHEMA_MAP.md` (line 15) but no such file exists on disk. Out of scope for this PR, but note the debt.

---

## H. Implementation Tasks (Sequenced)

### Task 1: OpeningHoursMapper pure helper + unit tests (MODULE)

**Files to create:**
- `src/OpeningHoursMapper.php`
- `tests/src/Unit/OpeningHoursMapperTest.php`

**TDD sequence:**
1. Write `OpeningHoursMapperTest` with the full edge-case matrix from D1
2. All tests RED
3. Implement `OpeningHoursMapper` — pure functions, no Drupal
4. All tests GREEN
5. phpcs + DrupalPractice sniff pass

**Review checkpoint:** drupal-critic focus: type safety (strict_types, typed params/returns), edge-case coverage (empty/malformed/out-of-range), time formatting correctness (pin exact strings), no Drupal dependencies in a pure helper.

### Task 2: JsonLdFieldTrait + ServiceNormalizer changes (MODULE)

**Files to modify:**
- `src/JsonLdFieldTrait.php` (organizationContactFromSections: add hours reading, update docblock, update return type)
- `src/Normalizer/ServiceNormalizer.php` (placement logic: contactPoint vs Service fallback)

**Depends on:** Task 1 (OpeningHoursMapper must exist)

**Key constraints:**
- NO `$display` parameter for paragraph subfield parity check
- NO `use` import of any office_hours class
- Defensive: check `hasField`, `isEmpty`, then `getValue()` → pass to mapper
- Follow existing code style: `$section->hasField($field) && !$section->get($field)->isEmpty()` pattern

**Review checkpoint:** drupal-critic focus: no office_hours dependency leak, defensive reading, docblock accuracy, return type correctness, parity-gate reasoning.

### Task 3: Update probe + docs (MODULE)

**Files to modify:**
- `tools/jsonld-probe.php` (replace openingHours check with hoursAvailable assertions)
- `README.md` (add hoursAvailable to "What it emits")
- `docs/SCHEMA-VALIDATION-2026-06-01.md` (mark structured hours as resolved)

**Depends on:** Task 2

**Review checkpoint:** probe assertions are specific (count=5, exact Monday spec shape), README claim matches actual emission.

### Task 4: Recipe field config changes (RECIPE)

**Files to modify:**
- `composer.json` (add drupal/office_hours)
- `recipe.yml` (add office_hours to install)
- `config/field.storage.paragraph.field_section_hours.yml` (string→office_hours, cardinality -1)
- `config/field.field.paragraph.section_contact_panel.field_section_hours.yml` (field_type, settings)
- `config/core.entity_form_display.paragraph.section_contact_panel.default.yml` (widget)
- `config/core.entity_view_display.paragraph.section_contact_panel.default.yml` (formatter — PARITY ANCHOR)

**MUST VERIFY:** Export field/display config from a DDEV site with office_hours installed to get correct `settings:` keys. Do not fabricate.

**Depends on:** None (recipe changes are independent of module changes)

### Task 5: Sample content rewrite (RECIPE)

**File to modify:**
- `content/paragraph/46000000-0000-4000-8000-000000000041.yml` (free-text → office_hours rows)

**Depends on:** Task 4

**BLOCKER VERIFICATION:** After `site:install`, run `drush ev` to confirm default_content imported the office_hours rows correctly (step E5).

### Task 6: DDEV end-to-end validation (BOTH)

**No files changed — validation only.**

**Depends on:** Tasks 1-5 all complete

**Sequence:** E1 → E2 → E3 → E4 → E5 (blocker) → E6 → E7 → E8 → E9

**Review checkpoint:** drupal-critic focus: full-surface schema-domain-check (all node types), probe pass, unit test pass, formatter parity (screenshot proves HTML renders hours).

### Task 7: Release MODULE 1.0.0-alpha3

**Depends on:** Task 6 passing

**Actions:** Tag, push both remotes, create d.o. release, wait for CI.

### Task 8: Release RECIPE next alpha

**Depends on:** Task 7 (module release live on packagist/d.o.)

**Actions:** Tag, push both remotes, create d.o. release.

---

## Open Questions / Risks / Pre-Mortem

### Risk 1 (HIGH): default_content YAML shape for office_hours rows

The sample content file uses default_content format. office_hours stores multi-column rows (`day`, `starthours`, `endhours`, `comment`, `all_day`). It is unknown whether default_content's paragraph import correctly writes these column-keyed values. If import silently drops or garbles values, hours will be empty after `site:install`, the probe will fail, and the content file YAML shape will need adjustment. **Mitigation:** Test early (step E5). This is the #1 time-waster risk. If default_content cannot handle it, options: (a) adjust YAML shape, (b) add a recipe install hook that programmatically sets the field value, (c) ship without sample hours data.

### Risk 2 (MEDIUM): office_hours config schema / settings keys

The field storage, field instance, widget, and formatter configs all have `settings:` blocks that must match what `office_hours` expects. Fabricating these from documentation rather than exporting from a real site risks config import failures or silent defaults. **Mitigation:** Do not fabricate — export from a DDEV site (Task 4 instruction). If office_hours has no config schema at all, Drupal will accept `settings: {}` but may log warnings.

### Risk 3 (MEDIUM): Day-index base confirmation

office_hours source code (checked this session) uses 0=Sunday, 1=Monday ... 6=Saturday. The mapper hardcodes this mapping. If a future office_hours version changes the base, or if the checked version was wrong, day→IRI mapping breaks silently. **Mitigation:** The probe pins "first spec is Monday" — a day-off-by-one would fail the probe. The unit test pins all 7 day→IRI mappings.

### Risk 4 (LOW): office_hours_table formatter output for parity

The parity rule says JSON-LD must not exceed visible HTML. The `office_hours_table` formatter renders a human-friendly weekly table. If its output includes days the JSON-LD omits (e.g. "Saturday: Closed") or vice versa, there's a parity gap. **Mitigation:** The module reads the raw field values, not the formatter output. The formatter shows all days including closed ones as "Closed"; the JSON-LD omits closed days entirely. This is parity-safe: JSON-LD < visible HTML (subset, not superset). No risk.

### Risk 5 (LOW): phpcs / cspell additions

New identifiers: `hoursAvailable`, `OpeningHoursSpecification`, `dayOfWeek`, `starthours`, `endhours`. If cspell is configured in drupal.org CI, some may need adding to the dictionary. `starthours`/`endhours` are office_hours column names, not our choice. **Mitigation:** Check CI output after push; add to cspell dictionary if needed.

---

## Summary

| What | Count |
|---|---|
| Recipe files changed | 6 (composer.json, recipe.yml, field storage, field instance, form display, view display) + 1 content rewrite |
| Module files created | 2 (OpeningHoursMapper.php, OpeningHoursMapperTest.php) |
| Module files modified | 4 (JsonLdFieldTrait.php, ServiceNormalizer.php, jsonld-probe.php, README.md) + 1 doc update |
| Total files touched | ~13 |
| Release order | MODULE first (1.0.0-alpha3), then RECIPE (next alpha) |
| Backward compatibility | Full — new module with old recipe = graceful no-op |
