# ntdst-assistant Phase 2 — Design Spec

**Date:** 2026-03-20
**Branch:** from staging
**Plugin:** `web/app/plugins/ntdst-assistant/`
**Domain abilities:** `web/app/mu-plugins/stride-core/Modules/Assistant/`

## Overview

Extend the ntdst-assistant plugin with new abilities, UI polish, and CSV export capabilities. Three workstreams executed in order:

1. **New abilities** — `get-stats`, `get-attendance`, `mark-attendance`
2. **UI polish** — visual refinement, UX improvements, rich link rendering
3. **Export abilities** — CSV generation with signed download URLs

## Scope Exclusions

- No `create-offering` ability (deferred)
- No Excel/DOCX export (CSV only)
- No conversation history/sidebar (deferred)
- No SSE streaming (stubbed, not implemented)

---

## 1. New Abilities

### 1.1 Split AbilityRegistrar

The current `AbilityRegistrar.php` (648 lines) exceeds the 150-line NTDST service limit. Split into:

| File | Location | Abilities |
|------|----------|-----------|
| `ReadAbilityRegistrar.php` | `Modules/Assistant/` | `search-users`, `get-editions`, `get-edition`, `get-enrollments`, `get-stats`, `get-attendance` |
| `WriteAbilityRegistrar.php` | `Modules/Assistant/` | `enroll-user`, `unenroll-user`, `mark-attendance` |

Both implement `NTDST_Service_Meta`, register on `wp_abilities_api_init` (priority 90). The category registration (`stride`) moves to `ReadAbilityRegistrar` (loads first).

### 1.2 `stride/get-stats` (read)

Returns aggregated statistics for an edition, course, or globally.

**Input schema:**
```json
{
  "type": "object",
  "properties": {
    "edition_id": {"type": "integer", "description": "Specifieke editie (optioneel)"},
    "course_id": {"type": "integer", "description": "Cursus-ID om edities te filteren (optioneel)"}
  }
}
```

- No required fields — empty input returns global overview.
- `edition_id` takes precedence over `course_id`.

**Output:**
```json
{
  "scope": "edition|course|global",
  "edition_count": 3,
  "total_enrolled": 87,
  "total_capacity": 120,
  "fill_rate": 72.5,
  "status_breakdown": {"confirmed": 72, "pending": 10, "cancelled": 5},
  "completion_rate": 45.8,
  "average_attendance_rate": 91.2,
  "_links": {
    "edition_edit": "https://stride.ddev.site/wp-admin/post.php?post=42&action=edit"
  }
}
```

**Service dependencies:** `EditionService`, `RegistrationRepository`, `AttendanceService`, `SessionService`

**Logic:**
- **Edition scope:** count confirmed/pending/cancelled registrations, compute fill rate from capacity, compute attendance rate across all sessions, compute completion rate from LearnDash.
- **Course scope:** aggregate across all editions for that course.
- **Global scope:** aggregate across all editions. Limit to recent 100 editions for performance.

### 1.3 `stride/get-attendance` (read)

Returns attendance records for a session, user-in-edition, or full edition matrix.

**Input schema:**
```json
{
  "type": "object",
  "properties": {
    "session_id": {"type": "integer", "description": "Sessie-ID (optioneel)"},
    "edition_id": {"type": "integer", "description": "Editie-ID (optioneel)"},
    "user_id": {"type": "integer", "description": "Gebruiker-ID (optioneel)"}
  }
}
```

At least one of `session_id`, `edition_id`, or `user_id` required (validated in callback).

**Output:**
```json
{
  "records": [
    {
      "user_id": 5,
      "user_name": "Jan Peeters",
      "user_email": "jan@example.com",
      "session_id": 10,
      "session_date": "2026-03-25",
      "session_time": "09:00-12:00",
      "status": "present",
      "marked_by": 1,
      "marked_at": "2026-03-25 09:15:00",
      "_links": {
        "user_edit": "https://stride.ddev.site/wp-admin/user-edit.php?user_id=5"
      }
    }
  ],
  "summary": {"present": 12, "absent": 2, "excused": 1, "total": 15},
  "attendance_rate": 86.7
}
```

