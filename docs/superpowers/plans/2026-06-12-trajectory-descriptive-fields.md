# Trajectory Descriptive Fields — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (sequential, shared context — one module + one template + one admin file). Harness: netdust-agent:harnessed-development Class A. No threat model (admin-only fields behind existing nonce+capability, output-escaped — no new attack surface); wp-plan-requirements layering gate applies. Append the verbatim netdust addendum if any task is dispatched to a subagent.

**Goal:** Make the public trajectory page's "Praktische informatie" + sidebar render from real CPT fields instead of hardcoded text, reusing the edition's proven descriptive field set and "render-when-present" design. Admin gets inputs for those fields; empty fields hide their card/well (no fake data).

**Architecture:** The edition CPT already defines a battle-tested descriptive field set (`target_audience`, `required_experience`, `included`, `price_includes`, `cancellation_policy`, `cta_benefits`, `enrollment_info`) and `single-vad_edition.php` renders each only when non-empty. The trajectory already has `target_audience`/`duration`/`tagline` registered but unused and admin-uneditable. This plan: (1) register the missing descriptive fields on `TrajectoryCPT`, (2) surface them in `getTrajectory()`'s field array, (3) add admin metabox inputs + save handling mirroring the edition idiom, (4) rewrite the trajectory page's `content.php` "Praktisch" section + `single-vad_trajectory.php` sidebar to the edition's render-when-present design. The hardcoded FAQ stays as-is (separate future change, per Stefan).

**Tech stack:** WordPress mu-plugin (stride-core, NTDST Data API) + Stridence theme (PHP templates) + PHPUnit/Codeception.

**Class:** A. Single review cluster, tier STANDARD (theme + admin UI, no 1a surface — admin save already nonce+capability gated, all output escaped).

## Golden path: content-type feature (deviations named)

- Built to `netdust-wp:ntdst-patterns` → `golden-paths/content-type-feature.md` (CPT field → Service array → Admin metabox → frontend template).
- Deviations: no new CPT/Repository/Router — extends the EXISTING `TrajectoryCPT` field set, `TrajectoryService::getTrajectory()` formatter, `TrajectoryAdminController` metabox, and `single-vad_trajectory.php`/`content.php` templates. Pure field-set extension; reuses the edition's field names verbatim so the two offering types converge.

## WP security requirements (per data-flow)

- [ ] Admin save of the new descriptive fields (existing `TrajectoryAdminController::handleSave`, nonce + `current_user_can('edit_post')` already enforced at the top): each new field sanitized on save — `sanitize_textarea_field` for the textarea fields (`target_audience`, `included`, `cancellation_policy`, `cta_benefits`, `enrollment_info`, `required_experience` is text → `sanitize_text_field`; `price_includes` text → `sanitize_text_field`). Mirrors the existing `description` handling at line ~1047.
- [ ] Frontend render (`content.php`, `single-vad_trajectory.php`): every field output through `esc_html()` (textareas via `esc_html` + `nl2br` only where multiline display is wanted, matching the edition page which uses plain `esc_html`). `cta_benefits` split on newlines exactly as `single-vad_edition.php:249` does, each line `esc_html`. No raw output. escape: covered; authorize: n/a (public read-only); validate/sanitize: read-side trims only.

## ntdst-core layering requirements

