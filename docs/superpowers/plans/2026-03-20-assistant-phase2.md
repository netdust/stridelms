# ntdst-assistant Phase 2 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the AI assistant with stats/attendance abilities, CSV exports, and UI polish.

**Architecture:** Split the monolithic AbilityRegistrar into Read/Write registrars. Add ExportService to the assistant plugin for CSV generation with signed download URLs. Enhance the Alpine.js UI with timestamps, copy, clear, and download cards.

**Tech Stack:** PHP 8.1+, WordPress Abilities API, Alpine.js, Parsedown, fputcsv

**Spec:** `docs/superpowers/specs/2026-03-20-assistant-phase2-design.md`

---

## File Structure

### New files (stride-core)

| File | Responsibility |
|------|---------------|
| `Modules/Assistant/ReadAbilityRegistrar.php` | 9 read abilities (existing 4 + get-stats, get-attendance + 3 exports) |
| `Modules/Assistant/WriteAbilityRegistrar.php` | 3 write abilities (existing enroll/unenroll + mark-attendance) |

### New files (ntdst-assistant plugin)

| File | Responsibility |
|------|---------------|
| `src/ExportService.php` | CSV generation, signed URLs, file cleanup |

### Modified files

| File | Changes |
|------|---------|
| `Modules/Assistant/AbilityRegistrar.php` | **Deleted** — replaced by split |
| `Modules/Assistant/prompts/domain.md` | Attendance model + stats + link rendering rules |
| `Modules/Assistant/prompts/formatting.md` | Attendance status labels + stats formatting |
| `stride-core/plugin-config.php:45` | Replace AbilityRegistrar with Read/Write |
| `src/ToolExecutor.php:135-188` | Add `$downloads` accumulator in loop |
| `src/ChatController.php` | Add `/clear` + `/download` endpoints, `created_at` |
| `src/Transport/JsonTransport.php:11-27` | Pass through `downloads` array |
| `plugin-config.php` | Register ExportService |
| `ntdst-assistant.php` | Cron registration on activation |
| `assets/css/assistant.css` | Full visual overhaul |
| `assets/js/assistant.js` | Copy, clear, timestamps, auto-resize, download cards |
| `templates/admin/chat.php` | Header, grouping, avatars, copy, empty state, download cards |

---

## Phase 1: New Abilities

### Task 1: Split AbilityRegistrar — ReadAbilityRegistrar

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Assistant/ReadAbilityRegistrar.php`
- Modify: `web/app/mu-plugins/stride-core/plugin-config.php:45`

- [ ] **Step 1: Create ReadAbilityRegistrar with existing 4 read abilities**

Create `ReadAbilityRegistrar.php` with:
- All 4 existing read ability registrations (`search-users`, `get-edition`, `get-editions`, `get-enrollments`)
- All 4 execute callbacks (`searchUsers`, `getEdition`, `getEditions`, `getEnrollments`)
- The `batchRegisteredCounts()` helper
- The `registerCategories()` method (this registrar owns it)
- The `injectDomainPrompts()` method + system_prompt filter (this registrar owns it)
- Extends `AbstractService`, priority 90

```php
<?php
declare(strict_types=1);

namespace Stride\Modules\Assistant;

use Stride\Infrastructure\AbstractService;

