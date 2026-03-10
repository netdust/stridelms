# Audit-Driven Notifications Design

**Date:** 2026-03-10
**Status:** Approved
**Scope:** Rewrite NotificationService to derive real event notifications from audit log

## Problem

Current `NotificationService` derives fake notifications from dashboard action items (UserDashboardService). This produces vague titles like "Sessiekeuze" and "Inschrijving" — just a duplicate of the home tab's action list. Users need real event-driven notifications about things that happened to them.

## Decision

**Approach A: Pure audit log query.** No new tables. Rewrite `NotificationService` to query `wp_audit_log` directly for events where the user is the subject (not the actor). Add one new audit event for session note updates.

## Data Flow

```
Admin action (enroll user, mark attendance, update session note)
    → WordPress hook fires
    → AuditBridge records to wp_audit_log (existing)
    → NotificationService.getNotifications($userId)
        queries wp_audit_log WHERE context->user_id = $userId
                               AND actor_id != $userId
                               AND created_at > 30 days ago
    → Maps audit entries to notification format
    → Merges with read state from user meta (_stride_notifications_read)
    → Returns to template
```

## Notification Types

| Audit Action | Type | Icon | Color | Message Template |
|---|---|---|---|---|
| `registration.created` | `enrollment` | `check-circle` | green | "Je inschrijving voor {edition_title} is bevestigd" |
| `registration.cancelled` | `enrollment` | `x-circle` | red | "Je inschrijving voor {edition_title} is geannuleerd" |
| `attendance.marked_present` | `attendance` | `check` | green | "Je aanwezigheid op {session_date} is geregistreerd" |
| `attendance.marked_absent` | `attendance` | `alert-circle` | amber | "Je bent afwezig gemeld op {session_date}" |
| `attendance.marked_excused` | `attendance` | `info` | blue | "Je bent verontschuldigd op {session_date}" |
| `completion.course_completed` | `completion` | `award` | green | "Je hebt {course_title} afgerond" |
| `completion.certificate_issued` | `certificate` | `file-text` | green | "Je certificaat voor {course_title} is beschikbaar" |
| `session.note_updated` | `session` | `info` | blue | "Sessie {session_date} is bijgewerkt" |

### Filtering Rule

Only show events where `actor_id != user_id` (or `actor_id IS NULL`). Self-triggered actions (user enrolls themselves) are noise — the user already knows.

### URL Targets

- Enrollment notifications → edition detail page
- Attendance → edition detail page
- Completion/certificate → course page (certificate link from context if available)
- Session note → edition detail page

### Context Enrichment

Titles and dates are resolved at render time from IDs stored in audit context. No extra data stored in audit entries.

## Changes Required

### 1. AuditRepository — add `findBySubjectUser()`

New method querying JSON context:

```php
public function findBySubjectUser(int $userId, int $limit = 50, int $daysBack = 30): array
```

SQL logic:
- `WHERE JSON_EXTRACT(context, '$.user_id') = $userId`
- `AND (actor_id IS NULL OR actor_id != $userId)`
- `AND created_at > NOW() - $daysBack days`
- `ORDER BY created_at DESC LIMIT $limit`

Session note events (`session.note_updated`) don't have `context.user_id` — they're about the session, not a specific user. These are fetched separately: query session notes for editions where the user has an active registration, then merge into the main list.

### 2. AuditBridge — add session note hook

Listen to `save_post_vad_session`. Compare old vs new `_ntdst_description` meta. If changed, record:

```php
$this->audit()->record(
    'session',
    $sessionId,
    'session.note_updated',
    get_current_user_id(),
    ['session_id' => $sessionId, 'edition_id' => $editionId]
);
```

Event-only logging — no note content stored in audit context. The notification links to the edition page where the user can read the current note.

### 3. NotificationService — rewrite getNotifications()

- Replace `UserDashboardService` dependency with `AuditService`
- Query audit log via `findBySubjectUser()`
- Map each audit entry to notification format using message templates
- Read state stays in user meta `_stride_notifications_read` (unchanged)
- `markAllRead()` / `markRead()` unchanged
- Remove `UserDashboardService` constructor dependency

### 4. notification-item.php — update icon mapping

Expand type → icon/color mapping:

```php
[$icon, $iconColor, $iconBg] = match ($type) {
    'enrollment'  => ['check-circle', 'text-green-600', 'bg-green-50'],
    'attendance'  => ['check', 'text-blue-600', 'bg-blue-50'],
    'completion'  => ['award', 'text-green-600', 'bg-green-50'],
    'certificate' => ['file-text', 'text-green-600', 'bg-green-50'],
    'session'     => ['info', 'text-blue-600', 'bg-blue-50'],
    default       => ['bell', 'text-primary', 'bg-primary/10'],
};
```

Handle cancelled registration and absent attendance with different colors in the message builder, not in the partial (the `type` stays `enrollment`/`attendance`, the message text makes the status clear).

## What Stays Unchanged

- `tab-meldingen.php` template structure
- Read state storage (`_stride_notifications_read` user meta)
- `markAllRead()` / `markRead()` API handlers
- Sidebar notification badge count
- "Vandaag" / "Eerder" grouping logic in template

## GDPR Considerations

- Audit log is the compliance trail — session note updates are legitimate admin actions to audit
- Only the event is logged (who/what/when), not the note content
- No sensitive text stored in audit context for session notes
- Existing 7-year retention policy applies
