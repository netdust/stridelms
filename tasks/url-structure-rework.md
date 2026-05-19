# URL Structure Rework — /vormingen/ → /edities/ + LD owns /opleidingen/

Decided 2026-05-19 after the dry-run surfaced a second iteration of the
slug-confusion problem. Replaces the previous URL role split
(`a5f5aea9`, 2026-05-18).

## Final model

| URL | Renders | Notes |
|---|---|---|
| `/opleidingen/` | Course archive (all formats) | unchanged |
| `/opleidingen/<course-slug>/` (online) | **The course's main page.** Stride sidebar with CTA + LD lesson content. Self-enrollable. | LD owns this URL — no redirects away |
| `/opleidingen/<course-slug>/` (klassikaal) | Course info + list of editions to choose from | Pick-an-edition surface |
| `/online/` | Catalog of online courses | unchanged |
| `/klassikaal/` | Catalog of editions (one card per active edition) | already matches `pattern_catalog_one_card_per_enrollable` |
| `/edities/<edition-slug>/` | Single edition info + CTA | renamed from `/vormingen/<edition-slug>/` |
| `/edities/<edition-slug>/inschrijving/` | Edition enrollment form | renamed from `/vormingen/<edition-slug>/inschrijving/` |

### Catalog → destination rules

- **Online catalog cards** → `/opleidingen/<course-slug>/`
- **Klassikaal catalog cards** → `/edities/<edition-slug>/` (one card per edition)

### What dies

- `/vormingen/` (top-level slug) — gone. Replaced by `/edities/`.
- `/vormingen/<course-slug>/` for pure-LD online (the recent invention) — gone. Online courses live on `/opleidingen/<slug>/` instead.
- `EditionRouter::maybeRouteCourse()` pure-LD branch — gone. No more "rewrite query vars to load a course at /vormingen/<slug>/".
- `?enroll=1` handler in `EditionRouter` — gone, replaced by behaviour on `/opleidingen/<slug>/` itself.

### What stays

- `EditionRouter` still routes `/edities/<edition-slug>/` via WP's native CPT rewrite (just renamed). The pure-LD branch is removed.
- `EnrollmentRouter` still owns `/edities/<edition-slug>/inschrijving/` (renamed from `/vormingen/<slug>/inschrijving/`).
- `single-course-enrollable.php` template — its job is now done on `/opleidingen/<slug>/` for online courses, so it gets folded into the regular `single-sfwd-courses.php` flow (with the sidebar/CTA shown when format is online).

## Why this is the right model

The bug we just hit (`bug-pureld-open-cta-loop`) was caused by Stride trying to be the destination for online courses at `/vormingen/<slug>/` while LD still owned `/opleidingen/<slug>/`. Every LD-generated link (lesson tiles, breadcrumbs, "back to course") pointed at `/opleidingen/<slug>/` because LD has no way to know about the role split.

**You can't out-vote LD on its own URL.** Better to let LD own `/opleidingen/<slug>/`, and have Stride decorate that page with the right CTA based on course format.

Klassikaal courses don't have this problem because they're never self-enrollable — there's always an edition between user and course. `/opleidingen/<slug>/` (klassikaal) showing a list of editions is exactly what LD's default doesn't do, so Stride takes that page over for klassikaal.

## File touch list

### Renames + simple slug swaps

- [ ] `StrideSettingsService::DEFAULT_SLUGS['edition']` — `'vormingen'` → `'edities'`
- [ ] Search every `/vormingen/` literal in `web/app/themes/stridence/**/*.php` → `/edities/`
- [ ] Search every `/vormingen/` literal in `web/app/mu-plugins/stride-core/**/*.php` → `/edities/`
- [ ] `EditionCPT` rewrite slug — picks up via `StrideSettingsService::getEditionSlug()`, no direct change
- [ ] `stride_enrollment_url()` helper in `themes/stridence/helpers/formatting.php` — already uses the slug constant, verify

### Routing changes

- [ ] `EditionRouter::maybeRouteCourse()` — remove the pure-LD pass-through branch (the `$wp->query_vars = ['sfwd-courses' => $slug, ...]` block). Keep the edition redirect logic for `/edities/<slug>/` that points at editions or fans out when multiple match.
- [ ] `EditionRouter::handlePureLdEnroll()` — delete (the `?enroll=1` handler added today). Replaced by direct LD enroll behaviour on `/opleidingen/<slug>/`.
- [ ] `EditionRouter::singleCourseTemplate()` — delete (the `template_include` override for `/vormingen/<course>/`). LD's native template handles `/opleidingen/<slug>/`.
- [ ] `EnrollmentRouter` — verify all path matching is on `/edities/<slug>/inschrijving/`, not `/vormingen/<slug>/inschrijving/`.

### Template changes

- [ ] `single-sfwd-courses.php` — restore CTAs. Branch by format:
  - **online format** → render the sidebar/CTA (currently in `single-course-enrollable.php`). CTA: "Start cursus" (open + has_access), "Direct starten" / "Gratis inschrijven" with direct LD enroll for open/free, edition flow for editions, etc.
  - **klassikaal format** → render the editions list (already partly there), no inline CTA. Course content displays below.
