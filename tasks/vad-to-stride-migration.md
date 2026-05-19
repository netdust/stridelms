# VAD → Stride Migration Plan

Discovered while running Stride against VAD's production DB (`~/Sites/vad-vormingen/backups/prod-db-clean.sql`) on 2026-05-19. Direction: adapt VAD data to fit Stride. Never modify Stride to fit VAD.

---

## Phase 0 — Preconditions (already known)

- [x] Snapshot Stride DB before any swap (`ddev snapshot --name=pre-vad-test-2026-05-19`)
- [x] Import VAD prod-db-clean.sql into Stride DDEV
- [x] Set `DB_PREFIX=ckqp_` in Stride's `.env` (Bedrock reads via `env()` in `config/application.php:96`)
- [x] Confirm Stride's mu-plugins (`stride-core`, `ntdst-core`) load against VAD prefix without changes

**Rollback:** `ddev snapshot restore pre-vad-test-2026-05-19` + revert `.env` to `DB_PREFIX=wp_`.

---

## Phase 1 — Bootstrap (site loads)

- [x] **Activate Stridence theme**
  `wp theme activate stridence`
- [x] **Activate the full Stride plugin stack via SQL** (WP-CLI can't activate plugins while bootstrap fatals on missing `AuditService`)
  Write `active_plugins` directly with the serialized list:
  - fluent-crm, fluent-smtp, fluentform
  - sfwd-lms, tin-canny-learndash-reporting
  - netdust-lti, netdust-mail
  - ntdst-assistant, ntdst-audit, ntdst-auth
  Use PHP `serialize()` — never hand-count byte lengths.
- [x] **Do NOT activate learndash-hub** — merged into LearnDash 5.x core, re-activating causes `Cannot redeclare learndash_hub_install()` fatal.
- [x] Flush rewrites + cache

---

## Phase 2 — Page seeding (structural pages)

Stride templates (`page-online.php`, `page-klassikaal.php`, `page-mijn-account.php`) only fire when a WP page exists with the matching slug. VAD's DB has none of them.

Slugs to create as published pages:

| Slug | Title | Source |
|---|---|---|
| `mijn-account` | Mijn account | template `page-mijn-account.php` |
| `klassikaal` | Klassikaal | template `page-klassikaal.php` |
| `online` | Online | template `page-online.php` |
| `agenda` | Agenda | linked from theme `home_url('/agenda/')` |
| `contact` | Contact | linked from theme |
| `faq` | Veelgestelde vragen | linked from theme |
| `opleidingen` | Opleidingen | linked from theme |
| `over-ons` | Over ons | linked from theme |
| `privacy` | Privacybeleid | linked from theme |
| `trajecten` | Trajecten | linked from theme |
| `voorwaarden` | Algemene voorwaarden | linked from theme |

- [x] Seed all 11 pages (`get_page_by_path()` + `wp_insert_post()` loop)
- [x] Flush rewrites

**NOT pages** (handled by `ntdst_router`, no DB row needed): `/aanmelden/`, `/registreren/`, `/auth/verify/<token>`, `/auth/activate/<token>`, `/uitloggen`.

---

## Phase 3 — Taxonomy mapping

Stride hard-codes `stride_format` taxonomy with slugs `online`, `klassikaal`, `e-learning`, `webinar` in:

- `Modules/Edition/EditionService.php:162`
- `Modules/Edition/Admin/EditionAdminController.php:1017, 1300`
- `Modules/User/UserDashboardService.php:571, 770`
- `themes/stridence/page-online.php:46`

VAD's equivalent is `course_locatie` with terms `vad` (206), `op-locatie` (70), `online` (35).

### Lessons from the experiment (read first before running)

**Pitfall: `course_locatie` is NOT a format taxonomy.** It's a delivery channel — terms like `vad`, `op-locatie`, `online` mix "where it happens" with "how it's delivered". A webinar gets `online`, but it's not an e-learning. Renaming `course_locatie` → `stride_format` puts webinars in the e-learning catalog and is **wrong**. (Tried it 2026-05-19, reverted.)

**The real format source is `ld_course_category`:**
- `online-aanbod` (top-level) = the e-learnings → `stride_format/online`
- `vad-vormingen` (top-level) = the classroom courses → `stride_format/klassikaal`

**Filter chips on `/online/` come from `stride_theme`, NOT from format.** The sub-categories under `online-aanbod` in `ld_course_category` are what the editor wants to use as chips. Mirror those sub-cats into `stride_theme` so Stride's existing filter UI works without code changes.

**Additive, never destructive.** We don't rename `ld_course_category` (LearnDash owns it). We add Stride's taxonomies on top of VAD's existing terms.

### Migration steps to actually run

- [ ] **Format mapping** (`stride_format`, top-level `ld_course_category` → format):
  - `ld_course_category/online-aanbod` → `stride_format/online`
  - `ld_course_category/vad-vormingen` → `stride_format/klassikaal`
  - Decide what to do with `productinformatie`, `trainings-eupc`, `trainingen-uitgaansleven`, `trainingen-sportclubs` at top level if they're not nested under `online-aanbod` in production — they may be their own catalog category, not e-learning.

- [ ] **Theme/chip mapping** (`stride_theme`, sub-categories under `online-aanbod` → chips on `/online/`):
  - `middelengebruik-preventie-en-hulpverlening`
  - `productinformatie`
  - `trainingen-sportclubs` (named "Sportclubs")
  - `trainings-eupc`
  - `trainingen-uitgaansleven` (named "Uitgaansleven")
  - Note: total via sub-cats during the dry-run was 31; `online-aanbod` had 36 courses. The 5-course gap means some e-learnings are tagged with the parent only and won't show under any chip until they get a sub-cat. Confirm whether to (a) auto-assign a default chip, (b) leave them unfiltered, or (c) fix upstream in VAD.

- [ ] **Equivalent for `/klassikaal/`**: sub-categories under `vad-vormingen` (`basisvormingen` 24, `verdiepende-vormingen` 17, `studiedagen` 2, `ervaringsuitwisseling` 2, `train-de-trainers` 1) → mirror into `stride_theme` if the classroom page uses the same chip pattern. Verify by reading `page-klassikaal.php` first.

- [ ] **Commit the mapping as a script**: `scripts/migrate-vad-to-stride/03-taxonomy.php` — idempotent (uses `get_term_by` before insert, `wp_set_object_terms` with append=true).

- [ ] **Don't touch `course_locatie`** during migration. VAD's editors may still use it for reporting/filtering inside admin even if Stride ignores it.

### What was done during the dry-run (2026-05-19)

- [x] **Reverted** the wrong `course_locatie` → `stride_format` rename
- [x] **Format**: tagged 36 e-learnings as `stride_format/online`, 354 classroom as `stride_format/klassikaal` (additive from `ld_course_category`)
- [x] **Themes**: mirrored 5 sub-categories under `online-aanbod` into `stride_theme` (31 courses tagged)
- [x] `/online/` renders 36 e-learnings with filter chips at top ✓
- [ ] `/klassikaal/` not verified yet — sub-categories under `vad-vormingen` not yet mirrored

---

## Phase 4 — moved into Phase 6 (Users)

User activation flag handling now lives with the rest of user-meta work in Phase 6. This section kept for numbering continuity.

---

## Phase 5 — E-learning surfaces

Once taxonomy is renamed (Phase 3), online courses already appear at `/online/` because Stride's e-learning catalog uses `sfwd-courses` directly (no edition layer required). Most VAD e-learnings are "pure-LD" — student lands on the LD course page and works through lessons + quizzes.

- [ ] Verify `/online/` lists VAD's 35 e-learning courses
- [ ] Click into a course, confirm LD lessons render under Stridence theme
- [ ] Confirm LD progress + completion still tracked (existing `ckqp_learndash_user_activity` rows should just work)
- [ ] Confirm certificate generation still works for completed e-learnings (VAD has 2 `sfwd-certificates` posts)
- [ ] Check `archive-sfwd-courses.php` shows the right CTAs to `/klassikaal/` vs `/online/`

**Per `lesson_ld_owns_completion.md`**: LD enforces completion rules. For pure-LD e-learnings this is fine — students complete by finishing lessons/quizzes. For in-person courses with required LD lessons, Stride defers to LD's rules; audit later.

---

## Phase 6 — Users + user meta ✅ DONE (dry-run 2026-05-19)

VAD stores user data across **three layers** in priority order:
1. **FluentCRM** — main fields (`first_name`, `last_name`, `email`, `phone`) on `ckqp_fc_subscribers`; custom fields serialized in `ckqp_fc_subscriber_meta.key='custom_values'`
2. **BuddyBoss xprofile** — `ckqp_bp_xprofile_data` keyed by `field_id`
3. **WP usermeta** — `ckqp_usermeta`, mostly via GetPaid's `_wpinv_*` prefix

**Precedence rule (verbatim from VAD's `FluentCRM_UsersManager::get_field`):**
First non-empty value wins down the chain. Mirror VAD's decorator order:
`FluentCRM → XProfile → GetPaid_Customers → GetPaid_Users → WP`.

**Critical gotcha**: `xprofile_get_field_data()` doesn't exist on Stride because BuddyBoss isn't installed. Read directly from `ckqp_bp_xprofile_data` table.

### What was done

- [x] **Activated all 25,156 users**: `INSERT INTO ckqp_usermeta SELECT ID, 'ntdst_auth_activated', '1' FROM ckqp_users` (idempotent via `LEFT JOIN ... IS NULL`)
- [x] **Migrated user meta** with `scripts/migrate-vad-user-meta.php` (idempotent, batches of 500). Result: 25,690 new meta writes across 13 keys.
- [x] **Verified** with random 8-user audit — 100% match against expected precedence (88 field checks, zero mismatches)

### Mapping table (locked, see migrate-vad-user-meta.php `$map`)

| Stride usermeta | FluentCRM | XProfile ID | WP usermeta |
|---|---|---|---|
| `first_name` | main | 1 | `first_name` |
| `last_name` | main | 2 | `last_name` |
| `phone` | main | 141 | `_wpinv_phone` |
| `organisation` | custom `organisatie` | 146 | — |
| `department` | custom `afdeling` | — | — |
| `gln_number` | custom `gln_nummer` | 157 | — |
| `billing_company` | custom `facturatie_naam_organisat` | 147 | `_wpinv_company` |
| `billing_address_1` | custom `facturatie_adres` | 12 | `_wpinv_address` |
| `billing_postcode` | custom `facturatie_postcode` | 15 | `_wpinv_zip` |
| `billing_city` | custom `facturatie_stad` | 14 | `_wpinv_city` |
| `billing_vat` | custom `btw_ondernemingsnummer` | 16 | `_wpinv_vat_number` |
| `invoice_email` | custom `facturatie_email` | 151 | `_wpinv_email_cc` |
| `_stride_company_id` | custom `winbooks_id` | 148 | `_wpinv_company_id` |

`_stride_company_id` is the **external Winbooks ID**, copied verbatim. Not a WP post ID, just an opaque identifier used for partner-scoping in `CompanyAffiliation` + Partner API.

`organisation` ≠ `billing_company` — never fall back from one to the other. Per CLAUDE.md.

### Open items (deferred)

- [ ] Backfill `ntdst_auth_activated_at` from `user_registered` timestamp for audit consistency
- [ ] Verify `partner` role users have `_stride_company_id` set (already migrated, but spot-check on production data — some might have null winbooks_id)
- [ ] **Role reconciliation** — confirm Stride roles exist in VAD's `ckqp_user_roles` option; investigate VAD-specific roles (`instructor` from old ld-hub addon)
- [ ] Spot-check the 3 `_wpinv_email_cc` users for `invoice_email` — that column is rare; may not capture all VAD invoice-email cases
- [ ] Consider migrating `_wpinv_first_name` / `_wpinv_last_name` separately if needed (Stride uses `first_name`/`last_name` from WP core, which is already populated for 25,156 users)

---

## Phase 7 — Permalink + URL config

VAD's `permalink_structure` is `/%category%/%postname%/`. Stride defaults to `/%postname%/`. Either:

- [ ] Change permalink to `/%postname%/` and flush (cleaner, but breaks any deep links from emails/SEO pointing at the old structure)
- [ ] Or leave as-is and verify Stride's CPT rewrites still resolve (they probably do — CPTs have their own `slug` config)

Also:
- [ ] Set `stride_url_slugs` option if the defaults (`vormingen`, `trajecten`) don't match VAD's expected slugs.

---

## Phase 8 — Theme + page content

Pages seeded in Phase 2 have empty `post_content`. Templates that use `the_content()` (most "about/contact/legal" pages) will look bare.

- [ ] Decide for each page: hard-coded template, or block content that needs migrating from VAD's existing pages?
- [ ] If VAD has equivalent pages in its DB (e.g. `about-vad`), search-replace the slug or copy `post_content` over

---

## Phase 9 — Integrations (FluentCRM, Exact Online, FluentForm)

- [ ] Exact Online integration: VAD has it, Stride has a placeholder. Confirm credentials, mapping.
- [ ] FluentCRM contact data: probably already populated in VAD's DB. Confirm tag/list structure matches Stride's expectations.
- [ ] FluentForm forms: VAD's forms work; Stride uses different form schemas in some modules (intake, evaluation).

---

## Phase 10 — Editions for future courses

Stride generates new editions going forward. VAD has zero `vad_edition` posts. Decision: don't backfill historical editions; create editions only for upcoming/future scheduled courses.

- [ ] Identify which VAD courses have a "next instance" coming up (likely in LD groups, course meta, or a planning sheet outside the DB)
- [ ] For each future instance, create one `vad_edition` post:
  - Title: derived from course + date
  - Linked course: existing `sfwd-courses` post
  - Meta: price, capacity, start/end date, venue, session slots (`_ntdst_session_slots` per `pattern_keuzecursus_session_slots.md`)
  - Format taxonomy term: `klassikaal` (or `online` for hybrid webinars)
- [ ] Create `vad_session` posts for each meeting day per edition
- [ ] Verify `/vormingen/` archive populates
- [ ] Verify `/klassikaal/` shows editions, not bare courses

**No historical edition backfill** — past in-person courses stay as-is in VAD's archives. Stride only renders what's enrollable now.

---

## Phase 11 — Active enrollments (THE HARD PART)

In-flight enrollments. People who registered under VAD and haven't completed yet. They need to keep their progress, their quotes, their form responses, their certificate paths.

- [ ] Identify in-flight enrollments. Source TBD — likely:
  - LD groups + `ckqp_learndash_user_activity` for who's enrolled in what
  - VAD's quote/invoice data (probably WPI or custom — `wpi_item` has 224 rows)
  - FluentForm submission tables for the original registration form data
- [ ] For each active enrollment:
  - Create the matching `vad_edition` (if Phase 10 hasn't covered it) and `vad_session` posts
  - Insert row in `ckqp_vad_registrations` with: `user_id`, `edition_id`, `status='confirmed'`, `enrollment_path` (most likely `individual`), `enrolled_by`, `registered_at`
  - Backfill `enrollment_data` JSON with form responses (the *actual* problem — see below)
  - Backfill `selections` if the course is a keuzecursus
  - Backfill `selections_locked_at` if applicable
- [ ] Reconcile attendance: backfill `ckqp_vad_attendance` from VAD's existing tracking
- [ ] Reconcile quotes: link `quote_id` if quote system migrates

**The form-data problem (Stefan's flag):**
VAD collected enrollment form data via FluentForm. Stride stores form responses in `enrollment_data` JSON keyed by Stride's questionnaire field IDs. The mapping VAD-form-field-name → Stride-questionnaire-field-id is not obvious and probably needs per-course or per-form decisions. This is the actual work, not a script.

- [ ] Decide migration strategy per active enrollment:
  - (a) Migrate form data field-by-field with a hand-built mapping
  - (b) Store VAD's raw form data in `notes` or a custom meta, accept that Stride's questionnaire UI won't show it
  - (c) Email each in-flight enrollee to re-submit the Stride form, accept the friction
- [ ] Build the chosen migration path

---

## Phase X — `_ld_price_type='vad'` cleanup

VAD has a custom LearnDash price type `'vad'` registered by `vad-vormingen-v3.0` plugin (`VAD_Settings_Metabox_Course_Access.php`). Stride doesn't ship that plugin, so the type does nothing here. What's visible during the dry-run:

- LD's default fallback button still renders ("Take this course")
- Stride's sidebar adds its own enrollment CTA → `/vormingen/<slug>/inschrijving/`
- Two CTAs side-by-side on the course page, breadcrumb on the enrollment page reads "Inschrijving" (correct for Stride, but reads like a detour after clicking LD's button first)

This is **not a Stride bug** — it's the consequence of leaving VAD's custom price-type value in the DB without VAD's plugin to handle it.

### The rule (Stefan, 2026-05-19)

> "_ld_price_type for online courses need to be set to `open`, unless they actually have a form behind them. There are a few. The latter will be the online courses with edition so users have to fill in form before enrollment."

Refined to LD's binary (after looking at `LearnDashHelper::getAccessMode`):

- **`open`** = no account needed, self-enroll on click. Used for **pure-LD e-learnings** without an edition (33 courses in this DB).
- **`closed`** = LD won't enroll anyone itself; access is granted server-side after Stride's enrollment flow (`EnrollmentService::enroll()` → `LearnDashService::grantAccess()`). Used for:
  - **Form-gated online courses** (the 3 `Quality Nights`/`Sportivos` cases)
  - **All klassikaal courses** (354 in this DB) — always edition-driven

`free` mode is **not used** in Stride's model; it sits between open and closed and offers no value here. The 33 currently at `free` should flip to `open`.

### State of the data (dry-run snapshot 2026-05-19)

Across the 36 published courses tagged `ld_course_category/online-aanbod`:
- **33 at `_ld_price_type=free`** — already correct shape (LD-only enrollment); will need to flip to `open` if Stride treats `free` as different (verify)
- **3 at `_ld_price_type='vad'`** — these are the form-gated ones:
  - 2669 `Quality Nights - Festivals`
  - 2758 `Sportivos - Verantwoord alcohol schenken in de sportclub`
  - 3540 `Quality Nights - Clubs`

Across all 390 published `sfwd-courses` site-wide:
- 357 at `'vad'`, 33 at `'free'` — the 357 are mostly classroom courses (354 are tagged `vad-vormingen`).

### Migration steps

- [ ] **Pure-LD e-learnings** (under `online-aanbod`, no edition planned): flip `_ld_price_type` → `open`
- [ ] **Form-gated e-learnings** (3 known IDs: 2669, 2758, 3540 — re-audit at migration time): flip `_ld_price_type` → `closed`. They need a Stride edition + intake form attached.
- [ ] **Klassikaal courses** (under `vad-vormingen`): flip `_ld_price_type` → `closed`. Stride's edition + registration flow handles gating per edition.
- [ ] LD groups (`post_type='groups'`) have their own `_ld_price_type` postmeta — apply the same rule to any group used to deliver courses.
- [ ] Verify after flipping: single course pages show only Stride's CTA, no duplicate LD button; for `closed` courses, confirm the LD "Take this course" button is suppressed and access requires Stride enrollment.

### SQL (template — verify counts before running)

```sql
-- Open: pure-LD e-learnings (online-aanbod, NOT in the form-gated list)
UPDATE ckqp_postmeta pm
JOIN ckqp_posts p ON pm.post_id = p.ID
JOIN ckqp_term_relationships tr ON p.ID = tr.object_id
JOIN ckqp_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
JOIN ckqp_terms t ON tt.term_id = t.term_id
SET pm.meta_value = 'open'
WHERE pm.meta_key = '_ld_price_type'
  AND p.post_type = 'sfwd-courses'
  AND p.post_status = 'publish'
  AND tt.taxonomy = 'ld_course_category'
  AND t.slug = 'online-aanbod'
  AND p.ID NOT IN (2669, 2758, 3540);

-- Closed: form-gated e-learnings
UPDATE ckqp_postmeta SET meta_value = 'closed'
WHERE meta_key = '_ld_price_type' AND post_id IN (2669, 2758, 3540);

-- Closed: all klassikaal (vad-vormingen) courses
UPDATE ckqp_postmeta pm
JOIN ckqp_posts p ON pm.post_id = p.ID
JOIN ckqp_term_relationships tr ON p.ID = tr.object_id
JOIN ckqp_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
JOIN ckqp_terms t ON tt.term_id = t.term_id
SET pm.meta_value = 'closed'
WHERE pm.meta_key = '_ld_price_type'
  AND p.post_type = 'sfwd-courses'
  AND p.post_status = 'publish'
  AND tt.taxonomy = 'ld_course_category'
  AND t.slug = 'vad-vormingen';
```

List of form-gated IDs is dry-run snapshot — re-audit at real-migration time, the set may have grown.

---

## Phase 12 — LearnDash content audit

Per `lesson_ld_owns_completion.md`: LD enforces completion rules. Stride defers.

- [ ] Audit VAD's `sfwd-courses` configs — find any in-person course with required LD lessons/quizzes
- [ ] Either reconfigure those courses, or accept that completion routing differs from VAD's current behavior

---

## Phase 13 — Stride fixes shipped during dry-run (2026-05-19)

The dry-run surfaced two real Stride bugs. Both fixed on this branch; both fixes ride forward into the production migration. **Not** VAD-specific.

### Fix 1: `LearnDashHelper::isEnrolled` access-from check applies to all modes

**File:** `web/app/mu-plugins/stride-core/Integrations/LearnDash/LearnDashHelper.php`

Was: only short-circuited on `course_X_access_from` for `MODE_OPEN`. For `MODE_FREE` it fell through to `sfwd_lms_has_access()` which returns FALSE for legacy enrollments with expired access windows. Catalog showed enrolled courses as "Beschikbaar".

Now: the access-from short-circuit runs for every mode. Progress > 0 is also a positive enrollment signal. 894 unit tests pass.

### Fix 2: URL structure rework — `/vormingen/` → `/edities/`, LD owns `/opleidingen/`

**Design:** `tasks/url-structure-rework.md`. **Key insight:** can't out-vote LD on its own permalink. LD hard-codes links to `/opleidingen/<slug>/`; previous role split (`a5f5aea9`) made Stride compete with that and lost.

Final model:
- `/opleidingen/<course-slug>/` — LD owns it, Stride decorates with sidebar/CTA
  - Active edition exists → editions list (no inline CTA)
  - No edition, online format → self-enroll CTA → `?enroll=1` grants LD access + bounces to first lesson
  - No edition, klassikaal → "Geen actieve edities" notice
- `/edities/<edition-slug>/` — Stride's transactional edition surface (renamed from `/vormingen/`)
- `/edities/<edition-slug>/inschrijving/` — form-aware enrollment (already form-aware via `enrollment_form` meta)
- `/vormingen/<anything>/` → 301 via WP canonical redirect (bonus, no manual rules needed)

**Files touched:**
- `StrideSettingsService.php` — default slug → `edities`
- `EditionRouter.php` — rewritten, only routes editions; redirects course-slug requests to `/opleidingen/`
- `single-sfwd-courses.php` — restored CTAs with format/edition branching
- `CourseEnrollHandler.php` (new) — `?enroll=1` handler on `/opleidingen/<slug>/`
- `sidebar-online.php`, `mobile-cta.php` — CTAs retargeted to `add_query_arg('enroll', '1', get_permalink($course_id))`
- `card-course.php` — links to `get_permalink($course)` (the canonical LD URL)
- `single-course-enrollable.php` — deleted (workaround template no longer needed)
- 20-file bulk `/vormingen/` → `/edities/` sed pass + register CourseEnrollHandler in plugin-config.php

**Test status:** 894/894 unit tests pass.

### Bugs documented but NOT fixed in code (recorded in memory for future agents)

- `bug_pureld_open_cta_loop` — superseded by URL rework above; can be closed
- (none others outstanding — both real bugs are fixed)

---

## What's confirmed working as-is (end of 2026-05-19)

After Phases 0–6 + URL rework:

- ✅ Site boots, homepage renders (Stridence theme)
- ✅ All 13 structural pages resolve (catalog, info, legal, auth)
- ✅ Magic-link login works for any user (all 25,156 activated)
- ✅ `/online/` shows 36 e-learnings with subject-filter chips
- ✅ `/klassikaal/` shows classroom courses (354 tagged)
- ✅ Course page `/opleidingen/<slug>/` decorated with Stride sidebar
- ✅ Online enroll flow: card → `/opleidingen/<slug>/` → CTA → `?enroll=1` → first lesson
- ✅ Catalog correctly badges historical enrollments as "Ingeschreven"/"X% voltooid"
- ✅ User data migrated with FluentCRM → XProfile → WP precedence, 100% audit match on 8 random users
- ✅ `_stride_company_id` populated for ~2,549 users (every user with a billing profile)

## What's known broken / empty

- ❌ `/edities/` 404 — no `vad_edition` posts yet (Phase 10)
- ❌ `/mijn-account/` (authed) shows empty edition-based dashboard — `ckqp_vad_registrations` empty (Phase 11)
- ❌ Seeded pages have no content (Phase 8 — content migration)
- ❌ Quotes, attendance, certificates surfaces blank until Phase 10 + 11 done
- ❌ `_ld_price_type='vad'` still on 357 courses — needs flip to `open` per Phase X (or `free`)

## Recommended next phases

1. **Phase X** — flip `_ld_price_type` for online + classroom courses. Easy SQL, immediate win on catalog UX.
2. **Phase 10** — generate `vad_edition` posts for upcoming courses.
3. **Phase 11** — backfill active enrollments from LD activity + FluentForm submissions (the hard one).

---

## Restore

```bash
cd ~/Sites/stride
ddev snapshot restore pre-vad-test-2026-05-19
# revert DB_PREFIX in .env back to wp_
```
