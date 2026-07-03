# Gate deadlines + 3-mail reminder cadence — design

**Date:** 2026-07-01
**Status:** Approved (brainstorm complete) — ready for implementation plan
**Author:** Stefan + Claude

## Problem

Today a Stride enrollment has gated completion tasks *after* the enrollment form —
questionnaire, document upload, session selection — and a symmetric set of
*post-course* completion gates (evaluation, document upload). Ground-truth findings
(2026-07-01):

- **No enrollment ever expires.** A registration sits `Pending` indefinitely, holding
  edition capacity, until an admin manually confirms or cancels. There is no
  time-driven status transition and no cleanup cron.
- **No scheduled/reminder mail exists.** Every Stride mail is event-triggered
  (`StrideMailBridge`). The only cron in the whole tree is a one-shot 1-second admin
  offload. The `stride_notify_reminders` profile checkbox is inert — nothing reads it.
- **Only `session_selection` has a deadline** (`selection_deadline` +
  `selection_open`, per edition). Document upload and questionnaire have no time limit.
- The admin "Acties nodig" / stale-pending panel is **display-only** (`days_idle`,
  default 7-day threshold, "no auto-cancel"). No days-*left* to a deadline is shown.

Users therefore drop off silently, hold seats forever, and get no nudge; admins have no
per-registration countdown.

## Goal

1. Let admins set a **deadline** for the completion gates.
2. Send the user **3 mails**: (1) a "you still have to…" at enrollment, (2) a reminder
   *X days after enrollment* (X adjustable in settings) if not done, (3) the day before
   the deadline.
3. Give admins **per-registration visibility** of the pending tasks and **days left**.
4. Apply the same to the **end-of-course completion gates** (evaluation + document upload).

## Key decisions (locked during brainstorm)

| # | Decision | Value |
|---|---|---|
| D1 | Deadline shape | **Three independent per-edition dates**, each attached to its gate group — NOT one shared date. |
| D2 | Reminder #2 timing | **`registered_at` + X days** (literal "X days after enrollment"), with safe collision handling. |
| D3 | Deadline effect | **Flag for admin only — no auto-action.** Gate stays open past deadline; enrollment shows OVERDUE. Consistent with Stride's existing no-auto-cancel philosophy. |
| D4 | Enrollment-phase deadline | **One shared date** for questionnaire + documents together (not one per checkbox). |
| D5 | Mail cadence scope | **Per deadline that exists** — enrollment phase AND completion phase each run the full 3-mail cadence. |
| D6 | Scheduling architecture | **Small reusable cron convention in ntdst-core** (~40 lines: self-healing register + bind + deactivation-clear). NOT a CronManager. Reminders send via existing single-recipient `ndmail_send`. Mail-broadcast later reuses the cron seam + adds its own recipient/batch layer. |

## The three deadlines

| Deadline | Edition field | Shown in admin when | Governs gates |
|---|---|---|---|
| Session selection | `selection_deadline` *(exists, unchanged)* | *(existing session-selection config)* | `session_selection` |
| **Enrollment phase** | `gate_deadline` *(new)* | "Vragenlijst invullen" **or** "Documenten uploaden" enabled | `questionnaire` + `documents` |
| **Completion phase** | `post_gate_deadline` *(new)* | "Evaluatie invullen" **or** (post) "Document uploaden" enabled | `post_evaluation` + `post_documents` |

- Both new fields: `text`, `YYYY-MM-DD`, same storage/convention as `selection_deadline`,
  added to `EditionCPT::getFields()`.
- **Admin UI** (`EditionSessionsMetabox` / gate-config panel): the deadline input renders
  *underneath* its gate group, conditionally (Alpine `x-show` on the enable checkbox).
  For "Documenten uploaden", the deadline field is **grouped into the same block as the
  already-revealed required-documents config** so the panel reads as one intentional unit.
- `selection_open` stays as-is (it is the session-choice *window* toggle — a separate
  concern from the docs/questionnaire due date).

## Gate availability & the overdue state

`EnrollmentCompletion::getTaskAvailability()` is the single convergence point where every
task's `state`/`reason` is decided (it already reads `selection_deadline` for
`session_selection`). Extend it — **no new code path, no bypass**:

- Read `gate_deadline` once and apply to `questionnaire` + `documents`; read
  `post_gate_deadline` and apply to `post_evaluation` + `post_documents`.
