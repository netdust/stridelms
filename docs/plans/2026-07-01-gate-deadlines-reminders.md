# Gate Deadlines + 3-Mail Reminder Cadence — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Dispatch `netdust-agent:implementer`, one per task.

**Goal:** Give each edition three independent per-phase completion-gate deadlines (session / enrollment / completion), drive a 3-mail reminder cadence per deadline (event "todo" + cron reminder at `registered_at+X` + cron day-before), surface days-left/overdue to admins, and add a reusable recurring-cron seam to ntdst-core — flag-only on overdue, no auto-cancel.

**Architecture:** Two new edition date fields (`gate_deadline`, `post_gate_deadline`) route through the single availability convergence point `EnrollmentCompletion::getTaskAvailability()` (no bypass). A new ntdst-core `ntdst_schedule_recurring()` helper folds the self-healing cron idiom into one call; `GateReminderService` (stride-core, `NTDST_Service_Meta`) is its first consumer, enumerating incomplete registrations daily and sending single-recipient reminders via `ndmail_send`. A per-registration `reminder_state` JSON column (RegistrationTable v3) makes every mail fire at most once per phase.

**Tech Stack:** PHP 8.3, NTDST Core (DI/Router/Data API), WordPress cron, netdust-mail (`ndmail_send`), Alpine.js (admin metabox `x-show`), PHPUnit (Unit + Integration), Codeception (acceptance).

**Spec (authoritative):** `docs/superpowers/specs/2026-07-01-gate-deadlines-reminders-design.md`
**Memory:** `~/.claude/projects/-home-ntdst-Sites-stride/memory/project_gate_deadlines_reminders.md`

## Global Constraints

- **Work class:** A (feature, multi-task). Branch: `feat/gate-deadlines-reminders`.
- **Deadline effect is FLAG-ONLY** (D3) — the gate stays `available` past deadline; the user can still complete; no lock, no auto-cancel. Overdue drives mails + admin visibility only.
- **Three independent deadlines** (D1): `selection_deadline` (exists, untouched) / `gate_deadline` (new, shared by questionnaire+documents) / `post_gate_deadline` (new, shared by post_evaluation+post_documents).
- **Deadline fields:** `text`, format `YYYY-MM-DD`, added to `EditionCPT::getFields()` (INV-3: `getFields()` is the field source of truth — no central registry, no hardcoded `_ntdst_*` keys).
- **Every deadline read routes through `EnrollmentCompletion::getTaskAvailability()`** — no new deadline-reading code path anywhere (INV — see Architecture invariants touched).
- **Reminders send via existing single-recipient `ndmail_send($slug, $context, ['to' => $email])`** — verified signature `ndmail_send(string $templateSlug, array $context, array $options = []): bool|\WP_Error` at `web/app/plugins/netdust-mail/netdust-mail.php:78`. No many-recipient/batch layer here (out of scope).
- **UI language Dutch (nl_BE)**; code English. Reason strings mirror the existing `sprintf(__('Kies voor %s', 'stride'), date_i18n('d M Y', …))` pattern.
- **`gate_reminder_days`** default 7, clamped `[1,365]` (absint), stored via the existing `{key}_enabled`/`{key}_value` settings pattern.
- **No custom `cron_schedules`** — use the built-in `daily` interval (D6).
- **The cron callback takes NO request-borne input** — it is a scheduled context; all inputs come from the DB.

---

## Golden path: form-data-flow + admin-settings-page (deviations must be named and justified in the plan)

- [ ] Built to `netdust-wp:ntdst-patterns → golden-paths/form-data-flow.md` (the settings save) and `golden-paths/admin-settings-page.md` (the notifications tab field) — read before task breakdown.
- [ ] This feature ALSO has a **cron/service archetype with no golden-path slice** — the daily `GateReminderService` + the ntdst-core seam. For that portion: `## Golden path: none (no matching archetype — pure cron service)`. It is built to the NTDST_Service_Meta lifecycle in `netdust-wp:ntdst-architecture`, not to a form/route slice.
- [ ] Deviations from the slices (each named + justified):
  - **Settings save does NOT use the `ntdst/api_data` filter** — `gate_reminder_days` piggybacks on the EXISTING notifications settings save in `StrideSettingsService` (`{key}_enabled`/`{key}_value`), which already carries its own nonce + `manage_options`. Justified: one new field on an existing, already-gated form is a data addition, not a new flow. Do NOT hand-roll a second save path.
  - **Deadline reads render no new endpoint** — they extend the existing availability convergence point + the existing `getPendingApprovals` read. Justified: reuse of a convergence point is the point (INV routing), not a deviation to fix.
  - **Mail #1 is event-triggered, not cron** — bound in `StrideMailBridge` on the existing `stride/registration/created` + a new course-complete trigger. Justified by D5/spec table; only mails #2/#3 are cron-driven.

---

## Threat model

> **Context.** This is Stride's FIRST recurring cron and FIRST scheduled mail. The new surface is a daily job that enumerates registrations and sends mail to user-supplied email addresses, plus one new admin settings input (`gate_reminder_days`). Written 2026-07-01, at plan-time, before task breakdown. It exists so `/code-review` and `security-sentinel` verify the cron+mail+settings clusters against named mitigations in one round instead of re-discovering the surface each round. The auth/nonce mitigations become sub-invariants the FULL-tier review clusters check.

### What we're defending

- **A1 — Registrant PII / email address.** The `to` recipient of every reminder mail — read from the registration's resolved user email. Mailing the wrong address leaks that a person is enrolled in a specific (possibly sensitive, e.g. addiction-care) VAD course.
- **A2 — The cron enumeration query.** The daily scan over `wp_vad_registrations` for rows with an active `gate_deadline`/`post_gate_deadline` and incomplete tasks. An unbounded or attacker-widenable scan is a self-DoS on a shared cron tick.
- **A3 — The `gate_reminder_days` option.** A `manage_options` setting persisted to `wp_options`, echoed back into the settings form. Unescaped output or an unclamped value is stored-XSS / cron-abuse surface.
- **A4 — Mail-send idempotency state.** The per-registration `reminder_state` JSON. If it can be corrupted or bypassed, a registrant is spammed (send-many) or silently never reminded (send-never).
- **A5 — The scheduled hook itself.** `wp_schedule_event('daily', $hook)` + its bound callback. A callback that trusts request input, or a hook reachable via a crafted request, turns a scheduled job into an on-demand mass-mail primitive.

### Who we're defending against

- **External unauthenticated attacker** — IN scope for A2/A5 (can they trigger the cron / widen the scan / hit the hook?).
- **Authenticated low-privilege user (student/partner, no `manage_options`)** — IN scope for A3 (can they change the reminder-days setting or reach the settings save?).
- **Admin with `manage_options`** — IN scope only for A3 output-escaping (a bad value they type must not clamp-bypass or persist raw into the echoed form). NOT defended against as an attacker of their own site.
- **A registrant supplying their own profile email** — IN scope for A1: the email is user-controlled data the cron will send TO (not a URL the server fetches — no SSRF class here), so the risk is *mis-targeting* (send to a stale/attacker-chosen address), not server-side request forgery.
- **Insider with a stolen admin credential** — OUT of scope (acknowledged, not defended).

### Attacks to defend against