- [ ] CPT fields registered in `TrajectoryCPT::getFields()` (Data API vocabulary — the section's whole point: registered fields show).
- [ ] Reads through `TrajectoryService::getTrajectory()` / `TrajectoryRepository::getField()` — no direct `get_post_meta` in the template for these.
- [ ] No new service/handler/repository — extends existing classes; no pass-through methods added.
- [ ] Admin metabox stays in `Modules/Trajectory/Admin/TrajectoryAdminController` (correct layering).

> Convergence target for `/code-review` + `ntdst-drift-reviewer`: the named golden-path slice + the security line above.

## Acceptance flows

| # | Flow | Faithful layer | Edges |
|---|---|---|---|
| F1 | Admin sets Doelpubliek + Inbegrepen + Annuleren on a trajectory → public page renders those wells | Admin save + browser | empty: a field left blank renders NO card (not placeholder); boundary: only one field set → only that well shows; denied: n/a (admin behind capability); concurrent/re-entry/mid-flow: n/a — simple meta save |
| F2 | Trajectory with ALL descriptive fields empty (current seed state) → "Praktisch" section shows only the always-on cards (Cursussen count, Doorlooptijd), no empty wells, no hardcoded "Zorgprofessionals"/"VAD Certificaat" | Browser | empty (the default state) is the primary case |
| F3 | Sidebar reads price_includes (under price), enrollment_info (under CTA), cta_benefits (checklist) when set; hidden when empty | Browser | empty: bare sidebar = today's behavior; boundary: benefits with blank lines filtered |

## Field mapping (edition → trajectory, verbatim names)

| Field | Type | Already on TrajectoryCPT? | Renders as (copy from edition) |
|---|---|---|---|
| `target_audience` | textarea | ✅ registered, unused | "Voor wie?" well in Overzicht (single-vad_edition.php:353) |
| `required_experience` | text | ❌ add | "Voorkennis" well in Praktisch (edition:519) |
| `included` | textarea | ❌ add | "Inbegrepen" well (edition:527) |
| `cancellation_policy` | textarea | ❌ add | "Annuleren" well (edition:535) |
| `price_includes` | text | ❌ add | line under sidebar price (edition:598) |
| `enrollment_info` | textarea | ❌ add | under sidebar CTA + mobile repeat (edition:545,665) |
| `cta_benefits` | textarea | ❌ add | sidebar benefits checklist (edition:671) |
| `duration` | text | ✅ registered, unused | "Doorlooptijd" card — value when set, else mode-derived fallback |

(`deadline_months` already editable; `tagline` out of scope — header treatment is a separate design choice.)

## File structure

- Modify: `web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectoryCPT.php` — add 6 fields to `getFields()` (after `target_audience`, same textarea/text idiom as edition).
- Modify: `web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectoryService.php` — add the 8 descriptive keys to BOTH `formatTrajectory()` (line ~451) AND `formatTrajectoryArray()` (line ~473) so `getTrajectory()` surfaces them whichever path runs.
- Modify: `web/app/mu-plugins/stride-core/Modules/Trajectory/Admin/TrajectoryAdminController.php` — add inputs to the details metabox (`.stride-field` idiom) + `isset()` sanitize blocks in `handleSave` (mirror the `description` block ~1047).
- Modify: `web/app/themes/stridence/templates/trajectory/content.php` — replace the hardcoded "Praktisch" grid with the edition's render-when-present wells; add the "Voor wie?" well to Overzicht. FAQ untouched.
- Modify: `web/app/themes/stridence/single-vad_trajectory.php` — read the new fields from `$trajectory[...]`; wire price_includes / enrollment_info / cta_benefits into the sidebar (mirror edition sidebar 598-683).
- Create: `tests/Integration/TrajectoryDescriptiveFieldsTest.php` — the fields round-trip through the schema + getTrajectory() surfaces them.
- Modify: `tests/acceptance/TrajectoryE2ECest.php` — add F1/F2 assertions (set a field → well shows; empty trajectory → no placeholder text, no "Zorgprofessionals"/"VAD Certificaat").

---

# Task breakdown

### Task 1: Register the descriptive fields + surface in getTrajectory

- [ ] Step 1: Failing integration test `TrajectoryDescriptiveFieldsTest`: `update($id, ['included' => 'X', 'cancellation_policy' => 'Y', ...])` then `getTrajectory($id)` returns each key with the saved value; unset → '' default. Run → FAIL (fields unregistered + not in formatter).
- [ ] Step 2: Add to `TrajectoryCPT::getFields()` (verbatim from EditionCPT:119-145): `required_experience` (text), `included` (textarea), `price_includes` (text), `cancellation_policy` (textarea), `cta_benefits` (textarea), `enrollment_info` (textarea). (`target_audience`, `duration` already present.)
- [ ] Step 3: Add the 8 keys to `formatTrajectory()` + `formatTrajectoryArray()` via `getField(..., '')`.
- [ ] Step 4: GREEN; full unit suite; commit.

Unit test: field round-trip + getTrajectory surfaces. Tier A (data-layer registration).

### Task 2: Admin metabox inputs + save

- [ ] Step 1: Add inputs to `renderDetailsMetabox` after the description field — `.stride-field` textarea/text rows for the 6 new fields + `target_audience` + `duration` (label them Doelpubliek/Voorkennis/Inbegrepen/Prijs inclusief/Annuleringsvoorwaarden/Voordelen (één per lijn)/Inschrijvingsinfo/Doorlooptijd). Prefill `value`/textarea body from `$trajectory[...]`.
- [ ] Step 2: Add `isset($fields[X])` sanitize blocks to `handleSave` — `sanitize_textarea_field` for textareas, `sanitize_text_field` for `required_experience`/`price_includes`/`duration` (mirror line ~1047).
- [ ] Step 3: Manual admin round-trip verify (set fields, reload edit page, values persist); commit.

No unit test: Tier B — admin form glue over the Tier-A-registered fields; round-trip covered by F1 in TrajectoryE2ECest.

### Task 3: Frontend — content.php Praktisch + Overzicht "Voor wie?"

- [ ] Step 1: In `content.php`, read `$trajectory['target_audience']` etc (already in the `$trajectory` arg). Add the "Voor wie?" `bg-surface-alt` well after the Overzicht prose when `target_audience !== ''` (copy single-vad_edition.php:353-358).
- [ ] Step 2: Replace the hardcoded 4-card "Praktisch" grid with: always-on "Cursussen" (count, keep) + "Doorlooptijd" (`duration` when set, else the existing mode-derived text); render-when-present wells for `required_experience`/`included`/`cancellation_policy` (copy edition:519-542). REMOVE the hardcoded "Doelgroep: Zorgprofessionals" and "Certificaat: VAD Certificaat" cards.
- [ ] Step 3: Browser smoke (empty trajectory → no placeholder; set fields → wells show); commit.

No unit test: Tier B — template; covered by F1/F2 acceptance.

### Task 4: Frontend — sidebar enrichment

- [ ] Step 1: In `single-vad_trajectory.php`, read the new fields. Under the price row add `price_includes` line (edition:598); under the enroll CTA add `enrollment_info` (edition:665); add the `cta_benefits` checklist (split on `\R`, filter blanks, edition:249+671). Each render-when-present.
- [ ] Step 2: Browser smoke; commit.

No unit test: Tier B — template; covered by F3.

### ── REVIEW GATE — single cluster (tier: STANDARD) ──
`/code-review --effort=medium` + `code-simplicity-reviewer` + `ntdst-drift-reviewer Modules/Trajectory` + feature-acceptance browser pass on F1/F2/F3. (No security-sentinel — no 1a surface; escalate to FULL only if a finder hits one.)

### Task 5: Acceptance coverage

- [ ] Extend `TrajectoryE2ECest`: F1 (admin-set field via DB write mirroring save → public well renders), F2 (the existing fixture, all-empty → assert NO "Zorgprofessionals"/"VAD Certificaat"/placeholder, Praktisch shows only Cursussen+Doorlooptijd), F3 (set price_includes/enrollment_info/cta_benefits → sidebar shows them).
- [ ] Run green; commit.

## Stage 3 — close

1. `/integration` (unit + integration; the 6 pre-existing unrelated integration failures stay out of scope).
2. `test-effectiveness` over the diff (empty-state denial paths: each well's hide-when-empty).
3. `feature-acceptance` drive F1-F3 (TrajectoryE2ECest is the executable form).
4. `/shakeout` — branch tier STANDARD: `reviewer` + `invariant-auditor` + `ntdst-drift-reviewer`.
5. `superpowers:finishing-a-development-branch`.

## Verification (manual)

```bash
ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter TrajectoryDescriptiveFields
ddev exec vendor/bin/codecept run acceptance TrajectoryE2ECest
# Admin: wp-admin → edit "Kennismakingstraject" → fill Doelpubliek/Inbegrepen/Annuleren/Voordelen → save →
#   visit /trajecten/kennismakingstraject-jeugdgezondheid/ → wells render, no "Zorgprofessionals"/"VAD Certificaat".
```

## Known context for the implementer

- `getTrajectory()` whitelists fields in TWO formatters (`formatTrajectory` for WP_Post path, `formatTrajectoryArray` for the data-array path) — add the keys to BOTH or the field is null on one path.
- The edition field set + render-when-present pattern is the source of truth: `EditionCPT::getFields()` lines 115-145, `single-vad_edition.php` lines 353 (Voor wie), 519-542 (Praktisch wells), 598/665/671 (sidebar).
- `cta_benefits` is one-item-per-line; split with `preg_split('/\R/', ...)` + `array_filter(array_map('trim', ...))` exactly as single-vad_edition.php:249.
- Hardcoded FAQ section in content.php stays — separate future change (Stefan).
- `tagline` field exists but is out of scope (header design decision, not "practical info").
- Latent (NOT this plan): trajectory `price` is read as euros in the template but the admin save multiplies by 100 (cents) — pre-existing inconsistency; flag in review, don't fix here unless trivial.