- Open task + deadline present → `available` reason carries the date:
  **"Voltooien voor {d M Y}"** (mirrors existing `sprintf(__('Kies voor %s'), …)`).
- Past deadline + incomplete → **new `overdue = true` signal**, reason
  **"De deadline is verstreken."** — task stays **`available`** (user can still complete;
  we do NOT lock or cancel). This drives mails + admin visibility, not enforcement.
- **Session selection is untouched** — it keeps its existing past-deadline block behaviour.

## Scheduling seam + reminder cron

### A. ntdst-core cron convention (the reusable seam)
A small helper (e.g. `ntdst_schedule_recurring($hook, $interval, callable)` or a
`Schedulable` trait) that folds the self-healing idiom already copy-pasted in
ntdst-audit + ntdst-assistant into one call: guarded `wp_schedule_event` on init +
`add_action` bind + `wp_clear_scheduled_hook` on deactivate. Uses the built-in `daily`
interval (no custom `cron_schedules`). **This is the seam a future CronManager would grow
from — it is not itself a manager** (no registry, no UI). `GateReminderService` is its
first consumer; audit/assistant can adopt it later (YAGNI — not now).

### B. GateReminderService (stride-core, `NTDST_Service_Meta`)
Registers one **daily** job via the convention. Each run enumerates incomplete
registrations with an active `gate_deadline` or `post_gate_deadline`, and per
registration decides which mail is due today, sending via the existing single-recipient
`ndmail_send(slug, context, ['to' => $email])`.

### C. Setting (adjustable X)
`gate_reminder_days` (default 7) added to `ActionQueueService::DEFAULTS`, exposed in the
**Notifications settings tab** (`tab-notifications.php`) via the existing
`{key}_enabled` / `{key}_value` pattern (absint, clamped 1–365), read through
`getNotificationRules()`.

### D. The three mails (each phase runs its own cadence — D5)

| Mail | Fires | Mechanism | New `ndmail_template` slug |
|---|---|---|---|
| #1 "Je moet nog…" | at enrollment (enroll phase) / at course-completion (completion phase) | **event-triggered** via `StrideMailBridge` (no cron) | `stride-gate-todo` |
| #2 Reminder | `registered_at + X days`, if phase gates still incomplete (naturally skipped if that date ≥ deadline) | daily cron | `stride-gate-reminder` |
| #3 Day-before | `deadline − 1 day`, if still incomplete | daily cron | `stride-gate-deadline-tomorrow` |

Templates added to `getTemplateDefinitions()`; seed-version bumped.

### E. Idempotency (load-bearing)
A per-registration `reminder_state` marker (JSON column on `wp_vad_registrations`, or a
compact meta) records which reminder mails have already gone out per phase, e.g.
`{"enroll":{"reminder":"2026-07-10","deadline":null},"post":{...}}`. The cron
checks-and-marks so each mail fires **at most once per phase per registration**,
surviving downtime/catch-up and never colliding with the day-before mail. **Gets a
Tier-A RED-first test**: send-once, no-resend, catch-up-after-downtime, collision-skip.

## Admin days-left / overdue visibility

- `getPendingApprovals` (`AdminAPIController`) already computes `days_idle` from
  `registered_at`. Add, per pending registration, the **active deadline** and
  **`days_left`** (or `days_overdue` when negative), derived from the same
  `gate_deadline`/`post_gate_deadline` + the `overdue` signal above.
- The pending queue surfaces **"nog N dagen"** or a red **"N dagen te laat"** badge per
  row, next to the existing "Wacht op gebruiker" reason. **No new endpoint** — extends
  the existing read. Stays display-only (D3).

## Out of scope (explicit deferrals)

- **Auto-cancel / auto-lock** on deadline — rejected (D3).
- **Mail-broadcast recipient-source / batch layer** — separate feature, built later in
  netdust-mail; it will *reuse this cron seam* but owns its own many-recipient layer.
  The `ndmail_recipient_sources` filter the broadcast spec assumed **does not exist** yet
  and is NOT created here.
- **Sub-daily cron intervals / custom `cron_schedules`** — not needed; `daily` suffices.
- **Reading the inert `stride_notify_reminders` preference** — left inert; not wired to
  these mails for v1 (revisit if per-user opt-out is requested).

## Gates the plan must fire (harness)