final class ReadAbilityRegistrar extends AbstractService
{
    public static function metadata(): array
    {
        return [
            'name' => 'Assistant Read Abilities',
            'description' => 'Read-only abilities for the AI assistant',
            'priority' => 90,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'assistant-read';
    }

    protected function init(): void
    {
        add_action('wp_abilities_api_categories_init', [$this, 'registerCategories']);
        add_action('wp_abilities_api_init', [$this, 'registerAbilities']);
        add_filter('ntdst_assistant/system_prompt', [$this, 'injectDomainPrompts'], 10, 2);
    }

    // ... (copy all read registration + callbacks from AbilityRegistrar)
}
```

- [ ] **Step 2: Update stride-core plugin-config.php**

Replace line 45:
```php
// Old:
\Stride\Modules\Assistant\AbilityRegistrar::class,
// New:
\Stride\Modules\Assistant\ReadAbilityRegistrar::class,
\Stride\Modules\Assistant\WriteAbilityRegistrar::class,
```

- [ ] **Step 3: Verify read abilities load**

```bash
ddev exec wp eval "echo class_exists('\Stride\Modules\Assistant\ReadAbilityRegistrar') ? 'OK' : 'FAIL';"
```

Expected: `OK`

- [ ] **Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Assistant/ReadAbilityRegistrar.php web/app/mu-plugins/stride-core/plugin-config.php
git commit -m "refactor(assistant): extract ReadAbilityRegistrar from AbilityRegistrar"
```

### Task 2: Split AbilityRegistrar — WriteAbilityRegistrar

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Assistant/WriteAbilityRegistrar.php`

- [ ] **Step 1: Create WriteAbilityRegistrar with existing 2 write abilities**

Move from `AbilityRegistrar`:
- `registerWriteAbilities()` (enroll-user, unenroll-user registrations)
- `enrollUser()`, `unenrollUser()` callbacks
- `describeEnrollInput()`, `describeUnenrollInput()` callbacks
- `resolveUserName()`, `resolveEditionTitle()` helpers

```php
<?php
declare(strict_types=1);

namespace Stride\Modules\Assistant;

use Stride\Infrastructure\AbstractService;

final class WriteAbilityRegistrar extends AbstractService
{
    public static function metadata(): array
    {
        return [
            'name' => 'Assistant Write Abilities',
            'description' => 'Write abilities (confirmation required) for the AI assistant',
            'priority' => 90,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'assistant-write';
    }

    protected function init(): void
    {
        add_action('wp_abilities_api_init', [$this, 'registerAbilities']);
    }

    // ... (copy all write registration + callbacks from AbilityRegistrar)
}
```

- [ ] **Step 2: Delete the old AbilityRegistrar.php**

```bash
rm web/app/mu-plugins/stride-core/Modules/Assistant/AbilityRegistrar.php
```

- [ ] **Step 3: Verify both registrars load and old one is gone**

```bash
ddev exec wp eval "
echo class_exists('\Stride\Modules\Assistant\ReadAbilityRegistrar') ? 'Read OK' : 'Read FAIL';
echo ' | ';
echo class_exists('\Stride\Modules\Assistant\WriteAbilityRegistrar') ? 'Write OK' : 'Write FAIL';
echo ' | ';
echo class_exists('\Stride\Modules\Assistant\AbilityRegistrar') ? 'OLD STILL EXISTS' : 'Old gone OK';
"
```

Expected: `Read OK | Write OK | Old gone OK`

- [ ] **Step 4: Commit**

```bash
git add -A web/app/mu-plugins/stride-core/Modules/Assistant/
git commit -m "refactor(assistant): extract WriteAbilityRegistrar, delete old AbilityRegistrar"
```

### Task 3: Add `_links` to existing read abilities

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Assistant/ReadAbilityRegistrar.php`

- [ ] **Step 1: Add `_links` to `searchUsers()` output**

Each user in the `users` array gets:
```php
'_links' => [
    'user_edit' => admin_url("user-edit.php?user_id={$user->ID}"),
],
```

- [ ] **Step 2: Add `_links` to `getEdition()` output**

```php
'_links' => [
    'edition_edit' => admin_url("post.php?post={$edition->ID}&action=edit"),
],
```

- [ ] **Step 3: Add `_links` to `getEditions()` output per edition**

```php
'_links' => [
    'edition_edit' => admin_url("post.php?post={$id}&action=edit"),
],
```

- [ ] **Step 4: Add `_links` to `getEnrollments()` output per enrollment**

```php
'_links' => [
    'user_edit' => admin_url("user-edit.php?user_id={$reg->user_id}"),
    'edition_edit' => admin_url("post.php?post={$reg->edition_id}&action=edit"),
],
```

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Assistant/ReadAbilityRegistrar.php
git commit -m "feat(assistant): add _links to all read ability results"
```

### Task 4: Add `stride/get-stats` ability

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Assistant/ReadAbilityRegistrar.php`

- [ ] **Step 1: Register `stride/get-stats` ability**

Add to `registerAbilities()`:
```php
wp_register_ability('stride/get-stats', [
    'label' => 'Statistieken ophalen',
    'description' => 'Get aggregated statistics for an edition, course, or globally. Returns enrollment counts, fill rate, attendance rate, and completion rate (edition scope only).',
    'category' => 'stride',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'edition_id' => [
                'type' => 'integer',
                'description' => 'Specifieke editie (optioneel)',
            ],
            'course_id' => [
                'type' => 'integer',
                'description' => 'Cursus-ID om edities te filteren (optioneel)',
            ],
        ],
    ],
    'permission_callback' => fn() => current_user_can('stride_view'),
    'execute_callback' => [$this, 'getStats'],
    'meta' => [
        'show_in_rest' => true,
        'annotations' => ['readonly' => true],
        'readonly' => true,
    ],
]);
```

- [ ] **Step 2: Implement `getStats()` callback**

```php
public function getStats(array $input): array
{
    $editionId = (int) ($input['edition_id'] ?? 0);
    $courseId = (int) ($input['course_id'] ?? 0);

    if ($editionId > 0) {
        return $this->getEditionStats($editionId);
    }

    if ($courseId > 0) {
        return $this->getCourseStats($courseId);
    }

    return $this->getGlobalStats();
}
```

Implement three private methods:
- `getEditionStats(int $editionId)` — uses EditionService, RegistrationRepository, AttendanceService, SessionService. Includes `completion_rate` via LearnDash.
- `getCourseStats(int $courseId)` — aggregates across editions for course. Omits `completion_rate`.
- `getGlobalStats()` — aggregates recent 100 editions. Omits `completion_rate`.

All return the spec output format with `scope`, `edition_count`, `total_enrolled`, `total_capacity`, `fill_rate`, `status_breakdown`, `average_attendance_rate`, `_links`.

Zero editions → return zeros with `"message": "Geen edities gevonden."`.

- [ ] **Step 3: Verify ability registers**

```bash
ddev exec wp eval "
\$abilities = wp_get_registered_abilities();
echo isset(\$abilities['stride/get-stats']) ? 'OK' : 'FAIL';
"
```

- [ ] **Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Assistant/ReadAbilityRegistrar.php
git commit -m "feat(assistant): add stride/get-stats ability"
```

### Task 5: Add `stride/get-attendance` ability

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Assistant/ReadAbilityRegistrar.php`

- [ ] **Step 1: Register `stride/get-attendance` ability**

```php
wp_register_ability('stride/get-attendance', [
    'label' => 'Aanwezigheid ophalen',
    'description' => 'Get attendance records for a session, user-in-edition, edition matrix, or all attendance for a user. Returns records with status, summary, and attendance rate.',
    'category' => 'stride',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'session_id' => [
                'type' => 'integer',
                'description' => 'Sessie-ID (optioneel)',
            ],
            'edition_id' => [
                'type' => 'integer',
                'description' => 'Editie-ID (optioneel)',
            ],
            'user_id' => [
                'type' => 'integer',
                'description' => 'Gebruiker-ID (optioneel)',
            ],
        ],
    ],
    'permission_callback' => fn() => current_user_can('stride_view'),
    'execute_callback' => [$this, 'getAttendance'],
    'meta' => [
        'show_in_rest' => true,
        'annotations' => ['readonly' => true],
        'readonly' => true,
    ],
]);
```

- [ ] **Step 2: Implement `getAttendance()` callback**

```php
public function getAttendance(array $input): array|\WP_Error
{
    $sessionId = (int) ($input['session_id'] ?? 0);
    $editionId = (int) ($input['edition_id'] ?? 0);
    $userId = (int) ($input['user_id'] ?? 0);

    if ($sessionId <= 0 && $editionId <= 0 && $userId <= 0) {
        return new \WP_Error('missing_filter', 'Geef session_id, edition_id, of user_id mee.');
    }

    $attendance = ntdst_get(\Stride\Modules\Attendance\AttendanceService::class);
    $sessions = ntdst_get(\Stride\Modules\Edition\SessionService::class);

    if ($sessionId > 0) {
        return $this->getSessionAttendance($attendance, $sessions, $sessionId);
    }

    if ($userId > 0 && $editionId > 0) {
        return $this->getUserEditionAttendance($attendance, $sessions, $userId, $editionId);
    }

    if ($editionId > 0) {
        return $this->getEditionAttendance($attendance, $sessions, $editionId);
    }

    // user_id only — all attendance for user, capped at 200
    return $this->getUserAttendance($attendance, $sessions, $userId);
}
```

Implement four private methods following spec: session scope, user+edition scope, edition scope (cap 500), user-only scope (cap 200). Each returns `{records, summary, attendance_rate}` with `_links.user_edit` per record. Include `truncated` + `message` when cap hit.

Hydrate user data with batch `WP_User_Query` to avoid N+1. Session data from `SessionService::getSession()`.

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Assistant/ReadAbilityRegistrar.php
git commit -m "feat(assistant): add stride/get-attendance ability"
```

### Task 6: Add `stride/mark-attendance` ability

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Assistant/WriteAbilityRegistrar.php`

- [ ] **Step 1: Register `stride/mark-attendance` ability**

```php
wp_register_ability('stride/mark-attendance', [
    'label' => 'Aanwezigheid markeren',
    'description' => 'Mark one or multiple users present/absent/excused for a session. Validates enrollment before marking.',
    'category' => 'stride',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'session_id' => [
                'type' => 'integer',
                'description' => 'Sessie-ID',
            ],
            'user_id' => [
                'type' => 'integer',
                'description' => 'Enkele gebruiker (optioneel als user_ids meegegeven)',
            ],
            'user_ids' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'description' => 'Meerdere gebruikers (optioneel als user_id meegegeven)',
            ],
            'status' => [
                'type' => 'string',
                'enum' => ['present', 'absent', 'excused'],
                'description' => 'Aanwezigheidsstatus',
            ],
        ],
        'required' => ['session_id', 'status'],
    ],
    'permission_callback' => fn() => current_user_can('stride_manage'),
    'execute_callback' => [$this, 'markAttendance'],
    'meta' => [
        'show_in_rest' => true,
        'describe_input' => [$this, 'describeMarkAttendanceInput'],
    ],
]);
```

- [ ] **Step 2: Implement `markAttendance()` callback**

Logic:
1. Resolve user IDs: if `user_ids` provided, use those. Else use `[user_id]`. Validate at least one.
2. Validate session exists via `SessionService::getSession($sessionId)`.
3. Resolve parent edition from `$session['edition_id']`.
4. Validate all users enrolled: `RegistrationRepository::findByEdition($editionId)` → extract user IDs → cross-check. Return `WP_Error` listing invalid IDs if any fail.
5. Execute based on status:
   - `present`: `AttendanceService::markMultiplePresent($sessionId, $userIds, get_current_user_id())`
   - `absent`: loop `AttendanceService::markAbsent($sessionId, $userId, get_current_user_id())`
   - `excused`: loop `AttendanceService::markExcused($sessionId, $userId, get_current_user_id())`
6. Return `{marked: count, session_id, status, message}`.

- [ ] **Step 3: Implement `describeMarkAttendanceInput()` callback**

```php
public function describeMarkAttendanceInput(array $input): string
{
    $sessionId = (int) ($input['session_id'] ?? 0);
    $userIds = $input['user_ids'] ?? [];
    $singleUserId = (int) ($input['user_id'] ?? 0);
    $status = $input['status'] ?? 'present';

    if (empty($userIds) && $singleUserId > 0) {
        $userIds = [$singleUserId];
    }

    $count = count($userIds);
    $statusLabel = match ($status) {
        'present' => 'aanwezig',
        'absent' => 'afwezig',
        'excused' => 'verontschuldigd',
        default => $status,
    };

    $session = ntdst_get(\Stride\Modules\Edition\SessionService::class)->getSession($sessionId);
    $sessionDesc = $session
        ? sprintf('sessie %s (%s)', $session['date'] ?? '?', $session['start_time'] ?? '?')
        : "sessie #{$sessionId}";

    if ($count === 1) {
        $userName = $this->resolveUserName($userIds[0]);
        return sprintf('%s %s markeren voor %s', $userName, $statusLabel, $sessionDesc);
    }

    return sprintf('%d gebruikers %s markeren voor %s', $count, $statusLabel, $sessionDesc);
}
```

- [ ] **Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Assistant/WriteAbilityRegistrar.php
git commit -m "feat(assistant): add stride/mark-attendance ability"
```

### Task 7: Update domain and formatting prompts

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Assistant/prompts/domain.md`
- Modify: `web/app/mu-plugins/stride-core/Modules/Assistant/prompts/formatting.md`

- [ ] **Step 1: Update domain.md**

Append to existing content:

```markdown

## Attendance Model
- Sessions belong to editions. Each session is one meeting day with a date and time slot.
- Attendance status values: present (aanwezig), absent (afwezig), excused (verontschuldigd).
- Excused counts as attended for rate calculations.
- Attendance rate = (present + excused) / total sessions × 100.

## Statistics
- When presenting stats, explain what the numbers mean. Use percentages with 1 decimal.
- Fill rate = enrolled / capacity × 100. If capacity is 0, the edition is e-learning (unlimited).
- Completion rate is only available for single-edition queries (too expensive for bulk).

## Links
- When results include `_links`, include them as markdown links in your response so the admin can click through to the relevant edit page.
- Format: [display text](url). Example: [Jan Peeters](https://stride.ddev.site/wp-admin/user-edit.php?user_id=5)
```

- [ ] **Step 2: Update formatting.md**

Append to existing content:

```markdown
- Attendance status: present → "aanwezig", absent → "afwezig", excused → "verontschuldigd".
- Stats: percentages with 1 decimal (bijv. 72,5%), counts as integers.
- When exporting: mention the filename and number of rows exported.
```

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Assistant/prompts/
git commit -m "feat(assistant): update domain and formatting prompts for attendance and stats"
```

---

## Phase 2: UI Polish

### Task 8: CSS overhaul

**Files:**
- Modify: `web/app/plugins/ntdst-assistant/assets/css/assistant.css`

- [ ] **Step 1: Rewrite assistant.css**

Full replacement. Key changes from current:
- Add `.assistant-header` with title + clear button
- Typography: 15px base, line-height 1.5
- Message grouping: `.msg-group` wrapper, 4px gap within, 16px between
- Assistant avatar: `.msg-avatar` — 28px circle with "S"
- User messages: right-aligned, `#e8f0fe` background
- Assistant messages: left-aligned, `#f5f5f5` background
- Timestamps: `.msg-timestamp` — 12px, #999, below group
- Copy button: `.msg-copy` — hidden by default, visible on `.msg:hover`
- Confirmation expired: `.confirmation-card.is-expired` — greyed out
- Download card: `.download-card` — border, file icon, button
- Input area: min-height 48px, focus ring `#2271b1`, rounded corners
- Empty state: `.assistant-empty` — centered, muted text
- Links in responses: subtle underline, brand color hover

- [ ] **Step 2: Commit**

```bash
git add web/app/plugins/ntdst-assistant/assets/css/assistant.css
git commit -m "feat(assistant): CSS overhaul — typography, grouping, avatars, cards"
```

### Task 9: Alpine.js component rewrite

**Files:**
- Modify: `web/app/plugins/ntdst-assistant/assets/js/assistant.js`

- [ ] **Step 1: Rewrite assistant.js**

Full replacement. Add to existing data:
```javascript
// New properties:
copyTooltip: null,  // message ID showing "Gekopieerd!"
timestampInterval: null,

// New methods:
clear()             // POST /clear, reset messages + pending
copyMessage(msg)    // navigator.clipboard.writeText(msg.content)
relativeTime(iso)   // "zojuist", "2 min geleden", "1 uur geleden"
autoResize(event)   // textarea auto-grow up to 4 lines
```

Changes to existing methods:
- `send()`: add `created_at: new Date().toISOString()` to user message. Reset textarea height after send.
- `handleResponse(data)`: add `created_at: data.created_at` to all message types. Add `downloads: data.downloads || []` to assistant messages. Handle expired confirmation: if error received after confirm, find last confirmation message and set `msg.expired = true`.
- `confirm()`: on error, mark last confirmation message as expired.
- `init()`: start `timestampInterval = setInterval(() => this.$forceUpdate?.(), 30000)` for timestamp refresh.
- `destroy()`: clear interval.

- [ ] **Step 2: Commit**

```bash
git add web/app/plugins/ntdst-assistant/assets/js/assistant.js
git commit -m "feat(assistant): Alpine.js — copy, clear, timestamps, auto-resize, downloads"
```

### Task 10: Template updates

**Files:**
- Modify: `web/app/plugins/ntdst-assistant/templates/admin/chat.php`

- [ ] **Step 1: Rewrite chat.php template**

Key changes:
- Add header bar: `<div class="assistant-header">` with "Stride Assistent" title and clear button (disabled while loading)
- Empty state: shown when `messages.length === 0 && !loading`
- Message rendering: wrap in `.msg-group` logic based on consecutive same-role messages
- Assistant avatar on first message of group
- Copy button on hover for assistant messages (not confirmation/error)
- Timestamps below each message group using `relativeTime(msg.created_at)`
- Download cards after assistant message HTML when `msg.downloads?.length > 0`
- Confirmation card: add `is-expired` class when `msg.expired`
- Auto-resize textarea: `@input="autoResize($event)"`
- Shift+Enter for newline, Enter sends: `@keydown.enter.exact.prevent="send()"`

```html
<!-- Download card template -->
<template x-for="dl in msg.downloads || []" :key="dl.filename">
    <div class="download-card">
        <div class="download-info">
            <span class="download-icon">📄</span>
            <div>
                <strong x-text="dl.filename"></strong>
                <span class="download-meta" x-text="dl.row_count + ' rijen · CSV'"></span>
            </div>
        </div>
        <button @click="window.open(dl.url)" class="button">Downloaden</button>
    </div>
</template>
```

- [ ] **Step 2: Commit**

```bash
git add web/app/plugins/ntdst-assistant/templates/admin/chat.php
git commit -m "feat(assistant): template — header, grouping, copy, timestamps, download cards"
```

### Task 11: Add `/clear` endpoint

**Files:**
- Modify: `web/app/plugins/ntdst-assistant/src/ChatController.php`

- [ ] **Step 1: Register clear route in `registerRoutes()`**

After the `/cancel` route registration, add:

```php
register_rest_route(self::NAMESPACE, '/clear', [
    'methods'             => 'POST',
    'callback'            => [$this, 'handleClear'],
    'permission_callback' => [$this, 'checkPermission'],
]);
```

- [ ] **Step 2: Implement `handleClear()` method**

```php
public function handleClear(WP_REST_Request $request): void
{
    $userId = get_current_user_id();
    $this->store->clear($userId);
    wp_send_json(['cleared' => true]);
}
```

- [ ] **Step 3: Add `created_at` to all transport responses**

In `handleChat()`, after `$result = $this->executor->run(...)`:
```php
$result['created_at'] = gmdate('c');
```

Same in `handleConfirm()` after `$result = $this->executor->runConfirmed(...)`:
```php
$result['created_at'] = gmdate('c');
```

Same in `handleCancel()` after `$result = $this->executor->runCancelled(...)`:
```php
$result['created_at'] = gmdate('c');
```

- [ ] **Step 4: Commit**

```bash
git add web/app/plugins/ntdst-assistant/src/ChatController.php
git commit -m "feat(assistant): add /clear endpoint, created_at timestamps on responses"
```

---

## Phase 3: Export Abilities

### Task 12: Create ExportService

**Files:**
- Create: `web/app/plugins/ntdst-assistant/src/ExportService.php`
- Modify: `web/app/plugins/ntdst-assistant/plugin-config.php`

- [ ] **Step 1: Create ExportService**

```php
<?php
declare(strict_types=1);

namespace NtdstAssistant;

class ExportService implements \NTDST_Service_Meta
{
    private const EXPORT_DIR = 'stride-exports';
    private const URL_EXPIRY = 3600; // 1 hour
    private const MAX_ROWS = 5000;

    public static function metadata(): array
    {
        return [
            'name' => 'Export Service',
            'description' => 'CSV generation with signed download URLs',
            'priority' => 15,
        ];
    }

    public function getMaxRows(): int
    {
        return self::MAX_ROWS;
    }

    /**
     * Generate a CSV file in the exports directory.
     *
     * @param string $prefix  Filename prefix (e.g., 'edities')
     * @param array  $headers Column headers
     * @param array  $rows    Array of row arrays
     * @return array{filepath: string, filename: string, row_count: int, truncated: bool}
     */
    public function generateCsv(string $prefix, array $headers, array $rows): array
    {
        $dir = $this->ensureExportDir();
        $truncated = count($rows) > self::MAX_ROWS;
        $rows = array_slice($rows, 0, self::MAX_ROWS);

        $filename = sprintf('%s_%s_%s.csv', $prefix, gmdate('Y-m-d'), uniqid());
        $filepath = $dir . '/' . $filename;

        $handle = fopen($filepath, 'w');
        // UTF-8 BOM for Excel compatibility
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $headers, ';');

        foreach ($rows as $row) {
            fputcsv($handle, $row, ';');
        }

        fclose($handle);

        return [
            'filepath' => $filepath,
            'filename' => $filename,
            'row_count' => count($rows),
            'truncated' => $truncated,
        ];
    }

    /**
     * Generate a signed download URL for a file.
     */
    public function getSignedUrl(string $filename, int $userId): string
    {
        $expires = time() + self::URL_EXPIRY;
        $token = $this->computeToken($filename, $userId, $expires);

        return rest_url('ntdst-assistant/v1/download') . '?' . http_build_query([
            'file' => $filename,
            'token' => $token,
            'expires' => $expires,
        ]);
    }

    /**
     * Verify a signed download URL.
     */
    public function verifySignedUrl(string $file, string $token, int $expires, int $userId): bool
    {
        if (time() > $expires) {
            return false;
        }

        $expected = $this->computeToken($file, $userId, $expires);
        return hash_equals($expected, $token);
    }

    /**
     * Get the full filesystem path for an export file (with path traversal prevention).
     *
     * @return string|false  Full path or false if invalid
     */
    public function resolveFilePath(string $file): string|false
    {
        $dir = $this->getExportDir();
        $safe = $dir . '/' . basename($file);

        $real = realpath($safe);
        if ($real === false || !str_starts_with($real, realpath($dir))) {
            return false;
        }

        return $real;
    }

    /**
     * Delete export files older than 1 hour.
     */
    public function cleanup(): void
    {
        $dir = $this->getExportDir();
        if (!is_dir($dir)) {
            return;
        }

        $cutoff = time() - self::URL_EXPIRY;
        foreach (glob($dir . '/*.csv') as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }

    private function computeToken(string $filename, int $userId, int $expires): string
    {
        $payload = json_encode([$filename, $userId, $expires]);
        return hash_hmac('sha256', $payload, wp_salt('auth'));
    }

    private function getExportDir(): string
    {
        $uploadDir = wp_upload_dir();
        return $uploadDir['basedir'] . '/' . self::EXPORT_DIR;
    }

    private function ensureExportDir(): string
    {
        $dir = $this->getExportDir();

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);

            // Protect with .htaccess
            file_put_contents($dir . '/.htaccess', "Deny from all\n");
        }

        return $dir;
    }
}
```

- [ ] **Step 2: Register ExportService in plugin-config.php**

Add to the `services` array:
```php
\NtdstAssistant\ExportService::class,
```

- [ ] **Step 3: Commit**

```bash
git add web/app/plugins/ntdst-assistant/src/ExportService.php web/app/plugins/ntdst-assistant/plugin-config.php
git commit -m "feat(assistant): add ExportService — CSV generation + signed URLs"
```

### Task 13: Add `/download` endpoint

**Files:**
- Modify: `web/app/plugins/ntdst-assistant/src/ChatController.php`

- [ ] **Step 1: Add ExportService dependency**

ChatController constructor already takes `ToolExecutor`, `ConversationStore`, `TransportInterface`. We cannot add a 4th (DI limit). Instead, resolve via `ntdst_get()` inside the handler method.

- [ ] **Step 2: Register download route in `registerRoutes()`**

```php
register_rest_route(self::NAMESPACE, '/download', [
    'methods'             => 'GET',
    'callback'            => [$this, 'handleDownload'],
    'permission_callback' => [$this, 'checkPermission'],
    'args' => [
        'file'    => ['required' => true, 'sanitize_callback' => 'sanitize_file_name'],
        'token'   => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
        'expires' => ['required' => true, 'sanitize_callback' => 'absint'],
    ],
]);
```

- [ ] **Step 3: Implement `handleDownload()` method**

```php
public function handleDownload(WP_REST_Request $request): void
{
    $file = $request->get_param('file');
    $token = $request->get_param('token');
    $expires = (int) $request->get_param('expires');
    $userId = get_current_user_id();

    $export = ntdst_get(ExportService::class);

    if (!$export->verifySignedUrl($file, $token, $expires, $userId)) {
        wp_send_json_error(['message' => 'Download link is ongeldig of verlopen.'], 403);
        return;
    }

    $filepath = $export->resolveFilePath($file);
    if ($filepath === false || !file_exists($filepath)) {
        wp_send_json_error(['message' => 'Bestand niet gevonden.'], 404);
        return;
    }

    $filename = basename($filepath);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filepath));
    header('Cache-Control: no-cache, no-store, must-revalidate');

    readfile($filepath);
    unlink($filepath); // One-time download
    exit;
}
```

- [ ] **Step 4: Commit**

```bash
git add web/app/plugins/ntdst-assistant/src/ChatController.php
git commit -m "feat(assistant): add /download endpoint with signed URL verification"
```

### Task 14: Add cron cleanup

**Files:**
- Modify: `web/app/plugins/ntdst-assistant/ntdst-assistant.php`

- [ ] **Step 1: Register cron on activation**

After the existing code at the bottom of `ntdst-assistant.php`:

```php
// Cron: cleanup expired export files
register_activation_hook(__FILE__, function (): void {
    if (!wp_next_scheduled('ntdst_assistant_cleanup_exports')) {
        wp_schedule_event(time(), 'hourly', 'ntdst_assistant_cleanup_exports');
    }
});

register_deactivation_hook(__FILE__, function (): void {
    wp_clear_scheduled_hook('ntdst_assistant_cleanup_exports');
});

add_action('ntdst_assistant_cleanup_exports', function (): void {
    if (class_exists(\NtdstAssistant\ExportService::class)) {
        ntdst_get(\NtdstAssistant\ExportService::class)->cleanup();
    }
});
```

- [ ] **Step 2: Commit**

```bash
git add web/app/plugins/ntdst-assistant/ntdst-assistant.php
git commit -m "feat(assistant): cron cleanup for expired export files"
```

### Task 15: Add ToolExecutor download detection

**Files:**
- Modify: `web/app/plugins/ntdst-assistant/src/ToolExecutor.php`

- [ ] **Step 1: Add `$downloads` accumulator in loop**

In the `loop()` method, after `$messages = $this->store->get($adminUserId);` (line 142), add:
```php
$downloads = [];
```

- [ ] **Step 2: Collect downloads from tool results**

After each successful tool execution (line 218-222 area), before appending to `$toolResults`:
```php
// Check for download_url in result
$result = $execResult['result'];
if (is_array($result) && isset($result['download_url'])) {
    $downloads[] = [
        'url' => $result['download_url'],
        'filename' => $result['filename'] ?? 'export.csv',
        'row_count' => $result['row_count'] ?? 0,
    ];
}
```

- [ ] **Step 3: Attach downloads to final response**

In the "no tool_use" return block (lines 179-189), change to:
```php
if (empty($toolUseBlocks)) {
    $text = $this->extractText($textBlocks);

    $messages[] = ['role' => 'assistant', 'content' => $contentBlocks];
    $this->store->replace($adminUserId, $messages);

    $response = [
        'type' => 'response',
        'text' => $text,
    ];

    if (!empty($downloads)) {
        $response['downloads'] = $downloads;
    }

    return $response;
}
```

- [ ] **Step 4: Pass downloads through in JsonTransport**

In `JsonTransport::deliver()`, the `downloads` key will already pass through `wp_send_json()` since we're not filtering keys. No change needed — just verify.

- [ ] **Step 5: Commit**

```bash
git add web/app/plugins/ntdst-assistant/src/ToolExecutor.php
git commit -m "feat(assistant): ToolExecutor download detection and accumulation"
```

### Task 16: Add export abilities

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Assistant/ReadAbilityRegistrar.php`

- [ ] **Step 1: Register 3 export abilities**

Add to `registerAbilities()`:
- `stride/export-editions`: input schema with `course_id?`, `status?`, `upcoming?`
- `stride/export-enrollments`: input schema with `edition_id?`, `user_id?`, `status?`
- `stride/export-attendance`: input schema with `edition_id?`, `session_id?` (at least one required)

All marked `readonly: true`.

- [ ] **Step 2: Implement `exportEditions()` callback**

1. Query editions using same logic as `getEditions()`.
2. Build rows array with: ID, Titel, Cursus, Startdatum, Einddatum, Prijs, Capaciteit, Ingeschreven, Status.
3. Call `ExportService::generateCsv('edities', $headers, $rows)`.
4. Call `ExportService::getSignedUrl($filename, get_current_user_id())`.
5. Return `{download_url, filename, row_count, truncated?, message?}`.

```php
public function exportEditions(array $input): array
{
    $export = ntdst_get(\NtdstAssistant\ExportService::class);
    // ... query editions, build rows ...

    $result = $export->generateCsv('edities', [
        'ID', 'Titel', 'Cursus', 'Startdatum', 'Einddatum', 'Prijs', 'Capaciteit', 'Ingeschreven', 'Status',
    ], $rows);

    $response = [
        'download_url' => $export->getSignedUrl($result['filename'], get_current_user_id()),
        'filename' => $result['filename'],
        'row_count' => $result['row_count'],
    ];

    if ($result['truncated']) {
        $response['truncated'] = true;
        $response['message'] = 'Export afgekapt op 5000 rijen. Gebruik filters om het resultaat te verfijnen.';
    }

    return $response;
}
```

- [ ] **Step 3: Implement `exportEnrollments()` callback**

Same pattern. CSV columns: ID, Gebruiker, Email, Editie, Status, Inschrijfdatum, Pad.

- [ ] **Step 4: Implement `exportAttendance()` callback**

Same pattern. Validate at least one of `edition_id` or `session_id`. CSV columns: Gebruiker, Email, Sessie, Datum, Status, Gemarkeerd door, Tijdstip.

"Gemarkeerd door" column: resolve to display name via `get_userdata($markedBy)->display_name`.

- [ ] **Step 5: Update domain prompt**

Add to `prompts/domain.md`:
```markdown

## Exports
- When the admin asks to export data, use the appropriate export ability (export-editions, export-enrollments, export-attendance).
- Present the download link and mention the number of rows exported.
- If the export was truncated, explain that filters can be used to narrow the result.
```

- [ ] **Step 6: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Assistant/ReadAbilityRegistrar.php web/app/mu-plugins/stride-core/Modules/Assistant/prompts/domain.md
git commit -m "feat(assistant): add 3 CSV export abilities (editions, enrollments, attendance)"
```

---

## Verification Stages (MANDATORY)

> Run AFTER all implementation tasks. NOT done until all stages pass.
> If ANY stage fails: fix → re-run that stage → continue.

### Stage V1: Static Analysis

```bash
ddev exec vendor/bin/phpcs --standard=PSR12 \
  web/app/mu-plugins/stride-core/Modules/Assistant/ReadAbilityRegistrar.php \
  web/app/mu-plugins/stride-core/Modules/Assistant/WriteAbilityRegistrar.php \
  web/app/plugins/ntdst-assistant/src/ExportService.php \
  web/app/plugins/ntdst-assistant/src/ChatController.php \
  web/app/plugins/ntdst-assistant/src/ToolExecutor.php \
  web/app/plugins/ntdst-assistant/src/Transport/JsonTransport.php \
  web/app/plugins/ntdst-assistant/ntdst-assistant.php \
  web/app/plugins/ntdst-assistant/plugin-config.php
```

Expected: No errors. Fix all issues before proceeding.

### Stage V2: Unit Tests

**Test files to create/update:**
- `tests/Unit/NtdstAssistant/ExportServiceTest.php` — CSV generation, signed URLs, path traversal prevention, cleanup
- `tests/Unit/NtdstAssistant/ToolExecutorTest.php` — Update existing: add test for download accumulation
- `tests/Unit/ReadAbilityRegistrarTest.php` — get-stats, get-attendance, export callbacks
- `tests/Unit/WriteAbilityRegistrarTest.php` — mark-attendance validation, bulk logic

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Expected: ALL tests pass (existing 626 + new tests).

### Stage V3: Acceptance Tests (Browser)

**Test files to create:**
- `tests/acceptance/AssistantChatCest.php`

**Scenarios to cover:**

```
ADMIN FLOW:
  SCENARIO: Chat page loads with empty state
    GIVEN: Admin is logged in
    WHEN: Navigate to Stride Assistant page
    THEN: Empty state message visible, input area enabled

  SCENARIO: Send a message and receive response
    GIVEN: Admin on assistant page
    WHEN: Type "hoeveel edities" and press Enter
    THEN: User message appears, loading indicator shows, assistant response appears

  SCENARIO: Clear conversation
    GIVEN: Admin has sent messages
    WHEN: Click clear button, confirm dialog
    THEN: Messages cleared, empty state shown

  SCENARIO: Copy assistant message
    GIVEN: Assistant has responded
    WHEN: Hover over assistant message, click copy
    THEN: Copy tooltip appears "Gekopieerd!"

ERROR FLOW:
  SCENARIO: Empty message blocked
    GIVEN: Admin on assistant page
    WHEN: Click send with empty textarea
    THEN: Nothing happens, no request sent

EXPORT FLOW:
  SCENARIO: Export generates download card
    GIVEN: Admin asks to export editions
    WHEN: Assistant responds with export
    THEN: Download card appears with filename and row count
```

```bash
ddev exec vendor/bin/codecept run acceptance AssistantChatCest --steps
```

Expected: ALL acceptance tests pass.

### Stage V4: Full Regression

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
ddev exec vendor/bin/codecept run
```

Expected: Zero failures across all suites.

### Stage V5: Smoke Test Checklist

```markdown
## Manual Smoke Test

- [ ] Visit: https://stride.ddev.site/wp-admin/admin.php?page=stride-assistant
      Expected: Chat UI loads with empty state, no console errors
- [ ] Action: Type "hoeveel edities zijn er?" and press Enter
      Expected: Assistant responds with edition count and links
- [ ] Action: Type "geef me de aanwezigheid voor editie X" (use a seeded edition)
      Expected: Attendance records with user links
- [ ] Action: Type "statistieken voor editie X"
      Expected: Stats with fill rate, attendance rate, breakdown
- [ ] Action: Type "exporteer alle edities als csv"
      Expected: Download card appears, clicking downloads a CSV
- [ ] Action: Click copy button on an assistant message
      Expected: Tooltip "Gekopieerd!", clipboard has content
- [ ] Action: Click clear button, confirm
      Expected: Messages cleared, empty state shown
- [ ] Admin: Check browser dev tools network tab
      Expected: All API calls return 200, no 500s
```
