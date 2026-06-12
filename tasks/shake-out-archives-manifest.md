# Shake-out: Archive surfaces (after URL role split)

**Date:** 2026-05-18
**Scope:** Verify the four archive-like surfaces show correct status, filter past editions, and use the right partials. After the URL role split (`/opleidingen` informational, `/vormingen` transactional), confirm those rules hold in the discovery feeds.

**Surfaces under test:**
- `/opleidingen/` — `archive-sfwd-courses.php` — landing page with 3 sections (trajectories, classroom editions, online courses)
- `/klassikaal/` — `page-klassikaal.php` — classroom edition catalog
- `/vormingen/` — falls through to `index.php` (no dedicated template) — raw post-type archive
- `/online/` — `page-online.php` — online course catalog (uses `card-course`)

---

## Findings

### BUG #A1 — CRITICAL — Past editions leak into every archive

**Symptom:** Editions with `_ntdst_status = open` but `end_date` in the past appear on `/opleidingen/`, `/klassikaal/`, and `/vormingen/` with badge "Open voor inschrijving". 11 editions in the DB right now match this condition (today 2026-05-18, cutoff today-2 = 2026-05-16).

**Examples on `/klassikaal/`:**
- Gratis Webinar: Lachgas — 20 maart 2026 → "Open voor inschrijving" ❌
- Gratis Introductie: Werken bij BWEEG — 7 april 2026 → "Open voor inschrijving" ❌
- Jeugdgezondheid: Fundament — 14 april 2026 → "Open voor inschrijving" ❌
- Assessment en Motorische Screening — 28 april 2026 → "Open voor inschrijving" ❌
- Gezonde Tussendoortjes — 5 mei 2026 → "Open voor inschrijving" ❌
- (...3 more)

**Root cause:** Archive queries only filter by `_ntdst_status IN [announcement, open, full, in_progress]` — no date guard. Stored status doesn't auto-transition to `Completed` when `end_date` passes (the architectural decision from earlier shake-outs: rely on `isPast()` at render time).

**Fix needs two parts:**
1. **Filter at query time** — hide cards where `end_date < today − 2 days` (2-day grace so just-finished cohorts still find their page).
2. **Effective status at render time** — when a past edition slips through (e.g. the 2-day grace), its badge must say "Afgelopen", not "Open". Right now badges read raw `_ntdst_status`.

**Affected templates:**
- `page-klassikaal.php` query (line 32-43)
- `archive-sfwd-courses.php` query (line 35-66)
- `partials/badge-status.php` (reads raw status without `isPast` check)
- `partials/card-edition.php` (passes raw status to badge partial)
- `single-vad_edition.php` already does the past override at line 180, but the card-edition partial doesn't

---

### BUG #A2 — HIGH — `/vormingen/` archive renders raw posts with no styling, no filtering

**Symptom:** `/vormingen/` (the `vad_edition` post-type archive) falls through to `index.php`, which renders generic "card p-6" tiles with raw post titles, excerpts, and "Lees meer" buttons. No status badges. No filtering. 10 cards shown including past editions, all statuses indiscriminately.

**Decision:** Hide the archive entirely (`has_archive => false` on the CPT registration). `/klassikaal/` and `/online/` are the actual discovery surfaces. Individual `/vormingen/<slug>/` URLs continue to work via the rewrite slug.

**Affected:**
- `mu-plugins/stride-core/Modules/Edition/EditionCPT.php` (line 33: `has_archive => true`)

---

### BUG #A3 — IMPORTANT — Online course cards always say "Beschikbaar" regardless of underlying edition

**Symptom:** `partials/card-course.php` shows a hardcoded "Beschikbaar" badge for any logged-out / unenrolled visitor. When a course has an edition (online course + scheduled offering), the badge ignores the edition's status. So an archived/cancelled-edition course still says "Beschikbaar" on `/online/` and `/opleidingen/`.

**Rule (confirmed):** Edition trumps LD when an edition exists. Pure-LD courses keep "Beschikbaar".

**Fix:** `card-course.php` checks for a primary edition (same logic as the route resolver — visible status, prefer enrollable > active). If found, badge reads that edition's effective status via `badge-status` partial. Pure-LD courses keep the existing "Beschikbaar" branch.

**Affected:**
- `partials/card-course.php` (line 47-78 — the status-decision block)
- Used by `/online/` and `/opleidingen/`

---

### BUG #A4 — IMPORTANT — Badge ignores past-date override on card surfaces

**Symptom:** A "past" edition that's still within the 2-day grace period (i.e. card-visible) shows the wrong badge. End_date was yesterday → card still on screen → badge says "Open voor inschrijving". Visitor clicks → lands on single page which DOES show "Afgelopen" (because the single page has `isPast` override at line 180).

**Inconsistency:** Single page knows the edition is past; card partials don't.

**Fix:** Compute effective status in `EditionService::getEffectiveStatus()` and use it everywhere a badge is rendered:
- `partials/card-edition.php` (catalog grid)
- `partials/badge-status.php` accepts effective status directly (no change needed if caller passes it)
- `templates/course/editions-list.php` (course-detail edition rows)
- `single-vad_edition.php` (already has its own past override — can replace with effective status for consistency)

**Definition of past for the badge:** `end_date < today` (no grace) — last day of the edition is the last day. Day after = "Afgelopen". Filter cutoff stays at -2 days separately (so the badge says "Afgelopen" for ~2 days before the card drops off).

---

### Non-issues confirmed

- **No-session editions** render cleanly on the single edition page (`single-vad_edition.php`): Sessies tab + section both gate on `$has_sessions`. Interest form still reachable via status-based CTA. No fix needed.
- **`badge-status` partial** itself is fine after the earlier Cluster A fix — it sources from `OfferingStatus`. The issue is what status it receives, not how it renders.
- **`/online/` card-course** is correct for pure-LD courses (most of them). Only fails when an online course has an edition.

---

## Severity summary

| # | Severity | Bug | Affected files |
|---|---|---|---|
| A1 | CRITICAL | Past editions leak with wrong badge | page-klassikaal, archive-sfwd-courses, card-edition |
| A2 | HIGH | /vormingen/ archive is broken | EditionCPT |
| A4 | IMPORTANT | Card badges don't respect past-date | card-edition, editions-list |
| A3 | IMPORTANT | Course cards ignore edition status | card-course |

## Cluster fix plan

- **Cluster X — Effective status primitive** (fixes A1 render-side + A4):
  - Add `EditionService::getEffectiveStatus(int): OfferingStatus` → returns `Completed` when `isPast()` is true, else stored status.
  - `card-edition.php` reads it from the edition's data when available; falls back to stored status.
  - `editions-list.php` already calls `getStatus()` — switch to `getEffectiveStatus()`.
  - `single-vad_edition.php` replaces its inline `$is_past` past-override with the effective status (clean-up, not behavior change).

- **Filter cutoff** (fixes A1 query-side):
  - Add a meta_query date guard to both archive queries: `_ntdst_end_date >= today − 2 days` (or `_ntdst_start_date >= …` when end is missing). Same filter goes into `editions-list.php` if it should respect the cutoff too.

- **`/vormingen/` archive** (fixes A2):
  - Set `has_archive => false` on the `vad_edition` CPT. Flush rewrite rules.

- **`card-course.php`** (fixes A3):
  - Look up primary edition for the course. If found, render its effective status badge instead of "Beschikbaar". Pure-LD courses keep "Beschikbaar".