1. **Settings-save privilege bypass (A3).** A non-`manage_options` user reaches the notifications settings save and sets `gate_reminder_days`.
2. **Unclamped / non-integer `gate_reminder_days` (A3).** A value of `0`, negative, `99999`, or a string is stored → reminder date math produces `registered_at + 0/negative/absurd` days, mis-firing or never firing the reminder.
3. **Stored-XSS via the echoed setting (A3).** The persisted value is echoed back into the settings `<input value="…">` without `esc_attr`.
4. **Mail to the wrong / injected recipient (A1).** The cron sends to an address that isn't the registrant's, or a header-injection payload in the address string reaches the transport.
5. **Enumeration DoS / unbounded scan (A2).** The daily query has no upper bound and no active-deadline predicate, so it loads every registration ever, on one cron tick, and/or sends a mail storm.
6. **Duplicate-send / send-storm (A4).** The idempotency check is read-then-write with a race, or is skipped on catch-up after downtime, so a registrant gets the same reminder N times (or the day-before + reminder collide and both fire).
7. **Request-triggered cron callback (A5).** The mail-sending callback reads `$_GET`/`$_POST`/`$_REQUEST`, so a crafted request to `admin-ajax`/`wp-cron.php?doing_wp_cron` can steer WHO gets mailed or fire it on demand.
8. **Mail to a cancelled / completed registration (A1/A4).** The scan includes rows whose phase is already done or whose registration is cancelled → a "you still have to…" nag to someone who is finished.

### Mitigations required (numbered to match attacks)

