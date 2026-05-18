# Deelnemers panel — view-info pass

**Date:** 2026-05-18
**Scope:** Stride admin — `EditionRegistrationMetabox` on `vad_edition` post edit screen
**Status:** Spec / brainstorm-approved

## Why

When an admin opens an edition, the "Deelnemers" tab should let them see *everything* about a single enrollment — userdata, what the user picked in the form, which sessions they chose, their questionnaire answers, uploaded documents, and how far along they are. Today the expanded row mixes identity with raw `enrollment_data` dumps and a thin task list. That's not enough info, and the row gets cluttered.

This pass **prepares the panel for the future quick-actions pass** (view-as / contact / unsubscribe / etc.). We do not implement those mutating actions yet — they need separate design thinking. We only:

1. Slim the inline detail row to identity only.
2. Add three view-info entry points in the actions column.
3. Build two server-rendered modals with full info.

## What this is NOT

- Not adding `view as`, `contact`, `unsubscribe`, `switch user` actions yet.
- Not redesigning the Aanwezigheid tab.
- Not changing the LearnDash-direct-enrollment view (`renderDirectEnrollments`).
- Not changing the registrations DB schema or services.

## User stories

- **As an admin running an edition**, I want to see what a participant filled in on the enrollment form, so I can verify the info.
- **As an admin handling a question by phone**, I want to see which sessions the user picked and which questionnaire answers they gave, without leaving the edition page.
- **As an admin auditing completion**, I want to see the chronological state of every completion task for a registration, including any uploaded documents.

## UI changes

### Inline detail row (slimmer)

Today: identity + enrollment-data dump + task list with files.
After: identity only.

Keep:

- Telefoon
- Organisatie
- Afdeling
- Facturatie bedrijf (only if different from organisation)
- BTW-nummer
- Opmerking (registration notes)

Remove:

- Raw `enrollment_data` key/value dump (moves to modal section 1)
- Task list with file links (moves to completion modal)
- Inline quote summary (link moves to action icon)

Empty state stays as "Geen aanvullende gegevens."

### Actions column — new icon strip

Existing buttons (`stride-confirm-reg`, `stride-reject-reg`, `stride-approve-post-course`) keep their current behaviour and position (left side of cell).

Add three new icons to the right, separated by a vertical divider:

| Icon | Class | Behaviour |
|------|-------|-----------|
| `dashicons-clipboard` | `stride-view-enrollment` | Open Inschrijvingsgegevens modal |
| `dashicons-yes` | `stride-view-completion` | Open Voltooiing modal |
| `dashicons-media-text` | `stride-view-quote` | Link to `get_edit_post_link($quote['id'])` if quote exists; disabled (greyed-out with title "Geen offerte") otherwise |

Anonymised / hard-deleted users render an em-dash in actions as today — none of the new icons appear.

### Modal 1 — Inschrijvingsgegevens

Title: `Inschrijving — {user display name} — {edition title}`

Four collapsible sections, default-open. Each section has a non-hiding empty state so admins know when something is absent vs. missing.

**Section A. Inschrijvingsformulier**
Render `enrollment_data` as a definition list. Field labels resolved from the edition's enrollment-form schema where available; humanised key (e.g., `phone_secondary` → "Phone secondary") otherwise. Skip keys that duplicate identity already shown in the inline row (organisation, department).
Empty state: "Geen inschrijvingsformulier voor deze editie."

**Section B. Sessiekeuzes**
For each `_ntdst_session_slots` group:
- Slot label (e.g., "Module 1 — Kies 1 uit 3")
- The session the user picked (`SessionSelection::getSelectionsForRegistration` → resolve to session post → render date, start/end time, location/online URL)

For mandatory sessions (no slot binding): list under "Verplichte sessies".
Empty state: "Geen sessiekeuze van toepassing."

**Section C. Vragenlijst**
Read answers from `completion_tasks['questionnaire']['data']['answers']` (shape: `question stem => answer`, as already used by `EditionRegistrationExporter`). Render each pair as question-card + answer. For non-string answers, JSON-encode for display.
Empty state when the task is absent or has no answers: "Geen vragenlijst voor deze editie."

**Section D. Documenten**
File list combining `documents` task and `post_documents` task (`completion_tasks[].data.files`). For each file: filename, size, uploaded-at (from attachment post `post_date`), download link.
Empty state: "Geen documenten geüpload."

### Modal 2 — Voltooiingsdata

Title: `Voltooiing — {user display name}`

Sections:

1. **Status van taken** — table of completion tasks (questionnaire, documents, approval, session_selection, post_evaluation, post_documents, post_approval): label, status badge, completed-at timestamp, completed-by (resolve from task data where available, fallback "—").
2. **LearnDash voortgang** — percentage bar (`LearnDashHelper::getProgress`), completion date (`LearnDashHelper::getCompletionDate`) if any.
3. **Aanwezigheid** — `SessionService::getHoursAttended($userId, $editionId)` over total hours required (sum of session hours marked attendance-required). Show `X / Y uur`.
4. **Certificaat** — if `LearnDashHelper::isComplete()` → link from `LearnDashHelper::getCertificateLink()`. Otherwise nothing (no empty state, since it's a positive-only block).

## Data flow

One new AJAX endpoint: `wp_ajax_stride_get_registration_modal`.

- Inputs: `registration_id` (int), `type` (`enrollment` | `completion`), `nonce` (`stride_edition_admin`).
- Capability: `manage_options` (matches existing edition-admin actions).
- Output: HTML fragment (escaped) for the modal body. Title rendered by the endpoint too.
- Error responses use `wp_send_json_error` with translated messages.

Server-rendered HTML keeps escaping in PHP and avoids leaking internal data shapes to JS. JS only handles open/close + skeleton state.

## Components touched

| File | Change |
|------|--------|
| `mu-plugins/stride-core/Modules/Edition/Admin/EditionRegistrationMetabox.php` | Slim `renderDetailRow`; add 3 action icons + modal scaffold `<div id="stride-registration-modal">`. |
| `mu-plugins/stride-core/Modules/Edition/Admin/RegistrationModalController.php` *(new)* | AJAX endpoint; renders modals; uses existing services (`EditionService`, `SessionService`, `SessionSelection`, `AttendanceRepository`, `LearnDashHelper`). |
| `mu-plugins/stride-core/templates/admin/partials/registration-modal-enrollment.php` *(new)* | Modal 1 HTML template. |
| `mu-plugins/stride-core/templates/admin/partials/registration-modal-completion.php` *(new)* | Modal 2 HTML template. |
| `mu-plugins/stride-core/assets/js/admin/edition-admin.js` | Wire new icon clicks → AJAX → render modal body; open/close handlers. |
| `mu-plugins/stride-core/assets/css/admin/edition-admin.css` | Modal styles, action-icon divider, slimmed detail-row spacing. |
| `mu-plugins/stride-core/plugin-config.php` | Register `RegistrationModalController` service. |

No service interface changes. No DB changes. No new domain types.

## Service registration

`RegistrationModalController` follows the thin-handler pattern (matches `EditionAdminController` style): constructor wires its own AJAX action; uses `ntdst_get()` to resolve services inside the request.

## Permissions & safety

- Endpoint requires `manage_options` + valid `stride_edition_admin` nonce.
- Modal renders nothing for anonymised users (`_stride_anonymised_at > 0`) or hard-deleted user IDs — returns `WP_Error('user_unavailable', ...)`.
- All output passes `esc_html` / `esc_attr` / `wp_kses_post` as appropriate. File links validated via `wp_get_attachment_url`.
- No mutation endpoints. Pure read.

## Tests

- **Unit (`tests/Unit/`)**
  - `RegistrationModalControllerTest` — happy paths for enrollment + completion HTML rendering with stubbed services; missing data → empty-state markup; anonymised user → `WP_Error`.
- **Integration (`tests/Integration/`)**
  - Full edition with seeded registration: modal endpoint returns expected sections; field labels resolve correctly from enrollment-form schema; questionnaire answers map to question stems.
- **Acceptance (Cest)**
  - Admin opens edition → sees deelnemers tab → row expands to identity only → clicks `Inschrijvingsgegevens` icon → modal shows all four sections → closes modal → clicks `Voltooiing` icon → modal shows tasks/progress/attendance → `View quote` icon opens edit-quote screen in new tab.

## Out of scope (deferred)

- `View as` (impersonate) — endpoint exists; UI wiring deferred to action-thinking pass.
- `Contact` — new compose modal needed; deferred.
- `Unsubscribe` — `EnrollmentService::cancel` exists; UX (confirm copy, undo window) needs thinking; deferred.
- `Switch user` (replace participant with colleague) — admin-burden tradeoff not yet resolved; deferred per 2026-05-18 user note.
- Density/compact mode for the deelnemers table.

## Open questions

None — all clarifications resolved in brainstorm 2026-05-18.

## References

- Current implementation: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionRegistrationMetabox.php`
- Memory: `pattern_keuzecursus_session_slots.md` (slot grouping), `pattern_questionnaire_reserved_fields.md` (reserved field names)
- Launch checklist: `docs/LAUNCH-CHECKLIST.md` — fits operational visibility ahead of launch; not the deferred "what happened" timeline view
