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

### Field mapping (already audited in `vad-to-stride-migration.md` Phase 10)

| Stride edition meta | Source | Notes |
|---|---|---|
| `course_id` | the course post ID | direct |
| `start_date` | `_next_course_date` OR `course_days[0]` | timestamp → `Y-m-d` |
| `end_date` | `_last_course_date` OR `course_days[last]` | timestamp → `Y-m-d` |
| `capacity` | `sfwd-courses_course_max_participants` | int; empty → 0 (unlimited) |
| `price` | `sfwd-courses_course_pricing` numeric extract OR `sfwd-courses_course_price` | freeform text → parse digits, fallback null |
| `venue` | `sfwd-courses_course_address` | direct |
| `speakers` | `sfwd-courses_course_supervisors` (freeform text) | string preferred over user-ID array |
| `selection_deadline` | `sfwd-courses_course_max_subscription_date` | direct, may be empty |
| `status` | derived from `sfwd-courses_course_status_*` flags | see status mapping below |
| `enrollment_form` | from `_ld_price_type` after Phase X: `'open'` courses → `'direct'`, `'closed'` courses → `'default'` | derived |
| `requires_questionnaire` | true if VAD attached a FluentForm to the course | needs audit during run |
| `session_slots` | parse `sfwd-courses_course_program` when `course_program_enabled='on'` | rare — keuzecursussen only |
| `requires_approval` | false | sensible default |
| `requires_documents` | false | sensible default |
| `completion_mode` | `'automatic'` | sensible default |

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

### Session mapping

For each timestamp in `sfwd-courses_course_days`, create one `vad_session`:

| Stride session meta | Source |
|---|---|
| `edition_id` | the edition we just created |
| `date` | `course_days[i]` timestamp → `Y-m-d` |
| `start_time` / `end_time` | parsed from `sfwd-courses_course_duration` freeform string. Regex: `\d{1,2}[:hu]\d{0,2}u?\s*tot\s*\d{1,2}[:hu]\d{0,2}u?`. Fallback: empty. |
| `location` | inherited from edition's `venue` |
| `type` | `'in_person'` for klassikaal, `'webinar'` for online — derived from course's `stride_format` term |
| `capacity` | inherited from edition (empty) |

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

1. **Time-string parsing**: VAD's `sfwd-courses_course_duration` is freeform Dutch ("van 9:30u tot 16:00u, onthaal met koffie vanaf 9:00u"). Build + test regex on the 94 in-scope courses; manually fix outliers.
2. **Status flag → enum mapping**: which combinations are valid? Sample all 94 and see which flags are set.
3. **Price-string parsing**: `sfwd-courses_course_pricing` is often a descriptive string ("Deel van aanbod tweejarige opleiding") not a number. Strategy: extract first `€?\d+(?:,\d{2})?` if present, else null.
4. **wpi_item → course link**: confirm `sfwd-courses_course_invoice_item` is the canonical bridge before relying on it.
5. ~~**Course → FluentForm form_id link**~~ — RESOLVED: port VAD's `LearnDashCourseService::getCustomForm()` (see Script 2 form-data approach).

## Anti-goals

- **No live data**: scripts run against the dry-run DB first; production runs only after sign-off.
- **No retroactive changes to Stride code**: if mapping reveals a Stride bug (like the three we found earlier), log it in memory; fix is a separate change.
- **No deletes**: nothing in `wpi_quote`/`wpi_invoice`/`learndash_user_activity` gets deleted. Original VAD data stays as historical record.
