# Stride Mail Integration — Design Spec

**Date:** 2026-03-17
**Status:** Draft
**Scope:** Register SmartCodes, Triggers, seed default templates for netdust-mail

---

## Problem

Stride has no email notifications. Users don't know when their enrollment is confirmed, when tasks are completed, or when quotes are ready. Admins don't get notified about new enrollments, document uploads, or approval requests.

## Solution

Create `StrideMailBridge` — a single service that integrates Stride with the netdust-mail plugin by:
1. Registering Stride-specific SmartCodes (template variables)
2. Registering Stride action hooks as email triggers
3. Seeding default Dutch email templates
4. Adding missing `do_action` calls where needed

No new CPTs, tables, or UI. Admins manage templates via the existing netdust-mail admin.

---

## Architecture

### Single service

```
stride-core/Modules/Mail/StrideMailBridge.php
```

Registered in `plugin-config.php`. Hooks into netdust-mail via filters:
- `ndmail_smartcodes` — register Stride SmartCodes
- `ndmail_triggers` — register Stride triggers

### Admin email

Configurable in Stride settings. Stored as option `stride_admin_email`. Falls back to `get_option('admin_email')`. Filter: `stride/mail/admin_email`.

---

## SmartCodes

Registered via `ndmail_smartcodes` filter. Built-in `user.*`, `site.*`, `date.*` already exist in netdust-mail.

| Category | Code | Resolver |
|----------|------|----------|
| `edition` | `title` | Edition post title |
| `edition` | `start_date` | Formatted start date |
| `edition` | `end_date` | Formatted end date |
| `edition` | `venue` | Location |
| `edition` | `price` | Formatted price (€) |
| `edition` | `url` | Frontend permalink |
| `registration` | `status` | Dutch status label |
| `registration` | `date` | Formatted enrollment date |
| `registration` | `selections` | Comma-separated selected session titles |
| `registration` | `documents` | Comma-separated uploaded filenames |
| `quote` | `number` | Quote number (OFF-YYYY-NNNN) |
| `quote` | `total` | Formatted total (€) |
| `quote` | `url` | User dashboard quote tab URL |
| `certificate` | `url` | LearnDash certificate link |
| `trajectory` | `title` | Trajectory post title |

### SmartCode context resolution

Each SmartCode callback receives `$context` array. The context contains IDs passed from the trigger action. Resolution chain:

- `edition_id` → fetch edition meta for `edition.*` codes
- `registration_id` → fetch registration for `registration.*` codes
- `quote_id` → fetch quote for `quote.*` codes
- `user_id` → already handled by netdust-mail built-in `user.*` codes
- `course_id` → fetch LearnDash certificate for `certificate.url`
- `trajectory_id` → fetch trajectory post for `trajectory.*` codes

---

## Triggers

Registered via `ndmail_triggers` filter. Each trigger maps a Stride action hook to a label and expected context keys.

| Hook | Label | Context |
|------|-------|---------|
| `stride/registration/created` | Nieuwe inschrijving | user_id, edition_id, registration_id |
| `stride/registration/confirmed` | Inschrijving bevestigd | user_id, edition_id, registration_id |
| `stride/registration/cancelled` | Inschrijving geannuleerd | user_id, edition_id, registration_id |
| `stride/enrollment/task_completed` | Taak voltooid | user_id, registration_id, task_type |
| `stride/completion/completed` | Opleiding voltooid | user_id, edition_id, course_id |
| `stride/completion/attendance_complete` | Aanwezigheid voltooid | user_id, edition_id, registration_id |
| `stride/quote/created` | Offerte aangemaakt | user_id, quote_id, edition_id |
| `stride/quote/sent` | Offerte verzonden | user_id, quote_id |
| `stride/quote/session_modifier_blocked` | Prijswijziging geblokkeerd | quote_id, registration_id, user_id |
| `stride/trajectory/enrolled` | Traject inschrijving | user_id, trajectory_id |

### Multiple templates per trigger

Netdust-mail allows multiple templates to share the same trigger. When a trigger fires, ALL active templates with that trigger send. So for `stride/registration/created`, both a user template and an admin template can be configured.

Admin templates use the Stride admin email as recipient (configured via template `to` override or SmartCode).

---

## Missing Action Hooks

