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

## Phase 4 — User activation flag

Stride's `ntdst-auth` requires `ntdst_auth_activated=1` user meta before magic-link login works. VAD users don't have it.

- [x] One-off: set for ntdst (user 1) for testing
- [ ] **Bulk set for all VAD users**:
  ```sql
  INSERT INTO ckqp_usermeta (user_id, meta_key, meta_value)
  SELECT ID, 'ntdst_auth_activated', '1' FROM ckqp_users
  WHERE ID NOT IN (SELECT user_id FROM ckqp_usermeta WHERE meta_key='ntdst_auth_activated');
  ```
- [ ] Optional: also set `ntdst_auth_activated_at` to a real timestamp for audit consistency

**Tradeoff:** Bulk-activating skips email consent confirmation. If VAD already collected consent, that's fine — we're just teaching Stride to recognise it.

---

## Phase 5 — Edition layer (the big one)

VAD has zero `vad_edition` posts. Stride's catalog, registration flow, dashboard, quote system all key off editions. This is the gap that defines "doable" — it requires generating edition posts from whatever VAD currently uses to represent scheduled course offerings.

**Investigation needed first:**
- [ ] Identify VAD's edition equivalent. Candidates: LearnDash groups (13 in DB), course meta fields (start dates?), the `wpi_item` post type (224 rows — pricing items?), or rows in some VAD-specific table.
- [ ] Map VAD's fields → Stride's edition meta keys (`_stride_edition_course`, `_stride_edition_price_cents`, `_stride_edition_capacity`, `_stride_edition_start`, etc.)
- [ ] Decide: one edition per LD group? Per cohort tag? Per course + scheduled session?
- [ ] Write a migration script that creates `vad_edition` posts with the right course linkage + meta

**Tables that already match Stride's schema** (lucky):
- `ckqp_vad_registrations` — identical columns, currently empty. Backfill from LD activity/groups.
- `ckqp_vad_attendance` — identical, empty. Backfill from session attendance if VAD tracked it.

**Stride-specific tables likely missing or empty:**
- [ ] Check whether `ckqp_vad_sessions` (CPT meta table) is needed for `vad_session` posts
- [ ] Check `ckqp_vad_quotes` / `vad_quote` CPT — VAD probably has separate Exact Online integration

---

## Phase 6 — Theme + page content

Pages seeded in Phase 2 have empty `post_content`. Templates that use `the_content()` (most "about/contact/legal" pages) will look bare.

- [ ] Decide for each page: hard-coded template, or block content that needs migrating from VAD's existing pages?
- [ ] If VAD has equivalent pages in its DB (e.g. `about-vad`), search-replace the slug or copy `post_content` over

---

## Phase 7 — Permalink + URL config

VAD's `permalink_structure` is `/%category%/%postname%/`. Stride defaults to `/%postname%/`. Either:

- [ ] Change permalink to `/%postname%/` and flush (cleaner, but breaks any deep links from emails/SEO pointing at the old structure)
- [ ] Or leave as-is and verify Stride's CPT rewrites still resolve (they probably do — CPTs have their own `slug` config)

Also:
- [ ] Set `stride_url_slugs` option if the defaults (`vormingen`, `trajecten`) don't match VAD's expected slugs.

---

## Phase 8 — Quotes, invoicing, integrations

Out of scope for boot-test, but real switch-over needs:

- [ ] Exact Online integration: VAD has it, Stride has a placeholder. Confirm credentials, mapping.
- [ ] FluentCRM contact data: probably already populated in VAD's DB. Confirm tag/list structure matches Stride's expectations.
- [ ] FluentForm forms: VAD's forms work; Stride uses different form schemas in some modules (intake, evaluation).

---

## Phase 9 — LearnDash content audit

Per `lesson_ld_owns_completion.md`: LD enforces completion rules (lessons, quizzes). Stride defers. In-person courses with required LD lessons cannot complete via attendance alone.

- [ ] Audit VAD's `sfwd-courses` configs — find any course that has required lessons/quizzes but is delivered in-person
- [ ] Either reconfigure those courses or accept that completion routing differs from VAD's current behavior

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

- ❌ `/vormingen/` 404 — no `vad_edition` posts (Phase 5)
- ❌ `/online/` shows 0 courses — taxonomy mismatch (Phase 3)
- ❌ `/mijn-account/` (authed) will show empty dashboard — empty registrations table (Phase 5)
- ❌ Seeded pages have no content (Phase 6)
- ❌ Quotes, attendance, certificates surfaces all blank until Phase 5 is done

---

## Restore

```bash
cd ~/Sites/stride
ddev snapshot restore pre-vad-test-2026-05-19
# revert DB_PREFIX in .env back to wp_
```