- **threat-modeling** — new scheduled surface: cron enumeration of registrations, mail
  send to user-supplied email, settings input. Model the reminder-loop + send path.
- **architecture-invariants** — the ntdst-core cron convention is a **new convergence
  point** (name it: "recurring jobs register through `ntdst_schedule_recurring`, not raw
  `wp_schedule_event`"); `EnrollmentCompletion::getTaskAvailability()` is the **existing**
  convergence point every deadline read must route through (no bypass).
- **wp-plan-requirements** (WP stack) — settings input sanitize/cap, `ndmail_send`
  recipient escaping, capability on the settings save.
- Per-task test tiers + review clusters per writing-plans.

## Surfaces touched (ground-truthed 2026-07-01)

- `Modules/Edition/EditionCPT.php` — `getFields()`: add `gate_deadline`, `post_gate_deadline`.
- `Modules/Edition/Admin/EditionSessionsMetabox.php` — conditional deadline inputs + docs grouping.
- `Modules/Enrollment/EnrollmentCompletion.php` — deadline reads + `overdue` in `getTaskAvailability()`.
- `Modules/Enrollment/RegistrationTable.php` / `RegistrationRepository.php` — `reminder_state` storage.
- `ntdst-core` — new `ntdst_schedule_recurring` helper / `Schedulable` trait.
- `Modules/…/GateReminderService.php` — **new** daily cron service.
- `Modules/Mail/StrideMailBridge.php` — mail #1 event bindings + 3 new template definitions + seed bump.
- `Admin/ActionQueueService.php` + `Admin/StrideSettingsService.php` + `templates/admin/settings/tab-notifications.php` — `gate_reminder_days` setting.
- `Admin/AdminAPIController.php` (`getPendingApprovals`) — `days_left` / `days_overdue` + active deadline.
- `templates/admin/handleiding.php` — admin-facing explainer (settings/behaviour, not code).

> **Note on the handleiding:** the guide describes *shipped* behaviour, so the section
> below is added **as an implementation task when the feature ships** — NOT to the live
> handleiding now (it would describe settings that don't yet exist). Copy is ready below.

## Handleiding copy (admin-facing, Dutch — add when feature ships)

Add to the **Inschrijvingen** section (after "Opties per editie"), tone matching the
existing guide:

### Deadlines & herinneringen

Per editie kun je een **deadline** zetten voor de taken die een deelnemer na inschrijving
moet afronden. Er zijn drie afzonderlijke deadlines, elk gekoppeld aan de bijhorende taak:

- **Sessiekeuze** — bestaande deadline; verschijnt bij de sessiekeuze-instellingen.
- **Vragenlijst & documenten (bij inschrijving)** — één gedeelde datum. Verschijnt zodra
  je "Vragenlijst invullen" of "Documenten uploaden" aanzet.
- **Evaluatie & documenten (na de cursus)** — één gedeelde datum voor de afrondingstaken.

**Wat gebeurt er bij de deadline?** Niets automatisch — de deelnemer kan de taak ook ná de
deadline nog afronden. Een verlopen deadline wordt enkel **gemarkeerd**: bij *Acties nodig*
zie je per inschrijving hoeveel dagen er nog resten ("nog 3 dagen") of hoeveel dagen te
laat ("2 dagen te laat"). Jij beslist per geval — Stride annuleert nooit vanzelf.

**De drie automatische e-mails.** Zodra een deadline is ingesteld, krijgt de deelnemer:

1. bij inschrijving (of bij het afronden van de cursus, voor de afrondingstaken) een mail
   *"je moet nog…"*;
2. een **herinnering** een aantal dagen na inschrijving als het nog niet klaar is;
3. een laatste mail **de dag vóór de deadline**.

Het aantal dagen voor de herinnering stel je in onder **Stride → Instellingen →
Meldingen** ("Herinnering na … dagen", standaard 7).

Add one FAQ entry (`<details>`):

> **Krijgt een deelnemer automatisch een herinnering als hij zijn taken niet afrondt?**
> Ja — als je een deadline op de editie zet. Hij krijgt een mail bij inschrijving, een
> herinnering (aantal dagen instelbaar onder Instellingen → Meldingen) en een mail de dag
> vóór de deadline. De inschrijving wordt nooit automatisch geannuleerd; verlopen
> deadlines zie je bij *Acties nodig*.