- [ ] `single-course-enrollable.php` — delete or fold into `single-sfwd-courses.php`. It was the workaround for the `/vormingen/<course>/` pure-LD route; route is gone.
- [ ] `sidebar-online.php` — already updated today. Re-target the enroll URL: instead of `/edities/<slug>/?enroll=1`, the CTA on `/opleidingen/<slug>/` for online courses should call a same-page handler that grants access + redirects to first lesson. Reuse `LearnDashHelper::getFirstLessonUrl()` and inline `ld_update_course_access()` if needed. Easiest: query arg `?enroll=1` on `/opleidingen/<slug>/` itself, handled by a small `template_redirect` hook in stride-core that fires before LD renders the course.
- [ ] `mobile-cta.php` — same update as sidebar-online.
- [ ] `card-course.php` and `card-edition.php` — catalog cards. Update permalinks:
  - online card → `home_url('/opleidingen/' . $course->post_name . '/')` (was `/vormingen/<slug>/`)
  - edition card → `home_url('/edities/' . $edition->post_name . '/')` (was `/vormingen/<slug>/`)
- [ ] `archive-sfwd-courses.php` — has hard-coded `/klassikaal/` and `/online/` links, those stay. Check for any `/vormingen/` references.
- [ ] `page-online.php`, `page-klassikaal.php` — these build card lists. Card target URLs flow through `card-course.php` / `card-edition.php`, so the change lives there.

### Backwards-compat redirects

Old `/vormingen/...` URLs are in emails, bookmarks, possibly indexed in SEO. Set up permanent redirects:
- [ ] `/vormingen/` → `/edities/` (301)
- [ ] `/vormingen/<slug>/` → `/edities/<slug>/` (301) — works for editions
- [ ] `/vormingen/<course-slug>/` for pure-LD courses → `/opleidingen/<slug>/` (301)
- [ ] `/vormingen/<slug>/inschrijving/` → `/edities/<slug>/inschrijving/` (301)

Implement as a single early-priority hook in stride-core that pattern-matches `/vormingen/` prefixes. Simpler than per-URL rewrites.

### Tests + memory updates

- [ ] Update unit tests that hard-code `/vormingen/` in URL assertions. Grep `tests/` for `/vormingen/`.
- [ ] Run full unit + integration suite. Run acceptance if there's a Codeception cest that walks the enrollment flow.
- [ ] Update memory:
  - `lesson_url_role_split.md` — append "rolled back 2026-05-19; the role split sounded right but LD's hard-coded links to its own permalink made it untenable. Final model: LD owns `/opleidingen/<slug>/` for online (self-enrollable) AND klassikaal (edition picker); Stride owns `/edities/<slug>/` for the transactional edition surface."
  - `bug_pureld_open_cta_loop.md` — mark as resolved by URL restructure (not by the in-place CTA fix we just shipped, which becomes obsolete).
  - `pattern_catalog_one_card_per_enrollable.md` — update card-target URLs to the new scheme.
- [ ] Update `CLAUDE.md` references to `/vormingen/` if any.

### Settings + admin

- [ ] `tab-general.php` admin UI shows the URL preview as `<siteurl>/<edition_slug>/editie-naam/`. Default value flips from `vormingen` to `edities`. Check stored value migration: any existing `stride_url_slugs.edition` value in production stays untouched (user-set); local sites pick up the new default.

## Order of operations

1. Plan reviewed (this doc) — **you are here**
2. Roll back the dry-run snapshot first (we're still on VAD's DB). Get back to clean Stride.
3. Implement the rename on a feature branch. Single commit per phase:
   a. Slug constant change + grep/replace `/vormingen/` → `/edities/` (literal swap, no behaviour change yet — old routes still work because EditionRouter is unchanged)
   b. Remove pure-LD pass-through from EditionRouter + delete `single-course-enrollable.php`
   c. Add CTA branch in `single-sfwd-courses.php` for online courses (and editions-list branch for klassikaal)
   d. Add `?enroll=1` handler on `/opleidingen/<slug>/` (template_redirect priority)
   e. Update cards (`card-course.php`, `card-edition.php`) to point at new URLs
   f. Add 301 redirects from old `/vormingen/` URLs
   g. Update tests + memory
4. Run full test suite at each commit.
5. Manual walk-through:
   - Catalog → online card → `/opleidingen/<slug>/` → see CTA → click → enroll + first lesson
   - Catalog → klassikaal card → `/edities/<slug>/` → see CTA → click → `/edities/<slug>/inschrijving/`
   - Old `/vormingen/<slug>/` URL → 301 to new URL

## Decision rules on /opleidingen/<slug>/ (resolved 2026-05-19)

The page branches on **active-edition presence first**, then on **format**:

```
if (course has any active edition):
    → "edition surface wins" — show the editions list, NO self-enroll CTA
    → if exactly 1 active edition, the card/CTA links directly to /edities/<slug>/
    → if multiple, render the list and let the user pick

elif (course has format=online):
    → render self-enroll CTA (the only place /opleidingen/<slug>/ shows a CTA)
    → behaviour as specified earlier:
        - has_access → first lesson
        - logged in, not enrolled → grant access + first lesson
        - guest → login with return-to first lesson

elif (course has format=klassikaal, 0 active editions):
    → show course info + small "Geen actieve edities" notice
    → NO CTA, NO interest form
```

This means:
- **Online courses without editions** are the only courses with a self-enroll CTA on `/opleidingen/<slug>/`.
- **Mixed-format courses** (online + 1+ active edition) hide the self-enroll CTA. The active edition wins. To enroll online, the editor must end the edition first.
- **Klassikaal courses without active editions** are info-only. Visitors who want to enroll wait.

## Sidebar logic consolidation

The `sidebar-online.php` template name becomes a misnomer once it also serves klassikaal courses. Rename or split:

- [ ] Decide: rename `sidebar-online.php` to `sidebar-course.php` (generic), or split into `sidebar-course-online.php` / `sidebar-course-klassikaal.php`. Probably one generic file with internal branching — fewer files, the branching logic is small.

## Open questions

(none — decisions all locked above)