1. **`gate_reminder_days` rides the EXISTING notifications settings save**, which already calls the settings-service save behind `current_user_can('manage_options')` + a nonce (`check_admin_referer`/`wp_verify_nonce`). The plan adds NO new save endpoint. Task 5.2's diff MUST show the field going through that gated path — a reviewer verifies the new key appears only inside the already-authorized save handler, never a fresh `add_action('wp_ajax_…')`.
2. **`absint()` + clamp `[1,365]`** on read AND on save: `max(1, min(365, absint($raw)))`. Applied in `StrideSettingsService::getNotificationRules()` (read) and the save sanitizer (write). Pinned by a Tier-A test asserting `0→1`, `-5→1`, `99999→365`, `'abc'→1`.
3. **`esc_attr()` on the echoed value** in `tab-notifications.php` (mirror the sibling `{key}_value` fields already on that tab — they are the reference; the new field must not be the one that forgets). INV-5 output-escaping sub-invariant.
4. **Recipient is derived server-side, never from request input**, via the registrant's resolved WP user email (`get_userdata($userId)->user_email` / the existing mail-context resolution in `StrideMailBridge`), and passed as `['to' => $email]` to `ndmail_send`. `ndmail_send`/FluentSMTP transport handle header-injection defense; the plan passes a single validated `is_email()` address and SKIPS the row (logged) if the email is empty/invalid. No untrusted string is concatenated into a header.
5. **The scan is bounded and predicate-filtered at the query, in `RegistrationRepository`**: `WHERE status IN ('confirmed','pending') AND (edition has gate_deadline OR post_gate_deadline)` — i.e. only rows whose edition actually carries an active deadline, only non-terminal statuses, `$wpdb->prepare` throughout (INV-3). The repository method carries an explicit upper `LIMIT` / batch cap; the service processes in bounded chunks. No unbounded `SELECT *`.
6. **`reminder_state` check-and-mark is idempotent and Tier-A RED-first** (Task 4.3). The service reads the phase's `reminder_state`, decides which single mail is due today, and marks it sent BEFORE/atomically-with the send so a re-run (catch-up after downtime, double cron tick) cannot resend. Day-before and reminder are mutually exclusive per run (if both would be due, day-before wins and reminder is marked skipped). Tests: send-once, no-resend-on-rerun, catch-up-after-downtime marks-without-spamming, reminder∩day-before collision fires exactly one.
7. **The cron callback reads NO request superglobals** — it takes zero parameters and pulls every input from the repository. Reviewer greps the callback body for `$_GET`/`$_POST`/`$_REQUEST`/`$_SERVER` → must be empty. The hook is registered via `ntdst_schedule_recurring` (invariant), which binds it to the cron event only, not to any AJAX/REST route.
8. **The scan predicate excludes terminal/irrelevant rows** (mitigation 5's `status IN` + a per-phase "is this phase's gates still incomplete?" check that routes through `getTaskAvailability()` — the same convergence point, so "done" means the same thing the UI shows). A cancelled or phase-complete registration is never enqueued for mail.

### Out of scope (explicit deferrals)

> **P3-gate tracked follow-ups (2026-07-01, non-blocking, NOT this feature's scope):**
> 1. `strtotime($deadline) < time()` in both `deadlineInfo()` (new) and the `session_selection` block (pre-existing) coerces `false`→`0` for a non-empty *unparseable* deadline string, falsely marking overdue. Empty/null is already guarded; only a garbage non-empty string bites, and values come from an admin date field. A both-call-sites hardening (`$ts=strtotime(...); $ts!==false && $ts<time()`) is a separate consistency pass — do NOT fix only the new site (would desync from the sibling).
> 2. Two independent readers of `_ntdst_gate_deadline`/`_ntdst_post_gate_deadline` now exist by design: `findWithActiveDeadline` (P2, cron existence-check) and `deadlineInfo` (P3, overdue computation) — different purposes, not a convergence bypass. Note only if a future single-source-of-truth refactor is considered.


- **Auto-cancel / auto-lock on deadline** — rejected (D3); overdue is flag-only.
- **Many-recipient / batch broadcast layer** (`ndmail_recipient_sources` filter) — separate netdust-mail feature; the filter does NOT exist and is NOT created here. Reminders are single-recipient only.
- **Per-user opt-out** (the inert `stride_notify_reminders` preference) — left inert for v1; not wired. Revisit if requested.
- **Sub-daily cron / custom intervals** — `daily` suffices.
- **DNS/SSRF classes** — N/A: the server sends mail TO an address, it does not fetch a user-supplied URL. No outbound-to-user-URL surface exists in this feature.
- **Hardening `wp-cron` reliability itself** (real cron vs. request-triggered pseudo-cron) — operational; the idempotency (mitigation 6) makes a missed/duplicated tick safe regardless.

### How to use this section

- **Controller pre-flight:** verify mitigations 1–8 appear in the plan-supplied task code before dispatching the FULL-tier clusters (Phase 4 cron/mail, Phase 5 settings).
- **`/code-review` / `security-sentinel`:** "Verify the diff against the threat model. Check each numbered mitigation is in place; report in-place / missing / out-of-scope per the deferrals." Point security-sentinel at `references/security-checklist.md`.
- **`/evaluate` retros:** any un-implemented mitigation is a plan-correction defect.
- **Downstream (mail-broadcast reuse):** cross-reference this section; the broadcast feature inherits the cron seam but MUST add its own recipient/batch threat model — do not assume this one covers many-recipient.

---

## Architecture invariants touched

> Doc: `ARCHITECTURE-INVARIANTS.md` (project root). This feature touches four existing convergence points and INTRODUCES ONE new one. Task 4.1 authors the new invariant into the doc.

**NEW — INV-10 (this feature authors it):** **Recurring jobs register through `ntdst_schedule_recurring()`, not raw `wp_schedule_event`.** Convergence point: `ntdst-core` global helper `ntdst_schedule_recurring(string $hook, string $interval, callable $cb): void` (self-healing register + `add_action` bind; paired with `ntdst_clear_recurring($hook)` on deactivate). Bypass to flag: a new `wp_schedule_event(...)` + hand-rolled `wp_next_scheduled` guard anywhere in stride-core. Known accepted pre-existing bypasses (NOT re-flagged; the two the idiom is folded FROM): `web/app/plugins/ntdst-audit/src/AuditService.php:42-51` (`ntdst_audit_cleanup`, weekly) and `web/app/plugins/ntdst-assistant/ntdst-assistant.php:56-62` (`ntdst_assistant_cleanup_exports`, hourly) — these live in regular plugins, not mu-plugins, and may adopt the seam later (YAGNI). `GateReminderService` is the first and only consumer of the new seam.

**Existing invariants this feature MUST route through (no bypass):**
- **INV-3 (Data access):** `gate_deadline`/`post_gate_deadline` live in `EditionCPT::getFields()` (the field source of truth); reads go through `EditionRepository::getField()`. `reminder_state` storage goes through `RegistrationRepository` (the sole `$wpdb` owner of `wp_vad_registrations`) — add repository methods `getReminderState(int)`/`setReminderState(int, array)` and the scan finder `findWithActiveDeadline(...)`; do NOT reach `$wpdb` from `GateReminderService`.
- **The availability convergence point (task-state):** `EnrollmentCompletion::getTaskAvailability()` is the SINGLE place every task's `state`/`reason`/overdue is decided. The new deadline reads + `overdue` signal are added HERE and nowhere else. Every render surface (dashboard, completion page, admin queue) reads the derived value, never re-reads `gate_deadline` itself. (This is the deadline-read invariant the spec names; it is the `session_selection` pattern extended, not a new path.)
- **INV-4 (Error handling):** the cron scan and `ndmail_send` failures return/log `WP_Error` via `ntdst_log('mail')` / `ntdst_log('enrollment')` — a failed send is logged, the row is NOT marked sent (so it retries next tick), never silently swallowed.
- **INV-5 (Rendering/escaping):** the new settings input echoes through `esc_attr`; admin metabox deadline inputs escape their `value`. The plugin never calls theme helpers.

---

## WP security requirements (per data-flow)

- [ ] **Settings save `gate_reminder_days`** (rides existing notifications save): authorize `current_user_can('manage_options')` + existing nonce (`check_admin_referer`) — inherited, verify not bypassed. Sanitize: `max(1, min(365, absint($_POST[...])))`. Escape: `esc_attr` on the echoed `value`. Validate: n/a beyond clamp. (Mitigations 1–3.)
- [ ] **Edition metabox deadline inputs** (`gate_deadline`, `post_gate_deadline`, ride existing `EditionAdminController` save at :515): authorize — inherited `edit_post` check on the edition save. Sanitize: `sanitize_text_field` (mirror `selection_deadline` at :516). Escape: `esc_attr` on the metabox `value` output. Validate: format is free-text `YYYY-MM-DD` like `selection_deadline` (no new validator — parity).
- [ ] **Cron scan query** (`RegistrationRepository::findWithActiveDeadline`): authorize — n/a (scheduled context, no actor). Sanitize — no request input; all params are internal ints. `$wpdb->prepare` on every interpolated value. Escape — n/a (no output). Bound — explicit `LIMIT`/batch. (Mitigations 5, 7.)
- [ ] **Cron mail send** (`GateReminderService::run` → `ndmail_send`): authorize — n/a. Recipient — server-derived `user_email`, `is_email()`-validated, row skipped+logged if invalid. No request superglobals in the callback body. (Mitigations 4, 7.)
- [ ] **Admin `getPendingApprovals` days_left extension**: authorize — inherited `canViewAdmin()` (INV-1), verify not bypassed. Output is JSON via `WP_REST_Response`; `days_left`/`days_overdue` are ints (no escaping needed, but no raw string interpolation). Read-only (D3).

## ntdst-core layering requirements

- [ ] Data access through repositories — `reminder_state` via `RegistrationRepository`, deadline fields via `EditionRepository::getField()`; no `$wpdb` in `GateReminderService`, no `ntdst_data()` outside a repo (INV-3).
- [ ] No pure pass-through Service methods — `GateReminderService` adds the enumerate→decide-which-mail→idempotency→send logic (real behavior, not a wrapper).
- [ ] No raw `wp_ajax_*` handlers — none added; settings ride the existing save, cron is a scheduled hook.
- [ ] No `ob_start()+include` rendering — metabox/settings additions use the existing template + escaping.
- [ ] No swallowed `WP_Error` — send failures logged via `ntdst_log`, row left unmarked to retry (INV-4).
- [ ] Field names via `EditionCPT::getFields()`; no hardcoded `_ntdst_*` (INV-3).
- [ ] `GateReminderService` is `NTDST_Service_Meta`, registered in `stride-core/plugin-config.php`, hooks in `init()` (per `netdust-wp:ntdst-architecture`).
- [ ] Schema change (`reminder_state` column) goes through `RegistrationTable::migrate()` idempotent-step + retry-transient pattern, bumping `SCHEMA_VERSION 2 → 3`.

> **Convergence contract:** These blocks + the Threat model + the Architecture-invariants note are the convergence target for `/code-review` and `ntdst-drift-reviewer` at shake-out. Reviewers verify the diff against the named golden-path deviations + four pillars + nine categories + numbered mitigations + INV routing — a gap is a one-line finding keyed to a named item, not a re-discovery.

---

## Sibling-site audit — the deadline read (`selection_deadline` → the new gate deadlines)

`selection_deadline` is read today in `EnrollmentCompletion::getTaskAvailability()` (`:167`) and its derived `reason`/`state` is rendered across N surfaces. The new `gate_deadline`/`post_gate_deadline` reads + the `overdue` signal MUST be threaded to **every** surface that renders a task reason/state — or that surface will show the session countdown but silently omit the questionnaire/documents/evaluation one. Enumerate (ground-truthed 2026-07-01):

| Surface | File | What it renders | Must reflect new deadline/overdue? |
|---|---|---|---|
| Availability convergence point | `Modules/Enrollment/EnrollmentCompletion.php:139` (`getTaskAvailability`) + its two internal callers `:420`, `:569` | the `state`/`reason` decision itself + `overdue` | **YES — the ONLY place the read happens.** Add here. |
| Dashboard completion checklist | `themes/stridence/templates/dashboard/partials/completion-checklist.php` | per-task reason + state badge | YES — renders the derived reason; will pick up new reason automatically IF it reads `reason`/`overdue` generically. Verify it does not special-case only `session_selection`. |
| Dashboard service (data prep) | `Modules/User/UserDashboardService.php:356`, `:730` | feeds availability to the dashboard | YES — passes through; confirm it forwards the new `overdue` key. |
| Completion page | `themes/stridence/forms/completion.php` | the task list on the completion page | YES — same generic-reason check. |
| Admin pending queue | `Admin/AdminAPIController.php` `getPendingApprovals` (`:1728`, `days_idle` at `:1792`) | per-registration reason + (new) days_left/overdue badge | YES — Phase 6 adds `days_left`/`days_overdue` here, derived from the same deadline + `overdue` signal. |
| Mail payload | `Modules/Mail/StrideMailBridge.php` (reads availability) | mail context | YES — mail #1/#2/#3 reason/date come from the same derived value, not a re-read. |
| Audit bridge | `Modules/Audit/AuditBridge.php` | logs task transitions | NO new deadline render; audit only logs completion events. Confirm no deadline re-read added. |
| Profile handler | `Handlers/ProfileHandler.php` | (uses reason strings) | Verify it consumes derived reason, adds no re-read. |

**The audit rule:** grep for `gate_deadline`/`post_gate_deadline` after implementation — the ONLY read sites permitted are `EditionCPT::getFields()` (definition), `EditionSessionsMetabox`/`EditionAdminController` (admin write), and `getTaskAvailability()` (the one read). Any OTHER file reading `gate_deadline` is a bypass of the convergence point — route it through `getTaskAvailability()`. Mirror INV-6b's audit-move style.

---

## Ground-truth notes (verified 2026-07-01 against source, per Step 1c)

Drift found against the spec's premises — carry these corrections into the tasks:

1. **`ndmail_send` signature — CONFIRMED, no drift.** `ndmail_send(string $templateSlug, array $context, array $options = []): bool|\WP_Error` (`netdust-mail.php:78`). Single-recipient via `['to' => …]`. Spec is correct.
2. **`ActionQueueService::DEFAULTS` shape — CONFIRMED, no drift.** Flat `['key' => ['enabled' => bool, 'value' => int]]` (`ActionQueueService.php:16`). `gate_reminder_days => ['enabled' => true, 'value' => 7]` slots in cleanly. Note: `evaluate()` dispatches per-known-key, so adding `gate_reminder_days` to DEFAULTS alone does NOT wire it into the action-queue evaluation — that's fine, this key is read by `getNotificationRules()` for the CRON, not evaluated as an action-queue rule. **Do not add an `evaluate*` branch for it.**
3. **`getTaskAvailability()` deadline block — CONFIRMED convergence point.** The four target cases (`questionnaire`, `documents`, `post_evaluation`, `post_documents`) currently each return a bare `['state' => 'available', 'reason' => '']` (`:200-224`). The `session_selection` case (`:213-224`) is the exact mirror: `sprintf(__('Kies voor %s', 'stride'), date_i18n('d M Y', strtotime($deadline)))`. Copy that shape for "Voltooien voor …" + add `overdue`.
4. **DRIFT — cron idiom location.** Spec says the idiom is "copy-pasted in ntdst-audit + ntdst-assistant." Verified: it exists, but in `web/app/plugins/ntdst-audit/src/AuditService.php:42-51` (`weekly`) and `web/app/plugins/ntdst-assistant/ntdst-assistant.php:56-62` (`hourly`) — **regular plugins, not mu-plugins**, and **neither uses `daily`**. The seam is still valid; the new helper defaults to no-interval-assumption and the caller passes `'daily'`. Do NOT expect a pre-existing `daily` example to copy.
5. **DRIFT — seed "version bump" is necessary-but-not-sufficient alone.** `StrideMailBridge::maybeSeedTemplates()` gates on `$currentVersion = '4'` vs option `stride_mail_templates_seeded` (`:660`), and `seedTemplates()` skips any slug that already exists via `get_page_by_path` (`:686`). Bumping `'4' → '5'` re-runs the seed, which creates ONLY the three new slugs (existing ones are skipped). Correct and safe — but the task must bump the literal `'4'` to `'5'` AND add the three defs to `getTemplateDefinitions()`; the bump without the defs seeds nothing new.
6. **DRIFT — `reminder_state` is net-new storage; schema is at v2.** `RegistrationTable::SCHEMA_VERSION = 2` (`:25`); no reminder column exists. Add `reminder_state JSON NULL` mirroring `enrollment_data JSON NULL` (`:69`) via a v3 migrate() step using the existing idempotent + retry-transient pattern (`:96-158`). Fresh installs get it via `create()` (add the column to the CREATE TABLE too).
7. **`getPendingApprovals` days_idle — CONFIRMED.** Computed at `:1792` as `(int) floor((time() - strtotime($row->registered_at)) / DAY_IN_SECONDS)`. Add `days_left`/`days_overdue` alongside, from the active deadline (mirror the same floor-div math).

---

## File structure

| File | Responsibility | Create/Modify |
|---|---|---|
| `web/app/mu-plugins/ntdst-core/ntdst-coreloader.php` (or new `core/Schedule.php` required in) | `ntdst_schedule_recurring()` + `ntdst_clear_recurring()` global helpers | Modify |
| `ARCHITECTURE-INVARIANTS.md` | author INV-10 | Modify |
| `Modules/Edition/EditionCPT.php` | `getFields()`: add `gate_deadline`, `post_gate_deadline` | Modify |
| `Modules/Edition/Admin/EditionSessionsMetabox.php` | conditional deadline inputs (Alpine `x-show`) + docs grouping | Modify |
| `Modules/Edition/Admin/EditionAdminController.php` | persist the two new fields on save (mirror `:515`) | Modify |
| `Modules/Enrollment/EnrollmentCompletion.php` | deadline reads + `overdue` in `getTaskAvailability()` | Modify |
| `Modules/Enrollment/RegistrationTable.php` | `reminder_state JSON` column + v3 migration | Modify |
| `Modules/Enrollment/RegistrationRepository.php` | `getReminderState`/`setReminderState`/`findWithActiveDeadline` | Modify |
| `Modules/Reminder/GateReminderService.php` | **NEW** daily cron service (enumerate → decide → idempotent send) | Create |
| `Modules/Reminder/GateReminderDueCalculator.php` | **NEW** pure date-math: which mail is due today for a phase | Create |
| `Modules/Mail/StrideMailBridge.php` | mail #1 event bindings + 3 template defs + seed bump `'4'→'5'` | Modify |
| `Admin/ActionQueueService.php` | `gate_reminder_days` in `DEFAULTS` | Modify |
| `Admin/StrideSettingsService.php` | expose/read `gate_reminder_days` (clamp) in `getNotificationRules()` | Modify |
| `templates/admin/settings/tab-notifications.php` | the settings input (escaped) | Modify |
| `Admin/AdminAPIController.php` | `days_left`/`days_overdue` + active deadline in `getPendingApprovals` | Modify |
| `stride-core/plugin-config.php` | register `GateReminderService` | Modify |
| `templates/admin/handleiding.php` | admin-facing explainer (ships-time task) | Modify |
| `tests/Unit/…`, `tests/Integration/…`, `tests/acceptance/…` | per-task tests | Create |

---

# PHASE 1 — Edition deadline fields + admin UI (STANDARD tier)

Pure edition-field/metabox work — no 1a surface, no cron, no mail. STANDARD review.

### Task 1.1: Add `gate_deadline` + `post_gate_deadline` to `EditionCPT::getFields()`

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/EditionCPT.php` (near `:154`, the `selection_deadline` def)
- Test: `tests/Unit/Modules/Edition/EditionCPTFieldsTest.php`

**Interfaces — Produces:** two new fields `gate_deadline`, `post_gate_deadline` (type `text`), readable via `EditionRepository::getField($editionId, 'gate_deadline')`.

**Tier:** B — glue: a field-definition addition mirroring the existing `selection_deadline` shape. `no unit test: Tier B, field-registration mirrors an existing verified field; covered by the metabox render test (1.2) and the availability test (Phase 3).`

- [ ] **Step 1:** Add both fields to `getFields()` copying the `selection_deadline` array shape (type `text`, label Dutch: "Deadline taken (vragenlijst & documenten)" / "Deadline afronding (evaluatie & documenten)").
- [ ] **Step 2:** Verify registration: `ddev exec wp eval "print_r(ntdst_get(\Stride\Modules\Edition\EditionRepository::class)->getField(<seededEditionId>, 'gate_deadline'));"` → returns `''`/null, no error.
- [ ] **Step 3:** Commit.

### Task 1.2: Conditional deadline inputs in the metabox (Alpine `x-show`) + docs grouping

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionSessionsMetabox.php` (near the `selection_deadline` input at `:388` and the required-documents block)
- Test: `tests/Unit/Modules/Edition/EditionSessionsMetaboxRenderTest.php` (assert the two inputs render with `esc_attr` value + `x-show` on the enable checkbox)

**Interfaces — Consumes:** `gate_deadline`/`post_gate_deadline` from `EditionRepository::getField()` (Task 1.1). **Produces:** `<input name="ntdst_fields[gate_deadline]">` / `[post_gate_deadline]` in the edition metabox.

**Tier:** B — presentational/glue: metabox HTML with `x-show`. `no unit test for behavior: Tier B, UI markup. A render-assertion test IS included (esc_attr + name + x-show present) as a cheap guard, not a logic test.`

- [ ] **Step 1:** Render `gate_deadline` input UNDER the questionnaire/documents gate group, `x-show` bound to (questionnaire-enabled OR documents-enabled); group the docs deadline visually into the required-documents block per spec.
- [ ] **Step 2:** Render `post_gate_deadline` input under the post-course evaluation/documents group, same `x-show` pattern.
- [ ] **Step 3:** Escape both values with `esc_attr` (INV-5). Write the render-assertion test; run it (Red if markup missing) → implement → Green.
- [ ] **Step 4:** Commit.

### Task 1.3: Persist the two fields on edition save

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAdminController.php` (mirror `:515-516`, the `selection_deadline` persist)
- Test: `tests/Integration/EditionDeadlineFieldsPersistTest.php`

**Interfaces — Produces:** saved `gate_deadline`/`post_gate_deadline` on the edition post.

**Tier:** A — write path: persistence with `sanitize_text_field`. **Test contract:** RED-first — saving an edition with `gate_deadline='2026-08-01'` persists exactly that; a value with HTML tags is stripped by `sanitize_text_field`; omitted field leaves prior value untouched.

- [ ] **Step 1:** Write the failing integration test (save via the controller path, read back via `EditionRepository::getField`).
- [ ] **Step 2:** Run → FAIL (field not persisted).
- [ ] **Step 3:** Add the two `isset(...) sanitize_text_field(...)` persists mirroring `:515-516`.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit.

── REVIEW GATE ── (tier: STANDARD — edition fields + metabox UI + one gated save persist; no 1a surface, no new invariant. 2 finders + simplicity + a feature-acceptance browser pass on the metabox conditional-show.)

---

# PHASE 2 — RegistrationTable `reminder_state` storage (FULL tier)

Touches the data layer / a migration on `wp_vad_registrations` (INV-3 owned table) — FULL per the tier table (data layer / migrations row).

### Task 2.1: Add `reminder_state JSON` column + v3 migration

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationTable.php` (`SCHEMA_VERSION 2→3`; add column to CREATE TABLE `:59-69`; add v3 step to `migrate()` `:96-158`)
- Test: `tests/Integration/RegistrationTableReminderStateMigrationTest.php`

**Interfaces — Produces:** column `reminder_state JSON NULL` on `wp_vad_registrations`.

**Tier:** A — migration. **Test contract:** RED-first — on a v2 table, `migrate()` adds the `reminder_state` column and stamps version 3; re-running `migrate()` is a no-op (idempotent); a fresh `create()` builds the column directly. Assert the retry-transient path: a simulated `ALTER` failure does NOT stamp v3.

- [ ] **Step 1:** Write the failing migration test (create v2 table without column → `migrate()` → assert column exists + version==3 + second run no-op).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Bump `SCHEMA_VERSION = 3`; add `reminder_state JSON NULL` to CREATE TABLE (after `enrollment_data`); add the v3 `ALTER TABLE … ADD COLUMN reminder_state JSON NULL` step to `migrate()` using the existing idempotent + `RETRY_TRANSIENT` + `ntdst_log('enrollment')->error` pattern.
- [ ] **Step 4:** Run → PASS. Run migration locally: `ddev exec wp eval "\Stride\Modules\Enrollment\RegistrationTable::migrate();"`.
- [ ] **Step 5:** Commit.

### Task 2.2: Repository `getReminderState` / `setReminderState`

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationRepository.php`
- Test: `tests/Integration/RegistrationReminderStateRepoTest.php`

**Interfaces — Produces:** `getReminderState(int $regId): array` (decodes JSON, `[]` on null), `setReminderState(int $regId, array $state): bool|WP_Error` (encodes, `$wpdb->prepare`). Shape: `{"enroll":{"reminder":"YYYY-MM-DD"|null,"deadline":"YYYY-MM-DD"|null},"post":{...}}`.

**Tier:** A — data access / round-trip. **Test contract:** RED-first — set then get round-trips the exact array; get on a null column returns `[]`; a DB failure returns `WP_Error` (INV-4), not false.

- [ ] **Step 1:** Write failing test (create reg → set state → get → assert equal; null → `[]`).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement both methods on the repository (the sole `$wpdb` owner of the table; `$wpdb->prepare`).
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit.

### Task 2.3: Repository `findWithActiveDeadline` (bounded scan finder)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationRepository.php`
- Test: `tests/Integration/RegistrationFindWithActiveDeadlineTest.php`

**Interfaces — Produces:** `findWithActiveDeadline(int $limit = 500, int $offset = 0): array` — rows where `status IN ('confirmed','pending')` AND the edition has a non-empty `gate_deadline` OR `post_gate_deadline`, `$wpdb->prepare`, explicit `LIMIT`. (Mitigations 5, 8.)

**Tier:** A — the enumeration query (threat-model A2). **Test contract:** RED-first — returns only confirmed/pending rows whose edition carries an active deadline; EXCLUDES cancelled/completed rows and rows on editions with no deadline; respects `LIMIT`/`offset`.

- [ ] **Step 1:** Write failing test with fixtures: one confirmed+deadline (included), one cancelled+deadline (excluded), one confirmed+no-deadline (excluded).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement the bounded, prepared query (join editions/meta for the deadline predicate).
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit.

── REVIEW GATE ── (tier: FULL — schema migration on the INV-3-owned `wp_vad_registrations` + the cron enumeration query (threat-model A2/A5). All finders + security-sentinel; verify mitigations 5, 8 + the `$wpdb->prepare`/bounded-LIMIT posture.)

---

# PHASE 3 — Deadline reads + `overdue` in the availability convergence point (STANDARD tier)

The single read site. Behavior change in `getTaskAvailability()` + threading the `overdue` key through the render surfaces (Sibling-site audit). No 1a surface, no named-invariant *authoring* here (it consumes the existing availability convergence point) → STANDARD.

### Task 3.1: Read `gate_deadline`/`post_gate_deadline` + set `overdue` in `getTaskAvailability()`

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentCompletion.php` (the four cases at `:200-224`, mirroring the `session_selection` deadline block `:213-224`)
- Test: `tests/Unit/Modules/Enrollment/GateDeadlineAvailabilityTest.php`

**Interfaces — Consumes:** `EditionRepository::getField($editionId, 'gate_deadline'|'post_gate_deadline')`. **Produces:** for `questionnaire`/`documents`/`post_evaluation`/`post_documents`: `['state'=>'available', 'reason'=>"Voltooien voor {d M Y}"|"De deadline is verstreken.", 'overdue'=>bool]`. Task stays `available` even when overdue.

**Tier:** A — availability/gate logic (the convergence point). **Test contract:** RED-first — (a) open task + future `gate_deadline` → `available` + reason "Voltooien voor …" + `overdue=false`; (b) open task + PAST `gate_deadline` → still `available` + reason "De deadline is verstreken." + `overdue=true` (NOT locked, NOT cancelled — D3); (c) no `gate_deadline` set → `available` + empty reason + no `overdue` (or `overdue=false`); (d) `post_gate_deadline` applies to post_evaluation+post_documents identically; (e) `session_selection` behavior UNCHANGED (its past-deadline block still locks — regression guard).

- [ ] **Step 1:** Write the failing test covering (a)–(e).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Read `gate_deadline` once (like `selection_deadline` at `:167`), apply to questionnaire+documents cases; read `post_gate_deadline`, apply to post_evaluation+post_documents. Set reason via the `sprintf(__('Voltooien voor %s','stride'), date_i18n('d M Y', …))` mirror; on past-deadline set reason "De deadline is verstreken." + `overdue=true`, keep `state='available'`.
- [ ] **Step 4:** Run → PASS (incl. the session_selection regression guard).
- [ ] **Step 5:** Commit.

### Task 3.2: Thread `overdue` through the render surfaces (Sibling-site audit)

**Files:**
- Modify: `themes/stridence/templates/dashboard/partials/completion-checklist.php`, `themes/stridence/forms/completion.php`, `Modules/User/UserDashboardService.php` (`:356`, `:730`)
- Test: `tests/acceptance/…` covered in Phase 7; here a targeted assert in `tests/Integration/CompletionOverdueSurfacesTest.php`

**Interfaces — Consumes:** the `overdue` + `reason` keys from Task 3.1.

**Tier:** B — presentational threading (the surfaces read a generic `reason`/`overdue`, they don't compute it). `no unit test: Tier B, template glue reading a derived value. Guarded by the integration assert that the dashboard payload carries overdue + by the Phase-7 acceptance flow.`

- [ ] **Step 1:** Confirm each surface renders the generic `reason`/`state` (not a `session_selection`-only special case); add `overdue`-styled badge where a reason is shown (red "verlopen" marker).
- [ ] **Step 2:** Confirm `UserDashboardService` forwards the `overdue` key (add it to the payload array if it whitelists keys).
- [ ] **Step 3:** Run the integration assert (dashboard payload for an overdue reg carries `overdue=true`) → Green.
- [ ] **Step 4:** Run the sibling-audit grep — `grep -rn "gate_deadline\|post_gate_deadline" web/app` — assert the ONLY reads are getFields/metabox/controller/getTaskAvailability. Commit.

── REVIEW GATE ── (tier: STANDARD — availability logic + template threading, reuses the existing convergence point, no 1a surface. 2 finders + simplicity + feature-acceptance browser pass on the dashboard/completion overdue badge.)

---

# PHASE 4 — ntdst-core cron seam + GateReminderService + idempotency (FULL tier)

The 1a scheduled surface + the NEW invariant (INV-10). FULL.

### Task 4.1: `ntdst_schedule_recurring()` seam + author INV-10

**Files:**
- Modify: `web/app/mu-plugins/ntdst-core/ntdst-coreloader.php` (add the two global helpers at the tail, alongside `ntdst_enqueue_admin_toolkit`) — or a new `core/Schedule.php` required in near `:37`
- Modify: `ARCHITECTURE-INVARIANTS.md` (author INV-10 + add row to the quick-reference table)
- Test: `tests/Integration/NtdstScheduleRecurringTest.php`

**Interfaces — Produces:** `ntdst_schedule_recurring(string $hook, string $interval, callable $cb): void` (guarded `wp_schedule_event` if `!wp_next_scheduled` + `add_action($hook, $cb)`); `ntdst_clear_recurring(string $hook): void` (`wp_clear_scheduled_hook`). Both `function_exists`-guarded.

**Tier:** A — the scheduling primitive every future cron copies (threat-model A5). **Test contract:** RED-first — calling `ntdst_schedule_recurring('t_hook','daily',$cb)` schedules exactly one `daily` event for `t_hook` and binds the action; calling it TWICE does NOT double-schedule (self-healing guard); `ntdst_clear_recurring('t_hook')` unschedules it. The callback body reads no request superglobals (mitigation 7 — assert by design/review, and the helper passes no request data).

- [ ] **Step 1:** Write the failing test (assert `wp_next_scheduled` set once, `has_action` bound, double-call idempotent, clear works).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement both helpers (`function_exists` guards; use the built-in interval passed by the caller — do NOT register a custom `cron_schedules`).
- [ ] **Step 4:** Author INV-10 in `ARCHITECTURE-INVARIANTS.md` (rule + convergence point + bypass signal + the two accepted pre-existing plugin bypasses + audit-move grep). Add the quick-reference row.
- [ ] **Step 5:** Run → PASS. Commit.

### Task 4.2: `GateReminderDueCalculator` — pure "which mail is due today" date-math

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Reminder/GateReminderDueCalculator.php`
- Test: `tests/Unit/Modules/Reminder/GateReminderDueCalculatorTest.php`

**Interfaces — Produces:** `dueMailFor(string $registeredAt, ?string $deadline, int $reminderDays, array $phaseState, string $today): ?string` → returns `'reminder'` | `'deadline'` | `null`. Rule: `deadline` (day-before) takes precedence over `reminder`; each returns null if already marked sent in `$phaseState`; reminder date = `registeredAt + reminderDays`, naturally skipped if `≥ deadline`.

**Tier:** A — the date-math (spec: Tier-A). **Test contract:** RED-first, pure/deterministic (inject `$today`): reminder-day → `'reminder'`; day-before-deadline → `'deadline'`; both due same day → `'deadline'` (collision, day-before wins); reminder already sent → skip to null; past-deadline day-not-tomorrow → null; reminder date ≥ deadline → reminder never returned; no deadline → null.

- [ ] **Step 1:** Write the failing test table (≥7 cases incl. the collision + already-sent + catch-up).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement the pure calculator (no WP calls, `$today` injected — deterministic, no `time()` inside).
- [ ] **Step 4:** Run → PASS (run ≥3× for determinism).
- [ ] **Step 5:** Commit.

### Task 4.3: `GateReminderService` — enumerate → decide → idempotent check-and-mark → send

> **P2-gate carry-forward (2026-07-01, security-sentinel):** the read-decide-write on `reminder_state` is the racy step. WP-Cron is not truly single-threaded — a double-tick can make two runs both read the same pre-mark state, both send, last-write-wins → a DUPLICATE reminder email. Task 4.3 MUST guard the read-modify-write: reuse the existing `RegistrationRepository::acquireSelectionLock()`/`releaseSelectionLock()` advisory-lock idiom (a `reminder_state` lock analogous to the selections lock), OR rely on WP-Cron's own `_transient_doing_cron` overlap guard — decide explicitly and test the double-tick case. This is why the idempotency test contract already names "double cron tick" — the lock is how it's actually made safe.


**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Reminder/GateReminderService.php`
- Modify: `web/app/mu-plugins/stride-core/plugin-config.php` (register the service)
- Test: `tests/Integration/GateReminderServiceTest.php`

**Interfaces — Consumes:** `RegistrationRepository::findWithActiveDeadline` (2.3), `getReminderState`/`setReminderState` (2.2), `GateReminderDueCalculator::dueMailFor` (4.2), `EnrollmentCompletion::getTaskAvailability` (3.1 — "is this phase still incomplete?"), `StrideSettingsService::getNotificationRules()` for `gate_reminder_days` (5.2), `ntdst_schedule_recurring` (4.1), `ndmail_send`. **Produces:** `metadata()`, `init()` (registers the daily job via `ntdst_schedule_recurring('stride_gate_reminders','daily',[$this,'run'])`), `run(): void` (no params — mitigation 7).

**Tier:** A — the idempotency logic (spec: explicitly Tier-A RED-first). **Test contract:** RED-first — **send-once** (one eligible reg → exactly one `ndmail_send`, state marked); **no-resend** (run twice same day → still one send); **catch-up-after-downtime** (reminder date was yesterday, not yet sent → sends today, marks — no spam of missed days); **collision-with-day-before** (reminder-day == deadline-1 → exactly one send, the day-before); **skip-completed** (phase gates complete per `getTaskAvailability` → no send); **invalid-email** (empty/`!is_email` → skipped + `ntdst_log`, no send, state NOT marked so it's not falsely consumed); **send-failure** (`ndmail_send` returns WP_Error → logged, state NOT marked, retries next tick — INV-4).

- [ ] **Step 1:** Write the failing integration test covering all seven contract cases (mock `ndmail_send` to count/capture recipients; inject a fixed "today").
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement `run()`: pull `gate_reminder_days`; iterate `findWithActiveDeadline` in bounded chunks; for each reg+phase, verify the phase is still incomplete via `getTaskAvailability`; derive due mail via `GateReminderDueCalculator`; if due and not marked → resolve+validate `to` email → `ndmail_send` → on success mark state via `setReminderState`, on WP_Error log and leave unmarked. No request superglobals anywhere in `run()`.
- [ ] **Step 4:** Register in `plugin-config.php`.
- [ ] **Step 5:** Run → PASS (≥3×). Commit.

── REVIEW GATE ── (tier: FULL — the daily cron enumerating registrations + sending mail to user-supplied emails (threat-model A1/A2/A5) + the NEW INV-10 invariant. All finders + security-sentinel + invariant-auditor; verify mitigations 4,5,6,7,8 and INV-10 routing (no raw `wp_schedule_event` in stride-core) and INV-3 (no `$wpdb` in the service).)

---

# PHASE 5 — Settings (`gate_reminder_days`) + the three mail templates + mail #1 event (FULL tier)

Settings input (threat-model A3) + mail templates/sends. FULL.

### Task 5.1: `gate_reminder_days` in `ActionQueueService::DEFAULTS`

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Admin/ActionQueueService.php` (`:16` DEFAULTS)
- Test: `tests/Unit/Admin/ActionQueueDefaultsTest.php`

**Interfaces — Produces:** `DEFAULTS['gate_reminder_days'] = ['enabled' => true, 'value' => 7]`.

**Tier:** B — a defaults-constant addition. `no unit test for logic: Tier B, constant addition. A one-line assert that the key exists with default 7 IS included as a cheap guard. Per ground-truth note 2, do NOT add an evaluate() branch — this key is read by the cron, not evaluated as an action-queue rule.`

- [ ] **Step 1:** Add the key. Assert it exists + default 7. **Step 2:** Commit.

### Task 5.2: Expose + clamp `gate_reminder_days` in settings (read + save)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Admin/StrideSettingsService.php` (`getNotificationRules()` — clamp on read; the save sanitizer — clamp on write)
- Modify: `web/app/mu-plugins/stride-core/templates/admin/settings/tab-notifications.php` (the input, `esc_attr`)
- Test: `tests/Unit/Admin/GateReminderDaysClampTest.php` + `tests/Integration/GateReminderDaysSaveTest.php`

**Interfaces — Produces:** `getNotificationRules()['gate_reminder_days']['value']` clamped `[1,365]`; the settings input on the Notifications tab.

**Tier:** A — the clamp + the gated save (threat-model A3, mitigations 1–3). **Test contract:** RED-first — clamp: `0→1`, `-5→1`, `99999→365`, `'abc'→1`, `7→7`; save: the value only persists through the `manage_options`+nonce-gated save handler (assert a non-privileged path does not persist); the echoed input value is `esc_attr`-escaped.

- [ ] **Step 1:** Write the failing clamp unit test + the save integration test (assert clamp applied on both read and write; assert nonce/cap inherited on the save).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement `max(1, min(365, absint($raw)))` in both `getNotificationRules()` and the save sanitizer; add the input to `tab-notifications.php` mirroring a sibling `{key}_value` field with `esc_attr` on `value`.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit.

### Task 5.3: Three reminder templates + seed bump + mail #1 event bindings

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Mail/StrideMailBridge.php` — add `stride-gate-todo`, `stride-gate-reminder`, `stride-gate-deadline-tomorrow` to `getTemplateDefinitions()` (`:723`); bump `maybeSeedTemplates()` `$currentVersion` `'4'→'5'` (`:660`); bind mail #1 (`stride-gate-todo`) on `stride/registration/created` (enroll phase) + a course-completion trigger (completion phase)
- Test: `tests/Integration/GateMailTemplatesSeedTest.php` + `tests/Unit/Modules/Mail/GateTodoEventBindingTest.php`

**Interfaces — Consumes:** the three slugs from `GateReminderService` (4.3) + the event binding. **Produces:** three seeded `ndmail_template` posts; mail #1 fires at enrollment/completion when a deadline exists.

**Tier:** A for the event-binding logic (mail #1 fires only when the phase has a deadline set); B for the template-definition text. **Test contract (mail #1 binding, Tier A):** RED-first — enrolling in an edition WITH `gate_deadline` triggers a `stride-gate-todo` send to the registrant; enrolling in an edition with NO gate deadline sends NO gate-todo. Seed test (integration): after `maybeSeedTemplates()` at v5, the three slugs exist as `ndmail_template` posts and pre-existing slugs are untouched.

- [ ] **Step 1:** Write the failing seed test + the mail#1-binding test.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Add the three template defs (Dutch bodies, `{{completion.url}}`/`{{edition.title}}` smartcodes mirroring existing defs); bump version `'4'→'5'`; bind `stride-gate-todo` send guarded on "does this phase's edition carry a deadline?".
- [ ] **Step 4:** Run → PASS. Re-seed locally: `ddev exec wp eval "ntdst_get(\Stride\Modules\Mail\StrideMailBridge::class)->maybeSeedTemplates();"`.
- [ ] **Step 5:** Commit.

── REVIEW GATE ── (tier: FULL — the `manage_options` settings input (threat-model A3) + mail sends to user-supplied emails (A1). All finders + security-sentinel; verify mitigations 1,2,3,4 + the seed-bump drift note 5.)

---

# PHASE 6 — Admin days-left / overdue visibility (STANDARD tier)

Extends an existing read behind `canViewAdmin()`; display-only (D3). Multi-file behavior change, no 1a surface → STANDARD.

### Task 6.1: `days_left`/`days_overdue` + active deadline in `getPendingApprovals`

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php` (`getPendingApprovals` `:1728`, near the `days_idle` computation `:1792`)
- Test: `tests/Integration/PendingApprovalsDaysLeftTest.php`

**Interfaces — Consumes:** the active `gate_deadline`/`post_gate_deadline` for the pending phase + the `overdue` signal (via `getTaskAvailability`, NOT a raw re-read). **Produces:** per-row `activeDeadline`, `days_left` (int, ≥0) or `days_overdue` (int, when past).

**Tier:** A — the days-left/overdue derivation. **Test contract:** RED-first — a pending reg with a future `gate_deadline` gets `days_left = N` (floor-div mirror of `days_idle`); a past deadline gets `days_overdue = M` + no negative `days_left`; a pending reg with no deadline gets neither key. Derived from the availability convergence point, not a raw `gate_deadline` re-read (Sibling-site audit rule).

- [ ] **Step 1:** Write the failing integration test (future/past/no-deadline rows).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Add the derivation next to `days_idle` (`:1792`), sourcing the deadline+overdue from the availability result for that registration's active phase.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit.

### Task 6.2: Pending-queue badge — "nog N dagen" / red "N dagen te laat"

**Files:**
- Modify: the admin pending-queue render (the Acties-nodig / pending-approvals template or Alpine component consuming `getPendingApprovals`)
- Test: acceptance flow (Phase 7)

**Tier:** B — presentational badge reading `days_left`/`days_overdue`. `no unit test: Tier B, UI badge. Covered by the Phase-7 acceptance flow (overdue badge visible).`

- [ ] **Step 1:** Render "nog {days_left} dagen" or red "{days_overdue} dagen te laat" next to the existing "Wacht op gebruiker" reason.
- [ ] **Step 2:** Commit.

── REVIEW GATE ── (tier: STANDARD — display-only extension of an existing gated read; no 1a surface. 2 finders + simplicity + feature-acceptance browser pass on the pending-queue badge.)

---

# PHASE 7 — Acceptance verification + handleiding (LIGHT tier for the doc; acceptance drives the whole feature)

### Task 7.1: Acceptance flows (drive the `## Acceptance flows` matrix below)

**Files:**
- Create: `tests/acceptance/GateDeadlinesRemindersCest.php`

**Tier:** A — acceptance drives the intended-use flows. See `## Acceptance flows` matrix; each row + its Edges is a scenario. Use Mailpit (`https://stride.ddev.site:8026`) to assert mails; drive the cron via `wp cron event run stride_gate_reminders` after setting `registered_at`/deadline fixtures.

- [ ] Implement one Cest scenario per matrix row (incl. edges). Assert mail presence/absence in Mailpit; assert idempotency by running the cron twice. Commit.

### Task 7.2: Handleiding note (ships-time)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/templates/admin/handleiding.php`

**Tier:** B — doc copy. `no unit test: Tier B, admin-facing documentation.` Add the spec's ready Dutch copy (§ "Handleiding copy") to the Inschrijvingen section after "Opties per editie" + the one `<details>` FAQ entry. This task runs WHEN the feature ships (all prior phases merged) — it describes shipped behavior.

- [ ] Paste the spec's "Deadlines & herinneringen" block + FAQ `<details>` verbatim. Commit.

── REVIEW GATE ── (tier: LIGHT — the handleiding edit is doc/copy only; single generalist pass. NOTE: 7.1 acceptance is verification of the whole branch, not a review cluster — it runs at shake-out under the branch's FULL tier via `netdust-agent:shakeout`.)

---

## Acceptance flows

> Feature-acceptance matrix (gate 1g). Each row is an intended-use flow; the **Edges** column is mandatory (the six edge classes, or why one is excluded). Driven at shake-out via Codeception + Mailpit + `wp cron event run`.

| # | Flow | Steps | Expected | Edges (mandatory) |
|---|---|---|---|---|
| AF-1 | Admin sets the three deadlines | In edition admin, enable questionnaire → `gate_deadline` input appears (x-show); enable evaluation → `post_gate_deadline` appears grouped with docs; save | Both fields persist; conditional show works | **Empty:** no gate enabled → deadline inputs hidden. **Error:** malformed date string → saved as-is (parity with `selection_deadline`, no validator). **Concurrent:** n/a (single admin save). |
| AF-2 | Enroll → mail #1 | Enroll in an edition WITH `gate_deadline` | Registrant receives `stride-gate-todo` at enrollment (Mailpit) | **No-deadline:** enroll in edition with NO deadline → NO gate-todo mail sent. **Past-deadline-at-enroll:** deadline already passed → mail #1 still sends (todo), overdue shows immediately. **Empty:** invalid registrant email → no send, logged. |
| AF-3 | Cron reminder #2 at `registered_at + X` | Set `registered_at` to X days ago, deadline in future, tasks incomplete; run cron | `stride-gate-reminder` sent once | **Idempotency:** run cron twice → exactly one reminder (never twice). **Downtime/catch-up:** reminder day was yesterday, unsent → sends today, once. **Settings change:** set X=3 then X=10 → reminder date recomputes from the current X. |
| AF-4 | Cron day-before #3 | Deadline = tomorrow, tasks incomplete; run cron | `stride-gate-deadline-tomorrow` sent once | **Collision:** reminder-day == deadline−1 → exactly ONE mail (day-before wins). **Idempotency:** run twice → one send. **Completed:** tasks done → no mail. |
| AF-5 | Overdue admin badge | Deadline in the past, tasks incomplete; open Acties-nodig | Red "N dagen te laat" badge; task still `available` (D3 — user can still complete, no cancel) | **Both-phases-active:** an edition with BOTH `gate_deadline` and `post_gate_deadline` overdue → both surfaced correctly, not conflated. **Future:** deadline ahead → "nog N dagen". **Empty:** no deadline → no badge. |
| AF-6 | Settings X reflected end-to-end | Set `gate_reminder_days=10` in Notifications tab; enroll; advance 10 days; run cron | Reminder fires at day 10, not day 7 | **Clamp:** set 0 → stored/used as 1; set 99999 → 365. **Escape:** value round-trips into the input `esc_attr`-safe. **Auth:** non-`manage_options` cannot persist the change. |

---

## Self-review (writing-plans checklist)

- **Spec coverage:** D1 (3 fields) → Task 1.1; D2 (`registered_at+X`) → 4.2/4.3; D3 (flag-only) → 3.1 (state stays `available`) + 6.x (display-only); D4 (shared enroll-phase date) → 1.1 (`gate_deadline` shared by questionnaire+documents in 3.1); D5 (per-phase cadence) → 5.3 mail#1 both phases + 4.3 cron both phases; D6 (cron seam) → 4.1. Setting → 5.1/5.2. Idempotency → 2.x + 4.2/4.3. Admin visibility → 6.x. Handleiding → 7.2. Threat model, invariants, sibling audit — embedded above. All spec sections mapped.
- **Placeholder scan:** every task carries concrete file paths, line anchors, test contracts, and the exact `sprintf/date_i18n` mirror. No TBD/"handle edge cases".
- **Type consistency:** `reminder_state` shape (`{"enroll":{"reminder","deadline"},"post":{…}}`) consistent across 2.2 / 4.2 / 4.3; `dueMailFor(...)` return `'reminder'|'deadline'|null` consistent 4.2↔4.3; `findWithActiveDeadline(int,int)` 2.3↔4.3; `getReminderState/setReminderState` 2.2↔4.3.

## Cluster / tier / gate summary

- **Phase 1** (edition fields + metabox + save): STANDARD — tasks 1.1(B) 1.2(B) 1.3(A).
- **Phase 2** (reminder_state storage: migration + repo scan): **FULL** — 2.1(A) 2.2(A) 2.3(A).
- **Phase 3** (availability deadline reads + overdue threading): STANDARD — 3.1(A) 3.2(B).
- **Phase 4** (cron seam + INV-10 + service + idempotency): **FULL** — 4.1(A) 4.2(A) 4.3(A).
- **Phase 5** (settings clamp + 3 templates + mail#1): **FULL** — 5.1(B) 5.2(A) 5.3(A).
- **Phase 6** (admin days-left/overdue): STANDARD — 6.1(A) 6.2(B).
- **Phase 7** (acceptance + handleiding): LIGHT (doc) — 7.1(A, verification) 7.2(B).

## Execution handoff

Plan complete and saved to `docs/plans/2026-07-01-gate-deadlines-reminders.md`. Recommended: Subagent-Driven — dispatch `netdust-agent:implementer` one per task, review at each `── REVIEW GATE ──` at its declared tier, full shake-out (`netdust-agent:shakeout`, branch tier = FULL because clusters touch a 1a surface + a new invariant) before finishing the branch.
