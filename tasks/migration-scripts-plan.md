# Migration Scripts Plan — Editions, Sessions, Registrations, Quotes

Final-mile of the VAD → Stride migration. Three scripts, run sequentially, each idempotent. Companion to `tasks/vad-to-stride-migration.md` (Phases 10 + 11 + quote subset of Phase 9).

**This is a plan. Scripts are not built yet.**

---

## Scope (decided 2026-05-19)

### Ongoing-enrollment definition

"Future + recent past 6 months" — measured against the **course's** `_next_course_date` / `_last_course_date` post meta. A course is in scope if:

- `_next_course_date >= today`, OR
- `_last_course_date >= today - 180 days`

Real numbers from the dry-run DB:

| Bucket | Courses |
|---|---|
| A. `_next_course_date` ≥ today | 29 |
| B. `_last_course_date` within last 6mo | 65 |
| C. Older | 296 |
| **In scope (A + B)** | **94** |

User scale: **1,130 users** hold a `course_X_access_from` meta for courses in scope (A + B).

### Quote scope

**Only quotes tied to migrated enrollments.** After registrations are created (Script 3), we walk `wpi_quote` rows and migrate the ones whose `user_id × edition_id` (or `user_id × course_id` if that's what wpi_quote stores) matches a freshly-created `vad_registrations` row. Other 1,244-N quotes stay in `wpi_quote`, unmigrated. Bookkeepers can still look them up in Exact for historical needs.

### Out of scope (deferred or never)

- The 296 "older" courses — their LD activity stays in `learndash_user_activity` for historical reference; no Stride edition/registration generated.
- `wpi_invoice` (2,241 rows). Stride doesn't do invoices.
- Form-data migration for in-flight enrollments where VAD's FluentForm submission needs to land in `enrollment_data`. **This is the hard problem from Phase 11** — solved separately, may be left blank with `notes` annotation.

---

## Script 1 — `migrate-vad-editions-and-sessions.php`

### Inputs

- VAD `sfwd-courses` posts in scope (94 of them)
- Their `_sfwd-courses` settings blob, `_next_course_date`, `_last_course_date`
- Stride's `stride_format` taxonomy (already populated in Phase 3)
- The format flip from Phase X (`_ld_price_type` now `open`/`closed`)

### Outputs

- ~94 new `vad_edition` posts
- ~200 new `vad_session` posts (avg ~2 meeting days per course via `sfwd-courses_course_days`)

### Field mapping (audited 2026-05-19)

| Stride edition meta | VAD source | Notes |
|---|---|---|
| `course_id` | the course post ID | direct |
| `start_date` | `_next_course_date` OR `course_days[0]` | timestamp → `Y-m-d` |
| `end_date` | `_last_course_date` OR `course_days[last]` | timestamp → `Y-m-d` |
| `capacity` | `sfwd-courses_course_max_participants` | int; empty → 0 (unlimited) |
| `price` | `sfwd-courses_course_pricing` (freeform) → parse digits OR `sfwd-courses_course_price` | fallback null |
| `venue` | `sfwd-courses_course_address` | direct |
| `speakers` | `sfwd-courses_course_supervisors` (freeform text) | preferred over `course_speakers` user-ID array |
| `selection_deadline` | `sfwd-courses_course_max_subscription_date` | direct, may be empty |
| `status` | derived from `course_status_*` flags | see status mapping |
| `enrollment_form` | from `_ld_price_type` after Phase X: `'open'` → `'direct'`, `'closed'` → `'default'` | derived |
| `requires_questionnaire` | true if `LearnDashCourseService::getCustomForm($courseId)` returns non-null | port VAD's lookup |
| `requires_approval` | false | sensible default |
| `requires_documents` | false | sensible default |
| `completion_mode` | `'automatic'` | sensible default |
| `session_slots` | empty | Mode 3 only, set by editor post-migration |
| `documents` | parse `sfwd-courses_course_materials` when `course_materials_enabled='on'` | attachment IDs — best effort |

### VAD course meta → Stride: gap audit

Fields VAD stores that Stride has no edition column for. Strategy per field:

| VAD field | Strategy | Reason |
|---|---|---|
| `course_target_audience` | **dump into edition `notes` JSON** under key `vad_target_audience` | Useful info, no Stride field, editor can promote to course description later |
| `course_desired_experience` | **dump into edition `notes`** under key `vad_desired_experience` | Same — prerequisite-level info |
| `course_accreditering` | **dump into edition `notes`** under key `vad_accreditering` | Important for medical/care professionals |
| `course_contact` | **dump into edition `notes`** under key `vad_contact_email` | Per-course contact person — Stride has no equivalent |
| `course_admins` | **dump into edition `notes`** under key `vad_admin_emails` | Internal admin list — Stride uses user roles |
| `course_when` | drop | Display-only "21 mei en 12 juni 2026" — already captured by start_date/end_date + sessions |
| `course_duration` | drop after time parse | Already extracted into session start_time/end_time |
| `course_points` / `course_points_enabled` | drop | LD-internal gamification, not used by Stride |
| `course_prerequisite_*` | drop | LD enforces these; Stride doesn't need to mirror |
| `course_disable_lesson_progression`, `course_lesson_*` | drop | LD content config, lives on the course, not the edition |
| `course_invoice_enabled` | drop | Stride decides via `_ld_price_type` + edition price |
| `course_status_certificate` | drop | LD-managed; certificate generation flows via LD |
| `course_subscriptions` | drop | Legacy WC integration field |

**`notes` field shape** (`vad_edition.notes` is a JSON array per the CPT spec):

```json
[
  {"type": "migration", "key": "vad_target_audience", "value": "Hulpverleners in de verslavingszorg...", "added": "2026-05-XX"},
  {"type": "migration", "key": "vad_accreditering", "value": "...", "added": "2026-05-XX"}
]
```

This way the data is visible in admin (the notes panel renders them), exportable, and clearly tagged as migration-sourced.

### Edition fields Stride has that VAD doesn't fill

These default to sensible empties; not blockers:
- `price_non_member` — VAD has only one price tier. Always null.
- `completion_threshold` — Stride concept, not VAD's. Defaults to whatever Stride's default is.
- `post_requires_evaluation`, `post_requires_documents`, `post_requires_approval` — all false by default; editor enables per edition as needed.

### Status mapping

VAD has 4 status flags on `_sfwd-courses`. Stride uses `OfferingStatus` enum. Mapping:

| VAD flag set | Stride `status` |
|---|---|
| `course_status_cancelled` | `cancelled` |
| `course_status_postponed` | `postponed` |
| `course_status_full` (no other flag) | `full` |
| `course_status_announcement` | `announcement` |
| No flag set, future date | `open` |
| No flag set, past date | `completed` |

### Session mapping (two modes — Mode 3 keuzecursus deferred to editors)

VAD encodes sessions two ways. Real keuzecursussen are rare (~1-2 in scope); admins fix those manually post-migration via the existing `_ntdst_session_slots` UI per [[pattern_keuzecursus_session_slots]]. Script doesn't try to detect.

**Mode 1: simple multi-day** (most common)
- Signal: `course_days` populated, `course_program_enabled !== 'on'` OR `course_program` is empty
- Action: one `vad_session` per timestamp in `course_days`
- Times parsed from course-level `course_duration` regex

**Mode 2: programmed course** (rich per-entry descriptions)
- Signal: `course_program_enabled === 'on'` AND `course_program` is non-empty
- Action: one `vad_session` per entry in `course_program`. Often more program entries than `course_days` timestamps (e.g. 5 entries, 4 days — the extra is self-paced/online with no fixed date)
- Per-session times/locations/speakers parsed from `program_description` freeform text — best-effort extraction. What can't be parsed → full `program_description` lands in session `description` so editors see it. Zero data loss.

**Session field mapping:**

| Stride session meta | Mode 1 source | Mode 2 source |
|---|---|---|
| `edition_id` | edition we just created | same |
| `date` | `course_days[i]` timestamp | parsed `(\d{1,2}\/\d{1,2}\/\d{2,4})` from `program_description`, fallback to `course_days[i]` if index aligns, fallback empty |
| `start_time` / `end_time` | parsed from `course_duration` | parsed from `program_description`, fallback to course-level `course_duration` |
| `location` | edition's `venue` | parsed `Locatie: ...` line, fallback to edition `venue` |
| `description` | empty | full `program_description` text |
| `type` | `'in_person'` (klassikaal) or `'webinar'` (online) from `stride_format` | same default + detect "Online" / "MS Teams" / "https://" in description to override per session |
| `capacity` | inherit edition | inherit edition |
| `slot` | empty | empty (editor sets manually for the rare keuzecursus) |

### Idempotency

- Skip if a `vad_edition` post exists with meta `_ntdst_course_id = $courseId` AND `_ntdst_start_date = $startDate` (same course + same start date = same edition).
- Sessions: skip if a `vad_session` post exists with `_ntdst_edition_id = $editionId` AND `_ntdst_date = $sessionDate`.

### Validation after run

- `SELECT COUNT(*) FROM ckqp_posts WHERE post_type='vad_edition'` → ~94
- `SELECT COUNT(*) FROM ckqp_posts WHERE post_type='vad_session'` → ~200
- Spot-check 5 random editions: visit `/edities/<slug>/` — page renders with correct course, dates, venue, speakers
- `/klassikaal/` shows ≈65 cards (klassikaal future + recent — minus any cancelled/archived)
- `/online/` may grow slightly if any in-scope course is also tagged `stride_format=online`

---

## Script 2 — `migrate-vad-registrations.php`

**Depends on Script 1** (needs the new `vad_edition` posts).

### Inputs

- `ckqp_usermeta` rows where `meta_key LIKE 'course_%_access_from'` AND the course is in scope
- Optionally: `learndash_course_X_enrolled_at` (more accurate enrollment timestamp than `access_from`)
- The 94 new `vad_edition` posts indexed by `_ntdst_course_id`

### Outputs

- ~1,130 new rows in `ckqp_vad_registrations`

### Field mapping

| `wp_vad_registrations` column | Source |
|---|---|
| `user_id` | from the usermeta `user_id` |
| `edition_id` | lookup: the edition matching `course_id = X` |
| `trajectory_id` | null (no trajectory migration in this pass) |
| `status` | derived from LD progress: `>=100% → completed`, `>0% → confirmed`, `=0% → confirmed` (assume access_from = paid/enrolled) |
| `enrollment_path` | `'individual'` (we have no signal for `colleague` / `trajectory` from VAD's data) |
| `selections` | null (no keuzecursus session-pick data from VAD) |
| `selections_locked_at` | null |
| `quote_id` | filled later by Script 3 |
| `company_id` | `_stride_company_id` user meta (already migrated in Phase 6) |
| `enrolled_by` | null (no signal of admin-enrollments from VAD) |
| `registered_at` | `learndash_course_X_enrolled_at` if present, else `course_X_access_from`, else now() |
| `completed_at` | `course_completed_X` if present, else null |
| `cancelled_at` | null |
| `notes` | `'Migrated from VAD on YYYY-MM-DD'` audit marker |
| `completion_tasks` | null (no signal) |
| `enrollment_data` | **`{"migrated_from_vad": {...VAD FluentForm JSON as-is...}}`** — see "Form-data approach" below. |

### Form-data approach (resolved 2026-05-19)

VAD's FluentForm submissions are already structured JSON. We don't need to map VAD form fields → Stride questionnaire IDs. Just dump the raw submission into `enrollment_data` under a reserved key.

**Storage shape:**

```json
{
  "migrated_from_vad": {
    "submission_id": 12345,
    "submitted_at": "2026-03-15 14:22:01",
    "form_id": 7,
    "form_title": "Inschrijving Basisvorming",
    "fields": {
      "naam": "Jan Vermeulen",
      "functie": "Hulpverlener",
      "organisatie": "CAW Antwerpen",
      "...": "..."
    }
  }
}
```

New Stride enrollments continue to use the existing stage-keyed shape (`enrollment_personal`, `intake`, `evaluation`). The two coexist — a migrated row has only `migrated_from_vad`; a Stride-native row has only the stages; a migrated row that later collects post-enrollment intake/evaluation has both.

**Migration step**:
1. Build a lookup `course_id → FluentForm form_id`. **VAD code already does this** in `services/Learndash/LearnDashCourseService::getCustomForm($courseId)` (port the logic verbatim, no need to invent):
   - Read LD setting `course_price_type_vad_custom_form` (new location) or `course_extraform_item` (legacy fallback) on the course
   - The stored value is the **form title**, not the ID
   - Lookup `ckqp_fluentform_forms` by title → get the form_id
2. Pull submissions from `ckqp_fluentform_submissions WHERE form_id = ? AND (user_id = ? OR JSON_EXTRACT(response, '$.email') = user's email)` — VAD matches on user_id OR email-in-response since FluentForm sometimes stores guest submissions before account creation.
3. Decode `response` column (FluentForm stores serialized PHP). Normalise to the shape above — strip nothing, preserve all field labels.
4. If no submission found for a given user × course → leave `enrollment_data = null` (user enrolled without filling the form, or form-less course).

**Exporter integration** (separate code change, post-migration):
- `EditionRegistrationExporter::writeAllSheets()` adds a conditional 8th sheet "Vorige inschrijfgegevens" that fires when any registration has `enrollment_data.migrated_from_vad`.
- Each row dumps the original VAD submission with its field labels intact.
- No mapping logic — editor sees the raw migrated data as one column per VAD form field, alongside the Stride-native sheets.

### Idempotency

- Skip if a registration row exists with `user_id = X` AND `edition_id = Y`.

### Validation

- `SELECT COUNT(*) FROM ckqp_vad_registrations` → ~1,130
- Spot-check 5 users: log in, visit `/mijn-account/`, see their enrolled courses appear under "Opleidingen" tab
- `LearnDashHelper::isEnrolled` returns true for them (after Phase 13 fix this works regardless of access window expiry)

---

## Script 3 — `migrate-vad-quotes.php`

**Depends on Script 2** (needs `vad_registrations` rows to filter quote scope).

### Inputs

- `ckqp_posts` rows where `post_type = 'wpi_quote'`
- Their `_wpinv_*` postmeta and `wpi_items` rich-data (GetPaid item-list serialized in `_wpinv_items`)
- The new `vad_registrations` rows

### Output

- Some subset of 1,244 `wpi_quote` rows migrated to `vad_quote`. Expected: a few hundred (anything tied to in-scope enrollments).

### Field mapping

Need to inspect a real `wpi_quote` row to confirm shape. Initial guess based on the top meta keys (`_wpinv_payment_form`, `_wpinv_company_id`, `_wpinv_discounts`, etc.):

| Stride `vad_quote` meta | Source |
|---|---|
| `user_id` | `wpi_quote.post_author` or `_wpinv_user_id` (verify) |
| `registration_id` | lookup: registration matching `user_id × edition_id` (need to derive edition from wpi_quote line items) |
| `edition_id` | derived from wpi_quote line items (item → course → edition we created) |
| `quote_number` | `wpi_quote.post_title` or `_wpinv_number` (verify) |
| `status` | map wpi_quote post_status to Stride QuoteStatus enum (Draft/Sent/Accepted/Cancelled) |
| `items` | parse `_wpinv_items` serialized → Stride items JSON shape |
| `subtotal`/`discount`/`tax`/`total` | from `_wpinv_subtotal` / `_wpinv_discount` / `_wpinv_tax` / `_wpinv_total` (×100 to cents) |
| `billing` | snapshot of user's billing meta at quote time (`_wpinv_company`, `_wpinv_address`, etc.) |
| `voucher_code` | `_wpinv_discount_code` if present |
| `valid_until` | `_wpinv_due_date` if present |
| `sent_at` | `_wpinv_sent_date` if present |
| `pdf_path` | null (regenerate fresh PDFs in Stride later if needed) |
| `locked` | true (historical quotes are sealed) |
| `notes` | `'Migrated from wpi_quote #N on YYYY-MM-DD'` |

### Mapping wpi_quote → registration

The hardest link. Path:
1. Read `wpi_quote.post_author` → user_id
2. Parse `_wpinv_items` → list of line items, each linked to an `wpi_item` post
3. Each `wpi_item` corresponds to a course (need a course-id meta on wpi_item to confirm)
4. Look up the migrated edition for that course
5. Look up the registration matching `user_id × edition_id`
6. If found → link the quote. If not (user wasn't enrolled or not in scope) → skip.

**Investigation needed during script writing:** what links a `wpi_item` to a `sfwd-courses` post? Likely a usermeta or a hardcoded ID in the course settings (`sfwd-courses_course_invoice_item` was `"14608"` in the sample course we inspected).

### Idempotency

- Skip if `vad_quote` exists with meta `_ntdst_source_wpi_quote_id = $wpiQuoteId`.

### Validation

- Count of `vad_quote` rows after run vs count of in-scope wpi_quote rows
- Spot-check 5 users with quotes: visit `/mijn-account/` → "Offertes" tab → see migrated quotes
- Check PDF link is null (no broken-PDF errors in the admin)

---

## Sequencing + safety

```
1. (already done) Phase 6 user meta migration
2. (already done) Phase X _ld_price_type flip
3. NEW: Script 1 — editions + sessions
4. Spot-check editions render at /edities/<slug>/
5. NEW: Script 2 — registrations
6. Spot-check users see their courses on /mijn-account/
7. NEW: Script 3 — quotes (scoped to migrated registrations only)
8. Final QA: log in as 3 random in-flight users, walk through their dashboard, edition page, lessons
```

All three scripts are idempotent — safe to re-run. Snapshot the DB before running each (`ddev snapshot --name=pre-script-N`).

## What we still need to investigate during script-writing

1. **Time-string parsing**: VAD's `course_duration` ("van 9:30u tot 16:00u, onthaal met koffie vanaf 9:00u") + per-session times in `program_description`. Build + test regex on the 94 in-scope courses; manually fix outliers.
2. **Status flag → enum mapping**: sample all 94 in-scope courses, see which `course_status_*` flag combinations actually occur. Most are likely "no flag = open"; the script logs anything ambiguous.
3. **Price-string parsing**: `course_pricing` is often descriptive ("Deel van aanbod tweejarige opleiding"). Strategy: extract first `€?\s*\d+(?:[,.]\d{2})?` if present, else null. Outliers get logged and editor sets manually.
4. **wpi_item → course link**: confirm `sfwd-courses_course_invoice_item` is the canonical bridge (we saw `"14608"` in one sample — a wpi_item ID). Walk a real wpi_quote: read its `_wpinv_items`, look up each line item's `wpi_item` post, check what links back to a `sfwd-courses`.
5. ~~**Course → FluentForm form_id link**~~ — RESOLVED: port VAD's `LearnDashCourseService::getCustomForm()`.
6. ~~**Keuzecursus detection**~~ — RESOLVED: not detected by script. Real keuzecursussen are ~1-2 in scope; admins fix them post-migration via the existing `_ntdst_session_slots` admin UI.
7. **Multi-line `program_description` parsing**: each program entry has freeform Dutch text mixing date, time, location, speakers. Regex per line:
   - Date: `(\d{1,2})\/(\d{1,2})\/(\d{2,4})` → ISO date
   - Time: `(\d{1,2})[\.:]?(\d{0,2})u?\s*tot\s*(\d{1,2})[\.:]?(\d{0,2})u?`
   - Location: line starting `Locatie:` → trim
   - Speakers: line starting `Sprekers:` → trim
   Whatever can't be parsed → still preserved in `description` field. Zero data loss.

## Anti-goals

- **No live data**: scripts run against the dry-run DB first; production runs only after sign-off.
- **No retroactive changes to Stride code**: if mapping reveals a Stride bug (like the three we found earlier), log it in memory; fix is a separate change.
- **No deletes**: nothing in `wpi_quote`/`wpi_invoice`/`learndash_user_activity` gets deleted. Original VAD data stays as historical record.