**Service dependencies:** `AttendanceService`, `SessionService`

**Logic:**
- **Session scope:** `AttendanceService::getSessionAttendance($sessionId)` + user data hydration.
- **User+edition scope:** `AttendanceService::getUserEditionAttendance($userId, $editionId)` + session data hydration.
- **Edition scope:** iterate sessions, collect all attendance. Limit to 500 records.

### 1.4 `stride/mark-attendance` (write, confirmation required)

Marks one or multiple users present/absent/excused for a session.

**Input schema:**
```json
{
  "type": "object",
  "properties": {
    "session_id": {"type": "integer", "description": "Sessie-ID"},
    "user_id": {"type": "integer", "description": "Enkele gebruiker (optioneel als user_ids meegegeven)"},
    "user_ids": {
      "type": "array",
      "items": {"type": "integer"},
      "description": "Meerdere gebruikers (optioneel als user_id meegegeven)"
    },
    "status": {
      "type": "string",
      "enum": ["present", "absent", "excused"],
      "description": "Aanwezigheidsstatus"
    }
  },
  "required": ["session_id", "status"]
}
```

One of `user_id` or `user_ids` required (validated in callback). If both provided, `user_ids` takes precedence.

**Confirmation summary (via `describe_input`):**
- Single: "Jan Peeters aanwezig markeren voor sessie 25 maart 2026 (09:00-12:00)"
- Bulk: "5 gebruikers aanwezig markeren voor sessie 25 maart 2026 (09:00-12:00)"

**Output:**
```json
{
  "marked": 5,
  "session_id": 10,
  "status": "present",
  "message": "5 gebruikers gemarkeerd als aanwezig."
}
```

**Service dependencies:** `AttendanceService`

**Logic:**
- Resolve user IDs (single → array of one).
- Validate session exists via `SessionService::getSession()`.
- Validate all user IDs are enrolled in the edition via `RegistrationRepository::findByEdition()`.
- Call `AttendanceService::markPresent/markAbsent/markExcused` per status.
- For bulk present: use `markMultiplePresent()`.

### 1.5 Rich Links in Ability Results

All abilities include a `_links` object in their results with relevant admin URLs:

| Ability | Link keys |
|---------|-----------|
| `search-users` | `user_edit` per user |
| `get-edition` | `edition_edit` |
| `get-editions` | `edition_edit` per edition |
| `get-enrollments` | `user_edit` + `edition_edit` per enrollment |
| `get-stats` | `edition_edit` (if edition scope) |
| `get-attendance` | `user_edit` per record |
| `mark-attendance` | (none — confirmation summary is sufficient) |

URL pattern: `admin_url("user-edit.php?user_id={$id}")` / `admin_url("post.php?post={$id}&action=edit")`

### 1.6 Domain Prompt Updates

Update `prompts/domain.md`:
- Add attendance model: sessions belong to editions, status values (present/absent/excused), excused counts as attended.
- Stats guidance: explain what numbers mean, use percentages.
- Link rendering: "When results include `_links`, include them as markdown links in your response so the admin can click through to the relevant edit page."

Update `prompts/formatting.md`:
- Attendance status labels: present → "aanwezig", absent → "afwezig", excused → "verontschuldigd"
- Stats formatting: percentages with 1 decimal, counts as integers.

---

## 2. UI Polish

### 2.1 Visual Refinement (CSS)

Changes to `assets/css/assistant.css`:

