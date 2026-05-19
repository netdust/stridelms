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

- [ ] **Rename taxonomy**: `UPDATE ckqp_term_taxonomy SET taxonomy='stride_format' WHERE taxonomy='course_locatie'`
- [ ] **Reconcile term slugs**:
  - `online` → keep
  - `op-locatie` → rename to `klassikaal` (`UPDATE ckqp_terms SET slug='klassikaal', name='Klassikaal' WHERE slug='op-locatie'`)
  - `vad` → decide: drop, fold into `klassikaal`, or leave as-is (visible nowhere if Stride only filters `[online, klassikaal, e-learning, webinar]`)
- [ ] Add `e-learning` and `webinar` terms if any VAD courses need them (probably not — VAD folded everything into `online`)
- [ ] Clear LD object cache (`wp cache flush`)
- [ ] Verify `/online/` shows the 35 online courses, `/klassikaal/` shows the 70 classroom ones

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

## Phase 6 — Users + user meta

VAD users carry over but they're missing Stride-specific meta. Stride uses some fields VAD doesn't, and reads some fields from different keys.

**Activation flag (required for login):**
- [x] One-off: set for user 1 (`ntdst_auth_activated=1`)
- [ ] **Bulk set for all VAD users:**
  ```sql
  INSERT INTO ckqp_usermeta (user_id, meta_key, meta_value)
  SELECT ID, 'ntdst_auth_activated', '1' FROM ckqp_users
  WHERE ID NOT IN (SELECT user_id FROM ckqp_usermeta WHERE meta_key='ntdst_auth_activated');
  ```
- [ ] Optional: backfill `ntdst_auth_activated_at` with `user_registered` timestamp for audit trail

**Personal vs billing fields (per CLAUDE.md — never conflate):**

| Stride field | Meta key | VAD has this? |
|---|---|---|
| organisation | `organisation` | TBD — investigate |
| department | `department` | TBD |
| company (billing) | `billing_company` | likely yes (WC/legacy) |
| address | `billing_address_1` | likely yes |
| postal_code | `billing_postcode` | likely yes |
| city | `billing_city` | likely yes |
| vat_number | `billing_vat` | TBD |
| invoice_email | `invoice_email` | TBD |
| gln_number | `gln_number` | likely no — VAD-specific |

- [ ] Audit `ckqp_usermeta` for what keys VAD actually populated
- [ ] Map any non-standard VAD keys → Stride's expected keys (SQL `UPDATE`s)
- [ ] **Critical:** Do NOT fall back `organisation` ← `billing_company`. They're separate concerns.
- [ ] Identify users with `partner` role and verify `_stride_company_id` is set (for Partner API access)

**Role check:**
- [ ] Confirm Stride roles exist in VAD's `ckqp_options` `ckqp_user_roles` row (administrator, subscriber, partner, etc.)
- [ ] Reconcile any custom VAD roles (e.g. `instructor` from `instructor-role` ld-hub plugin)

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

## Phase 12 — LearnDash content audit

Per `lesson_ld_owns_completion.md`: LD enforces completion rules. Stride defers.

- [ ] Audit VAD's `sfwd-courses` configs — find any in-person course with required LD lessons/quizzes
- [ ] Either reconfigure those courses, or accept that completion routing differs from VAD's current behavior

---

## What's confirmed working as-is

After Phases 0-2 + magic-link activation for user 1:

- ✅ Site boots, homepage renders (Stridence theme)
- ✅ `/klassikaal/`, `/online/`, `/agenda/`, `/contact/`, `/faq/`, `/opleidingen/`, `/over-ons/`, `/privacy/`, `/trajecten/`, `/voorwaarden/` all 200
- ✅ `/mijn-account/` 302 → `/aanmelden/` for guests (correct)
- ✅ `/aanmelden/`, `/registreren/` rendered via `ntdst_router` (no page seed needed)
- ✅ Magic-link login flow works once `ntdst_auth_activated` is set
- ✅ `ckqp_vad_registrations` + `ckqp_vad_attendance` tables already exist with Stride-compatible schema
- ✅ 390 `sfwd-courses` + 13 LD groups + ~all users carry over

## What's known broken / empty

- ❌ `/online/` shows 0 courses — taxonomy mismatch (Phase 3, next)
- ❌ `/vormingen/` 404 — no `vad_edition` posts (Phase 10)
- ❌ `/mijn-account/` (authed) will show empty dashboard — empty registrations table (Phase 11)
- ❌ Seeded pages have no content (Phase 8)
- ❌ Quotes, attendance, certificates surfaces all blank until Phase 10 + 11 done

---

## Restore

```bash
cd ~/Sites/stride
ddev snapshot restore pre-vad-test-2026-05-19
# revert DB_PREFIX in .env back to wp_
```