These hooks need to be added to existing Stride code:

| Hook | Location | Currently |
|------|----------|-----------|
| `stride/registration/confirmed` | `EnrollmentService::confirmRegistration()` | No action fired |

The `stride/enrollment/task_completed` hook already passes `task_type` in its data, so we can conditionally match templates (e.g., only fire admin notification when `task_type === 'documents'`). However, netdust-mail triggers fire on any action invocation — they can't filter by payload. So for task-specific templates, the `StrideMailBridge` listens to the action and calls `ndmail_send()` directly with the right template slug, rather than using automatic trigger matching.

### Conditional dispatch pattern

For `stride/enrollment/task_completed`, instead of auto-trigger:

```php
add_action('stride/enrollment/task_completed', function(array $data) {
    $taskType = $data['task_type'] ?? '';

    // Document upload → notify admin
    if ($taskType === 'documents' || $taskType === 'post_documents') {
        ndmail_send('stride-task-documents-admin', $data);
    }

    // All tasks done, approval required → notify admin
    // (handled by checking if approval task is now available)
});
```

This gives fine-grained control over which template fires for which task type.

---

## Default Templates (Seeded)

11 templates with Dutch content. Created as `ndmail_template` CPT posts.

| Slug | Trigger | Recipient | Subject |
|------|---------|-----------|---------|
| `stride-enrollment-created-user` | stride/registration/created | User | Bevestiging inschrijving: {{edition.title}} |
| `stride-enrollment-created-admin` | stride/registration/created | Admin | Nieuwe inschrijving: {{user.display_name}} voor {{edition.title}} |
| `stride-enrollment-confirmed` | stride/registration/confirmed | User | Inschrijving bevestigd: {{edition.title}} |
| `stride-enrollment-cancelled` | stride/registration/cancelled | User | Inschrijving geannuleerd: {{edition.title}} |
| `stride-task-documents-admin` | *(manual dispatch)* | Admin | Documenten ontvangen: {{user.display_name}} |
| `stride-task-approval-needed` | *(manual dispatch)* | Admin | Goedkeuring vereist: {{user.display_name}} voor {{edition.title}} |
| `stride-completion-user` | stride/completion/completed | User | Opleiding voltooid: {{edition.title}} |
| `stride-quote-created` | stride/quote/created | User | Je offerte {{quote.number}} is aangemaakt |
| `stride-quote-sent` | stride/quote/sent | User | Offerte {{quote.number}} |
| `stride-modifier-blocked-admin` | stride/quote/session_modifier_blocked | Admin | Prijswijziging kon niet worden verwerkt |
| `stride-trajectory-enrolled` | stride/trajectory/enrolled | User | Inschrijving traject: {{trajectory.title}} |

### Template seeding

- Runs once via `StrideMailBridge::seedTemplates()`
- Checks for existing templates by slug before creating
- Called on first activation or via WP-CLI
- All templates created with `status=active`

### Admin templates recipient

Admin-targeted templates set their recipient to the Stride admin email. This is done via the template's built-in `to` field or by passing `['to' => $adminEmail]` in the `ndmail_send()` options.

---

## Attachments

| Email | Attachment | Implementation |
|-------|-----------|----------------|
| Quote emails | Quote PDF | Future work — PDF generator not built yet |
| Document confirmation | None | Filenames listed via `{{registration.documents}}` SmartCode |
| Completion email | Certificate | Link via `{{certificate.url}}` (LearnDash URL, not file) |

---

## Files Affected

| File | Change |
|------|--------|
| `stride-core/Modules/Mail/StrideMailBridge.php` | **Create** — SmartCodes, Triggers, template seeding, conditional dispatch |
| `stride-core/plugin-config.php` | Register StrideMailBridge service |
| `stride-core/Modules/Enrollment/EnrollmentService.php` | Add `do_action('stride/registration/confirmed', ...)` |
| `stride-core/Handlers/CompletionTaskHandler.php` | Add conditional mail dispatch for document/approval tasks |

---

## Out of Scope

- Cron-based reminder emails (separate plan)
- Quote PDF generation and attachment
- Certificate PDF attachment (LearnDash only provides URLs)
- Instructor-per-edition email routing (future — filter allows extension)
- Custom email layout/branding (uses netdust-mail default layout)