- **Typography:** 15px base, line-height 1.5, proper paragraph/list spacing in HTML responses.
- **Message grouping:** consecutive messages from same role grouped with tighter spacing (4px gap within group, 16px between groups). Only first message in group shows role indicator.
- **Assistant avatar:** 28px colored circle with "S" before assistant messages, only on first message of group.
- **User messages:** right-aligned, subtle brand color background (#e8f0fe or similar).
- **Assistant messages:** left-aligned, light gray background (#f5f5f5).
- **Timestamps:** small muted text (12px, #999) below each message group. Relative format.
- **Confirmation cards:** clean layout with warning icon, summary text, detail list, confirm/cancel buttons with proper spacing.
- **Input area:** min-height 48px, subtle border (#ddd), focus ring (brand color), rounded corners.
- **Links in responses:** subtle underline, brand color on hover. Admin links get a small external-link indicator.

### 2.2 UX Improvements (Alpine.js)

Changes to `assets/js/assistant.js`:

- **Copy button:** appears on hover over assistant messages. Copies raw markdown (the `content` field, not `html`). Small tooltip "Gekopieerd!" on success.
- **Clear conversation:** button in chat header. Calls `POST /ntdst-assistant/v1/clear`. Resets `messages` array. Confirm with `window.confirm("Gesprek wissen?")`.
- **Auto-resize textarea:** grows with content up to 4 lines (~96px), then scrolls. Resets to 1 line after send.
- **Empty state:** centered message when `messages.length === 0`: "Hoe kan ik helpen? Vraag me om gebruikers, edities, inschrijvingen of aanwezigheid op te zoeken."
- **Keyboard:** `Escape` blurs textarea.
- **Timestamps:** each message object gets `created_at` from server. Rendered as relative time ("2 min geleden", "1 uur geleden"). Updated every 30 seconds via `setInterval`.

### 2.3 New REST Endpoint

`POST /ntdst-assistant/v1/clear`
- Permission: same capability as chat endpoint
- Calls `ConversationStore::clear($userId)`
- Returns `{cleared: true}`

### 2.4 Server-Side Timestamp

`ChatController` adds `created_at` (ISO 8601) to every response payload. The Alpine component stores it with each message.

### 2.5 Template Updates

`templates/admin/chat.php`:
- Add chat header bar with title "Stride Assistent" and clear button.
- Update message rendering for grouping, avatars, timestamps.
- Add copy button markup (hidden, shown on hover via CSS).
- Add empty state markup.

---

## 3. Export Abilities

### 3.1 Export Infrastructure

**Directory:** `wp-content/uploads/stride-exports/`
- Created on first export.
- Protected with `.htaccess`: `Deny from all`.

**ExportService** (`src/ExportService.php` in ntdst-assistant plugin):

```php
class ExportService implements \NTDST_Service_Meta
{
    public function generateCsv(string $filename, array $headers, array $rows): string;
    public function getSignedUrl(string $filepath, int $userId): string;
    public function verifySignedUrl(string $file, string $token, int $expires, int $userId): bool;
    public function cleanup(): void;
}
```

- `generateCsv()`: writes CSV to exports dir, returns absolute filepath. Uses `fputcsv()` with UTF-8 BOM for Excel compatibility.
- `getSignedUrl()`: HMAC-SHA256 over `filename + userId + expires`, 1-hour expiry. Returns full REST URL.
- `verifySignedUrl()`: validates HMAC + expiry + userId match.
- `cleanup()`: deletes files older than 1 hour. Called by cron.

**Cron:** Register `stride_cleanup_exports` event on plugin activation, hourly schedule. Deregister on deactivation.

### 3.2 Download Endpoint

`GET /ntdst-assistant/v1/download`

**Query params:** `file`, `token`, `expires`

**Flow:**
1. Validate `expires` > current time.
2. Verify HMAC via `ExportService::verifySignedUrl()`.
3. Verify current user matches token's user.
4. Stream file with headers: `Content-Type: text/csv`, `Content-Disposition: attachment; filename="..."`.
5. Delete file after streaming (one-time download).

**Errors:** expired → 403, invalid token → 403, file missing → 404.

### 3.3 Export Abilities (3 new reads)

Added to `ReadAbilityRegistrar`:

#### `stride/export-editions`

**Input:**
```json
{
  "course_id": {"type": "integer", "description": "Filter op cursus (optioneel)"},
  "status": {"type": "string", "description": "Filter op status (optioneel)"},
  "upcoming": {"type": "boolean", "description": "Alleen toekomstige edities (optioneel)"}
}
```

**CSV columns:** ID, Titel, Cursus, Startdatum, Einddatum, Prijs, Capaciteit, Ingeschreven, Status

**Output:**
```json
{
  "download_url": "https://...",
  "filename": "edities_2026-03-20.csv",
  "row_count": 42
}
```

#### `stride/export-enrollments`

**Input:**
```json
{
  "edition_id": {"type": "integer", "description": "Filter op editie (optioneel)"},
  "user_id": {"type": "integer", "description": "Filter op gebruiker (optioneel)"},
  "status": {"type": "string", "description": "Filter op status (optioneel)"}
}
```

**CSV columns:** ID, Gebruiker, Email, Editie, Status, Inschrijfdatum, Pad

#### `stride/export-attendance`

**Input:**
```json
{
  "edition_id": {"type": "integer", "description": "Filter op editie (optioneel)"},
  "session_id": {"type": "integer", "description": "Filter op sessie (optioneel)"}
}
```

At least one of `edition_id` or `session_id` required.

**CSV columns:** Gebruiker, Email, Sessie, Datum, Status, Gemarkeerd door, Tijdstip

### 3.4 Download Card UI

The `ToolExecutor` detects when an ability result contains a `download_url` key. It adds a `downloads` array to the response alongside `content`/`html`:

```json
{
  "type": "response",
  "content": "Hier is de export van 42 edities.",
  "html": "<p>Hier is de export van 42 edities.</p>",
  "downloads": [
    {
      "url": "https://...",
      "filename": "edities_2026-03-20.csv",
      "row_count": 42
    }
  ]
}
```

The Alpine component renders download cards after the message text:

```
┌─────────────────────────────────┐
│  📄  edities_2026-03-20.csv    │
│  42 rijen · CSV                 │
│  [Downloaden]                   │
└─────────────────────────────────┘
```

Card styling: subtle border, file icon, filename, row count, download button. Button triggers `window.open(url)`.

### 3.5 System Prompt Update

Add to domain prompt: "When the admin asks to export data, use the appropriate export ability (export-editions, export-enrollments, export-attendance). Present the download link and mention the number of rows exported."

---

## File Inventory

### New files

| File | Purpose |
|------|---------|
| `Modules/Assistant/ReadAbilityRegistrar.php` | Read abilities (6 existing + 3 exports) |
| `Modules/Assistant/WriteAbilityRegistrar.php` | Write abilities (2 existing + mark-attendance) |
| `src/ExportService.php` | CSV generation + signed URLs + cleanup |

### Modified files

| File | Changes |
|------|---------|
| `Modules/Assistant/AbilityRegistrar.php` | **Deleted** — replaced by Read/Write split |
| `Modules/Assistant/prompts/domain.md` | Add attendance model + stats guidance + link rendering instructions |
| `Modules/Assistant/prompts/formatting.md` | Add attendance status labels + stats formatting |
| `src/ChatController.php` | Add `/clear` and `/download` endpoints, add `created_at` to responses |
| `src/ToolExecutor.php` | Detect `download_url` in results, add `downloads` array to response |
| `src/Transport/JsonTransport.php` | Pass through `downloads` array |
| `assets/css/assistant.css` | Full visual overhaul |
| `assets/js/assistant.js` | Copy, clear, timestamps, auto-resize, empty state, download cards |
| `templates/admin/chat.php` | Header bar, message grouping, avatar, copy button, empty state, download card template |
| `plugin-config.php` | Register ExportService, update registrar references |
| `ntdst-assistant.php` | Register cron on activation/deactivation |

### Service registration updates

`stride-core/plugin-config.php`:
- Remove `AbilityRegistrar::class`
- Add `ReadAbilityRegistrar::class`
- Add `WriteAbilityRegistrar::class`

`ntdst-assistant/plugin-config.php`:
- Add `ExportService::class`

---

## Security Considerations

- Export files protected by `.htaccess` + signed URLs + user ID verification.
- Download tokens expire after 1 hour, files cleaned by cron.
- `mark-attendance` validates users are enrolled before marking.
- All new abilities respect existing capability checks (`stride_view` for reads, `stride_manage` for writes).
- CSV files use `fputcsv()` — no injection risk from user data.
- `/clear` endpoint requires same capability as `/chat`.

## Performance Considerations

- `get-stats` global scope limited to 100 most recent editions.
- `get-attendance` edition scope limited to 500 records.
- Export abilities use streaming `fputcsv()` — constant memory regardless of row count.
- Download endpoint streams file (no full read into memory).
- Existing batch query patterns (cache priming, batch counts) used in new abilities.
