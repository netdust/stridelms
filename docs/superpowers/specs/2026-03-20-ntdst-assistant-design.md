# NTDST Assistant — Implementation Design

**Date:** 2026-03-20
**Status:** Draft
**Scope:** AI chat interface for WordPress admins, powered by Claude API with WordPress Abilities as tools.
**Prior art:** `docs/plans/2026-03-11-ntdst-assistant-design.md` (approved concept design — this spec supersedes it with implementation details)

---

## Problem

Stride admins perform repetitive lookup-and-action tasks daily: checking enrollment counts, finding users, enrolling/unenrolling students. Each task requires navigating multiple admin screens. An AI assistant that speaks the domain language and executes actions through existing services would eliminate this friction.

## Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Plugin type | Regular plugin using ntdst-core container | Same patterns as stride-core. Can be activated/deactivated. |
| Abilities API | WordPress 6.9 native | Real, shipped, running on site. Categories, schemas, annotations, REST discovery. |
| Claude client | Interface — SDK (dev), direct HTTP (production) | SDK for fast iteration, zero-dependency HTTP for production. |
| Transport | Interface — JSON (v1), SSE-ready for later | Prove architecture first, add streaming without architectural change. |
| Confirmation | Server-side, structural | Write abilities physically cannot execute without a second request. Safety is structural, not behavioral. |
| Frontend | Alpine.js + server-side markdown (sanitized) | Consistent with Stride admin patterns. No JS markdown dependency. |
| Session state | Server-side message log per conversation | Prevents prompt injection via browser-supplied message history. Browser sends only conversation ID + new user input. |
| Extensibility | 3 hooks: tools filter, system_prompt filter, before/after_execute actions | Covers ability registration, domain injection, execution logging. More hooks added when needed. |
| v1 abilities | 4 read + 2 write | Proves full loop: search → lookup → act → confirm → result. |

---

## Architecture

```
ntdst-assistant plugin (generic, any NTDST project)
  → AssistantService         Admin page, assets, menu
  → ChatController           REST endpoints (/chat, /confirm, /cancel)
  → ToolExecutor             Claude ↔ Abilities conversation loop
  → AbilityBridge            WP Abilities → Claude tool format + confirmation
  → SystemPrompt             Base prompt + filtered domain rules
  → ClaudeClientInterface    SDK or HTTP implementation
  → TransportInterface       JSON or SSE implementation

stride-core (domain-specific)
  → AbilityRegistrar         Registers stride/* abilities on wp_abilities_api_init
```

**Key boundary:** The plugin never imports `Stride\*`. It talks to abilities only through `wp_get_abilities()` and `$ability->execute()`. Stride registers its abilities separately. The plugin works for any NTDST project that registers abilities.

**Hook timing:** The plugin's services register on `ntdst/features_ready` (after_setup_theme). Abilities register on `wp_abilities_api_init` (init). Both hooks fire during WordPress bootstrap, well before any REST API request is handled. `AbilityBridge` reads abilities at request time (inside the `/chat` handler), by which point all abilities are registered.

---

## Plugin Structure

```
web/app/plugins/ntdst-assistant/
├── ntdst-assistant.php              # Bootstrap: check ntdst-core ≥ required version, register services
├── plugin-config.php                # Service list + bindings
├── composer.json                    # anthropic-php SDK (require-dev only)
├── src/
│   ├── AssistantService.php         # Admin page registration, menu item, asset loading
│   ├── ChatController.php           # REST: /ntdst-assistant/v1/chat + /confirm + /cancel
│   ├── AbilityBridge.php            # WP Abilities → Claude tools, execution, confirmation
│   ├── ToolExecutor.php             # Tool loop: send → tools → execute → resume
│   ├── SystemPrompt.php             # Base prompt + ntdst_assistant/system_prompt filter
│   ├── ConversationStore.php        # Server-side message log (transient-based)
│   ├── Contracts/
│   │   ├── ClaudeClientInterface.php
│   │   └── TransportInterface.php
│   ├── Claude/
│   │   ├── SDKClaudeClient.php      # Anthropic SDK (dev/testing)
│   │   └── HttpClaudeClient.php     # wp_remote_post (production)
│   └── Transport/
│       ├── JsonTransport.php        # Buffered JSON response
│       └── SseTransport.php         # SSE streaming (future, stubbed)
├── prompts/
│   └── base.md                      # Generic assistant rules (markdown file)
├── assets/
│   ├── css/assistant.css            # Chat UI styles
│   └── js/assistant.js              # Alpine.js chat component
└── templates/
    └── admin/chat.php               # Chat page HTML template
```

### Stride-side addition

```
stride-core/Modules/Assistant/
├── AbilityRegistrar.php             # Registers stride/* abilities
└── prompts/
    ├── domain.md                    # Domain model + business rules
    └── formatting.md                # Dutch formatting rules
```

---

## Service Registration

**`plugin-config.php`:**

```php
use NtdstAssistant\Contracts\ClaudeClientInterface;
use NtdstAssistant\Contracts\TransportInterface;
use NtdstAssistant\Claude\SDKClaudeClient;
use NtdstAssistant\Claude\HttpClaudeClient;
use NtdstAssistant\Transport\JsonTransport;

return [
    'services' => [
        \NtdstAssistant\AssistantService::class,
        \NtdstAssistant\ChatController::class,
        \NtdstAssistant\AbilityBridge::class,
        \NtdstAssistant\ToolExecutor::class,
        \NtdstAssistant\SystemPrompt::class,
        \NtdstAssistant\ConversationStore::class,
    ],
    'bindings' => [
        // Closure resolves at runtime — checks class_exists for SDK fallback
        ClaudeClientInterface::class => fn() => WP_ENV !== 'production' && class_exists(SDKClaudeClient::class)
            ? ntdst_make(SDKClaudeClient::class)
            : ntdst_make(HttpClaudeClient::class),
        TransportInterface::class => JsonTransport::class,
    ],
];
```

**Bootstrap (`ntdst-assistant.php`):**

- Checks ntdst-core exists AND meets minimum version (graceful deactivation with admin notice if not)
- Checks WordPress ≥ 6.9 (Abilities API requirement)
- Checks API key is configured — shows admin notice if missing with link to WP-CLI command
- Hooks into `ntdst/features_ready` to register services via `ntdst_set()`
- All services implement `NTDST_Service_Meta`

---

## Conversation Store (Server-Side Message Log)

**Why:** The original design had a stateless model where conversation messages round-tripped through the browser. The spec review identified this as a prompt injection vector — an attacker could inject fabricated `assistant` or `tool_result` messages to manipulate Claude. Server-side storage eliminates this.

**Implementation:** WordPress transients with user-scoped keys.

```php
class ConversationStore
{
    // Conversation key: ntdst_assistant_conv_{user_id}
    // Pending key:      ntdst_assistant_pending_{user_id}
    // TTL: 1 hour (conversations are short-lived)

    // Message log
    public function get(int $userId): array;
    public function append(int $userId, array $message): void;
    public function clear(int $userId): void;

    // Pending confirmation state (separate transient)
    public function setPending(int $userId, array $pending): void;
    public function getPending(int $userId): ?array;
    public function clearPending(int $userId): void;
}
```

**Trade-offs:**
- One active conversation per admin user (sufficient for v1)
- Conversation lost after 1 hour of inactivity (acceptable — these are operational chats, not persistent threads)
- No conversation history or replay (deferred to future)

**Page refresh behavior:** A new `/chat` request clears any pending confirmation state server-side before processing. This prevents orphaned pending actions from a previous page load from remaining active. The conversation log itself persists — Claude retains context from before the refresh.

**Request contract change:** The browser sends only:
- `/chat`: `{content: "user's message"}` — not the full messages array
- `/confirm`: `{confirm_token: "..."}` — not the ability name + input
- `/cancel`: `{confirm_token: "..."}` — cancels pending confirmation

---

## Request Lifecycle

### Read query: "Wie is ingeschreven voor Excel?"

```
Browser → POST /ntdst-assistant/v1/chat
  {content: "Wie is ingeschreven voor Excel?"}
    ↓
ChatController
  • Validates nonce + capability (read from ntdst_assistant_capability option)
  • Validates API key is configured — returns error if not
  • Appends user message to ConversationStore
  • Delegates to ToolExecutor
    ↓
ToolExecutor (loop, max 10 iterations, 120s total timeout)
  1. SystemPrompt builds prompt (base + filtered domain rules)
  2. AbilityBridge builds tool definitions (filtered abilities → Claude format)
  3. ClaudeClient sends messages + tools to Claude API
  4. Claude responds: tool_use → stride/get-editions {search: "Excel"}
  5. AbilityBridge: readonly: true → execute immediately
  6. Result: [{id: 108, title: "Excel Basics", enrolled: 12, capacity: 15}]
  7. Append tool_use + tool_result to ConversationStore, loop again
  8. Claude responds: text "Er zijn 12 studenten ingeschreven..."
  9. No more tool_use → loop ends
    ↓
TransportInterface (JsonTransport)
  • Renders Claude's markdown to HTML via Parsedown + wp_kses_post()
  • Returns JSON: {type: "response", html: "..."}
    ↓
Browser
  • Alpine appends assistant message bubble with HTML content
```

### Write action: "Schrijf Jan uit voor die editie"

```
Browser → POST /ntdst-assistant/v1/chat
  {content: "Schrijf Jan uit voor die editie"}
    ↓
ToolExecutor (loop)
  1. Claude responds with multiple tool_use blocks:
     a. stride/search-users {search: "Jan"} → readonly, execute immediately
     b. stride/unenroll-user {user_id: 42, edition_id: 108} → NOT readonly, needs confirmation
  2. Read abilities execute. Write ability pauses.
  3. AbilityBridge builds confirmation summary with resolved labels
  4. Generates confirm_token (HMAC of ability + input + user_id + timestamp)
  5. Stores pending action in ConversationStore
  6. Loop STOPS
    ↓
JsonTransport returns:
  {
    type: "confirmation",
    confirm_token: "hmac...",
    summary: {
      title: "Gebruiker uitschrijven",
      description: "Cancel registration, revoke LMS access",
      details: [
        {label: "Gebruiker", value: "Jan Peeters"},
        {label: "Editie", value: "Excel Basics (maart 2026)"},
        {label: "Gevolgen", value: "LearnDash toegang wordt ingetrokken"}
      ]
    }
  }
    ↓
Browser renders confirmation card:
  ┌─────────────────────────────────────┐
  │ Gebruiker uitschrijven              │
  │                                     │
  │ Gebruiker: Jan Peeters             │
  │ Editie: Excel Basics (maart 2026)  │
  │ Gevolgen: LearnDash toegang wordt  │
  │           ingetrokken               │
  │                                     │
  │ [Annuleren]  [Bevestigen]          │
  └─────────────────────────────────────┘
    ↓
Admin clicks Bevestigen
    ↓
Browser → POST /ntdst-assistant/v1/confirm
  {confirm_token: "hmac..."}
    ↓
ChatController
  • Validates nonce + capability
  • Validates confirm_token (HMAC check — prevents replay/forgery)
  • Retrieves pending action from ConversationStore
  • Re-checks ability permission_callback
  • Delegates to ToolExecutor
    ↓
ToolExecutor
  1. AbilityBridge executes the confirmed ability
  2. Result appended to ConversationStore
  3. Fed back to Claude as tool_result
  4. Claude responds: "Gedaan. Jan is uitgeschreven voor Excel Basics.
     LearnDash toegang is ingetrokken. Er zijn nu 4 plaatsen beschikbaar."
    ↓
JsonTransport → Browser renders final message
```

### Cancel action: Admin clicks Annuleren

```
Browser → POST /ntdst-assistant/v1/cancel
  {confirm_token: "hmac..."}
    ↓
ChatController
  • Validates token
  • Appends tool_result with {cancelled: true, reason: "Admin cancelled"} to ConversationStore
  • Clears pending action
  • Delegates to ToolExecutor to get Claude's acknowledgment
    ↓
ToolExecutor
  • Claude sees the cancellation tool_result
  • Responds: "Begrepen, de actie is geannuleerd."
    ↓
JsonTransport → Browser renders cancellation message
```

### Error paths

| Error | When | Response |
|-------|------|----------|
| API key missing | Plugin activated but key not set | `{type: "error", message: "API key niet geconfigureerd. Stel in via WP-CLI."}` |
| API key invalid | Claude returns 401 | `{type: "error", message: "Claude API-sleutel is ongeldig."}` |
| Rate limited | Claude returns 429 | `{type: "error", message: "Te veel verzoeken. Probeer het over een minuut opnieuw."}` |
| Claude timeout | No response within per-request timeout | `{type: "error", message: "Claude reageert niet. Probeer het opnieuw."}` |
| Max iterations | 10 loop iterations exhausted | `{type: "error", message: "Het verzoek was te complex. Probeer een eenvoudigere vraag."}` |
| Total timeout | 120s wall clock exceeded | `{type: "error", message: "Time-out. Probeer het opnieuw."}` |
| Ability not found | Claude calls non-existent ability | Tool result with `is_error: true`, Claude explains to admin |
| Ability permission denied | permission_callback returns false | Tool result with `is_error: true`, Claude explains to admin |
| Invalid confirm token | Tampered or expired token | `{type: "error", message: "Ongeldige bevestiging. Start opnieuw."}` |

---

## Service Responsibilities

| Service | Responsibility | Estimated size |
|---------|---------------|----------------|
| `AssistantService` | Admin page registration under Stride menu, enqueue CSS/JS, API key admin notice | ~80 lines |
| `ChatController` | REST endpoint registration, request validation, capability check (from option), delegates to ToolExecutor | ~120 lines |
| `ToolExecutor` | The tool loop — orchestrates Claude ↔ Abilities conversation. Max 10 iterations, 120s timeout. Returns structured result to transport. | ~130 lines |
| `AbilityBridge` | Converts WP Abilities to Claude tool format. Handles execution + confirmation decision. Builds human-readable confirmation summaries. Fires extensibility hooks. | ~120 lines |
| `SystemPrompt` | Loads `prompts/base.md`, applies `ntdst_assistant/system_prompt` filter with context | ~60 lines |
| `ConversationStore` | Server-side message log per user via transients. One conversation per user, 1h TTL. | ~50 lines |
| `SDKClaudeClient` | Wraps Anthropic PHP SDK, implements ClaudeClientInterface. 60s per-request timeout. | ~50 lines |
| `HttpClaudeClient` | Direct `wp_remote_post` to Claude Messages API with 60s timeout, implements ClaudeClientInterface | ~80 lines |
| `JsonTransport` | Receives result from ToolExecutor, renders markdown via Parsedown + `wp_kses_post()`, returns JSON | ~40 lines |
| `AbilityRegistrar` (stride-core) | Registers stride/* abilities on `wp_abilities_api_init`, thin execute callbacks delegating to existing services, builds confirmation detail labels | ~150 lines |

---

## AbilityBridge Detail

### Ability inclusion logic

The bridge exposes abilities that have `show_in_rest: true` in their meta. This matches the WordPress Abilities REST API behavior — if an ability isn't visible via REST, it isn't visible to the assistant. The `ntdst_assistant/tools` filter allows further modification.

```php
private function isExposedToAssistant(WP_Ability $ability): bool
{
    return $ability->get_meta_item('show_in_rest', false) === true;
}
```

### Name mapping

Claude tool names cannot contain `/`. The bridge converts transparently:
- WordPress → Claude: `stride/get-editions` → `stride__get-editions`
- Claude → WordPress: `stride__get-editions` → `stride/get-editions`

**Constraint:** Ability names must not contain `__` (double underscore). The WordPress naming convention (`namespace/name` with lowercase alphanumeric and dashes) makes this a non-issue, but the bridge validates on registration and logs a warning if violated.

### Confirmation logic

```php
private function requiresConfirmation(WP_Ability $ability): bool
{
    $annotations = $ability->get_meta_item('annotations', []);
    return ($annotations['readonly'] ?? null) !== true;
}
```

Simple rule: `readonly: true` executes immediately. Everything else confirms first.

### Confirmation summary with resolved labels

The bridge calls ability-specific `describe_input` callbacks to resolve IDs to human-readable labels. Each ability can register a `describe_input` callable in its meta:

```php
// In AbilityRegistrar (stride-core)
wp_register_ability('stride/unenroll-user', [
    // ...
    'meta' => [
        'show_in_rest' => true,
        'annotations' => ['destructive' => true],
        'describe_input' => function(array $input): array {
            $user = get_userdata($input['user_id']);
            $edition = get_post($input['edition_id']);
            return [
                ['label' => 'Gebruiker', 'value' => $user->display_name],
                ['label' => 'Editie', 'value' => $edition->post_title],
                ['label' => 'Gevolgen', 'value' => 'LearnDash toegang wordt ingetrokken'],
            ];
        },
    ],
]);
```

The bridge builds the summary:

```php
private function buildConfirmationSummary(WP_Ability $ability, array $input): array
{
    $describer = $ability->get_meta_item('describe_input');
    $details = is_callable($describer) ? $describer($input) : $this->defaultDetails($input);

    return [
        'title'       => $ability->get_label(),
        'description' => $ability->get_description(),
        'details'     => $details,
    ];
}
```

If no `describe_input` is registered, falls back to raw key-value pairs from `$input`. **All v1 write abilities must implement `describe_input`** — showing raw IDs to admins defeats the purpose of the confirmation card. The fallback exists only for read abilities or future third-party abilities where raw keys are acceptable.

### Confirm token (HMAC)

When a write ability is paused, the bridge generates a token:

```php
$token = hash_hmac('sha256', json_encode([
    'ability' => $name,
    'input'   => $input,
    'user'    => get_current_user_id(),
    'time'    => time(),
]), wp_salt('auth'));
```

The pending action (ability, input, token, timestamp) is stored in `ConversationStore`. On `/confirm`, the token is re-verified and the stored action is used — never browser-supplied ability/input. Token expires with the conversation (1h).

### Execution with re-validation

`executeConfirmed()` re-checks permissions before executing:

```php
public function executeConfirmed(string $token): array
{
    $pending = $this->store->getPending(get_current_user_id());

    if (!$pending || !hash_equals($pending['token'], $token)) {
        return new WP_Error('invalid_token', 'Invalid or expired confirmation.');
    }

    $ability = wp_get_ability($pending['ability']);

    // Re-check permissions (prevents privilege escalation if role changed between confirm card and click)
    if (is_wp_error($ability->check_permissions($pending['input']))) {
        return new WP_Error('forbidden', 'Permission denied.');
    }

    // Fire hooks
    do_action('ntdst_assistant/before_execute', $pending['ability'], $pending['input'], get_current_user_id());

    $result = $ability->execute($pending['input']);

    do_action('ntdst_assistant/after_execute', $pending['ability'], $pending['input'], $result, get_current_user_id());

    $this->store->clearPending(get_current_user_id());

    return $result;
}
```

---

## ToolExecutor Detail

### Multi-tool handling

Claude can return multiple `tool_use` blocks in a single response. The executor processes them in order:
- **Read abilities** (`readonly: true`): execute immediately, collect results
- **First write ability**: stop processing, return confirmation for this one
- **Remaining tool calls after a write**: not processed — they'll be re-issued by Claude after the write is confirmed or cancelled

This means at most one confirmation card per response. The admin never sees two confirmation dialogs at once.

### Text-only response

If Claude responds with text and no tool calls on the first turn, the executor returns the text directly. No looping. This handles conversational responses like "Ik heb meer informatie nodig — welke editie bedoel je?"

### Max iterations exit

When the 10-iteration limit is reached:
1. Executor stops the loop
2. Returns `{type: "error", message: "Het verzoek was te complex..."}` to the transport
3. Conversation store retains the history — the admin can try a simpler question

### Timeout handling

- **Per-request timeout:** `ClaudeClientInterface` implementations use 60s timeout for each Claude API call. `HttpClaudeClient` passes `['timeout' => 60]` to `wp_remote_post()`.
- **Total timeout:** ToolExecutor tracks wall clock time. If 120s elapsed since the request started, the loop stops even if iterations remain.
- **PHP max_execution_time:** Bootstrap sets `set_time_limit(180)` for the chat/confirm endpoints only (not globally).

### Return type

ToolExecutor always returns one of:

```php
// Text response (conversation complete)
['type' => 'response', 'content' => 'Er zijn 12 studenten...']

// Confirmation needed
['type' => 'confirmation', 'confirm_token' => '...', 'summary' => [...]]

// Error
['type' => 'error', 'message' => 'Descriptive error in Dutch']
```

ChatController passes this to TransportInterface without modification.

---

## Extensibility Hooks

### 1. Tool definitions filter

```php
$tools = apply_filters('ntdst_assistant/tools', $tools);
```

Fired in AbilityBridge when building tool definitions for Claude. Another plugin can add, remove, or modify tools.

### 2. System prompt filter

```php
$prompt = apply_filters('ntdst_assistant/system_prompt', $prompt, $context);
```

Fired in SystemPrompt when building the prompt. `$context` contains:
- `user_id` — current admin user ID
- `locale` — site locale (e.g. `nl_BE`)
- `abilities` — list of available ability names

Stride injects domain rules. Another project injects its own.

### 3. Before/after execution actions

```php
do_action('ntdst_assistant/before_execute', $ability_name, $input, $admin_user_id);
do_action('ntdst_assistant/after_execute', $ability_name, $input, $result, $admin_user_id);
```

Fired in AbilityBridge around ability execution. `$admin_user_id` is `get_current_user_id()` — the admin who triggered the action, not the user being acted upon.

---

## v1 Abilities

### Read (execute immediately)

| Ability | Description | Annotation | Permission | Delegates to |
|---------|-------------|------------|------------|--------------|
| `stride/search-users` | Find users by name, email, or organisation | `readonly: true` | `stride_manage` or `stride_view` | `WP_User_Query` |
| `stride/get-editions` | List/search editions by title, date, status, course tag | `readonly: true` | `stride_manage` or `stride_view` | `EditionService` |
| `stride/get-edition` | Single edition with capacity, sessions, enrolled count | `readonly: true` | `stride_manage` or `stride_view` | `EditionService` + `SessionService` |
| `stride/get-enrollments` | Enrollments by edition or user with status | `readonly: true` | `stride_manage` or `stride_view` | `RegistrationRepository` |

### Write (confirmation required)

| Ability | Description | Annotation | Permission | Delegates to |
|---------|-------------|------------|------------|--------------|
| `stride/enroll-user` | Register user for edition, grant LMS access | `destructive: false, idempotent: false` | `stride_manage` | `EnrollmentService` → `LMSAdapter::grantAccess()` |
| `stride/unenroll-user` | Cancel registration, revoke LMS access | `destructive: true, idempotent: false` | `stride_manage` | `EnrollmentService` → `LMSAdapter::revokeAccess()` |

### Parked (later phases)

**Read:** `get-stats`, `get-sessions`, `get-attendance`, `get-quotes`
**Write:** `create-offering`, `add-sessions`, `update-edition`, `mark-attendance`, `update-quote`, `cancel-edition`

---

## System Prompt

### Base prompt (`ntdst-assistant/prompts/base.md`)

Generic assistant rules — project-agnostic:
- Always query before acting, never guess IDs
- If a name matches multiple records, ask the admin to clarify
- After every action, confirm what happened and show resulting state
- If an action cannot be performed, explain why and suggest alternatives
- Be concise — lead with the answer

### Domain injection (`stride-core/Modules/Assistant/prompts/domain.md`)

Stride-specific, injected via `ntdst_assistant/system_prompt` filter:
- Domain model (courses vs editions vs sessions vs registrations)
- Business rules (capacity, status transitions, side effects)
- Formatting (Dutch nl_BE, dates, money, status labels)
- Language rule: respond in Dutch unless the admin writes in English

Prompts stored as markdown files, loaded with `file_get_contents()`. Easy to read, edit, version.

---

## Frontend

### Admin page

Menu placement: submenu under the Stride admin menu (registered by `AdminDashboardService`). Page slug: `stride-assistant`. Capability for menu visibility: read from `ntdst_assistant_capability` option (default `edit_others_posts`).

### Alpine.js component (`assistant.js`)

```javascript
Alpine.data('ntdstAssistant', () => ({
    // State
    messages: [],           // Local display list: {id, type, content, data}
    input: '',
    loading: false,
    pending: null,          // {confirm_token, summary} — current confirmation

    // Actions
    send() {},              // POST /chat with {content: input}
    confirm() {},           // POST /confirm with {confirm_token}
    cancel() {},            // POST /cancel with {confirm_token}
}))
```

**UI state rules:**
- `send()` is disabled while `loading === true` or `pending !== null`
- Bevestigen/Annuleren buttons are disabled while `loading === true`
- Only one pending confirmation at a time (enforced by server and UI)

### Message types

| Type | Rendered as |
|------|------------|
| `user` | Right-aligned text bubble |
| `assistant` | Left-aligned bubble with HTML (server-rendered, sanitized markdown) |
| `confirmation` | Card with summary details + Annuleren / Bevestigen buttons |
| `error` | Red alert with error message |

### Conversation persistence

Messages are displayed locally in the Alpine component. The server holds the authoritative conversation log in `ConversationStore` (transient, 1h TTL). Page refresh clears the display — the admin starts a new conversation. This is intentional for v1.

### Markdown rendering

Server-side via Parsedown with `setMarkupEscaped(true)` to prevent raw HTML passthrough. Output further sanitized with `wp_kses_post()` before inclusion in the JSON response. Alpine renders with `x-html`. This double sanitization prevents XSS from Claude's output.

---

## Swappable Concerns

### Claude client

| Implementation | Used when | Dependency |
|---------------|-----------|------------|
| `SDKClaudeClient` | `WP_ENV !== 'production'` AND SDK class exists | `anthropic-php` SDK (composer require-dev) |
| `HttpClaudeClient` | `WP_ENV === 'production'` OR SDK class missing | None (wp_remote_post) |

The binding resolution checks `class_exists()` for the SDK client. If `composer install --no-dev` was run on a staging environment, it falls back to `HttpClaudeClient` automatically. No fatal error.

Both implement `ClaudeClientInterface`:

```php
interface ClaudeClientInterface
{
    /**
     * Send messages to Claude with tools.
     *
     * @param array  $messages     Conversation messages
     * @param array  $tools        Tool definitions (Claude format)
     * @param string $systemPrompt System prompt text
     * @return array Claude response with content blocks (text + tool_use)
     * @throws \RuntimeException On API error (401, 429, timeout, etc.)
     */
    public function send(array $messages, array $tools, string $systemPrompt): array;
}
```

Error handling: `send()` throws `\RuntimeException` with a descriptive message. ToolExecutor catches it and maps to the appropriate error response type (see error paths table).

### Transport

| Implementation | Behavior |
|---------------|----------|
| `JsonTransport` | Receives ToolExecutor result, renders markdown, returns single JSON via `wp_send_json()` |
| `SseTransport` | Streams events as SSE (future — stubbed in v1, throws "not implemented") |

Both implement `TransportInterface`:

```php
interface TransportInterface
{
    /**
     * Deliver the ToolExecutor result to the browser.
     *
     * @param array $result ToolExecutor return value: {type, content|confirm_token|message, ...}
     */
    public function deliver(array $result): void;
}
```

The ToolExecutor produces the complete result. The transport is a one-shot delivery mechanism — it receives the finished result and sends it. No incremental event collection in v1.

---

## Security

- **Access:** Gated by configurable capability (`ntdst_assistant_capability` option, default `edit_others_posts`). ChatController reads this at runtime — never hardcoded.
- **API key:** Stored in `wp_options` (encrypted at rest if host supports it), never sent to browser
- **Abilities:** Each ability has its own `permission_callback` — checked by WP Abilities API on `execute()`, AND re-checked on `/confirm`
- **Confirmation:** All non-readonly abilities require explicit admin confirmation via HMAC-signed token. Token is tied to ability + input + user + timestamp. Pending action stored server-side — browser never supplies ability name or input on confirm.
- **Message integrity:** Conversation history stored server-side in transients. Browser supplies only user input text and confirm tokens — never message history.
- **Input validation:** WP Abilities API validates input against JSON Schema before execution
- **Output validation:** WP Abilities API validates output against schema after execution
- **Markdown sanitization:** Parsedown with `setMarkupEscaped(true)` + `wp_kses_post()` on all Claude output
- **Nonce:** WordPress REST API nonce on all requests
- **Max iterations:** ToolExecutor limited to 10 loop iterations
- **Total timeout:** 120s wall clock limit on conversation loop
- **Per-request timeout:** 60s on Claude API calls

---

## Configuration

| WP Option | Default | Description |
|-----------|---------|-------------|
| `ntdst_assistant_api_key` | — | Claude API key (required) |
| `ntdst_assistant_model` | `claude-sonnet-4-6` | Model identifier |
| `ntdst_assistant_max_tokens` | `4096` | Max response tokens |
| `ntdst_assistant_capability` | `edit_others_posts` | Required WP capability for chat access |
| `ntdst_assistant_language` | `nl_BE` | Default response language (injected into system prompt context) |

### API key provisioning (v1)

No settings page in v1. API key is set via WP-CLI:

```bash
ddev exec wp option update ntdst_assistant_api_key "sk-ant-..." --autoload=no
```

Or via constant in `.env` / `wp-config.php`:

```php
define('NTDST_ASSISTANT_API_KEY', 'sk-ant-...');
```

The plugin checks the constant first, then the option. If neither is set, the chat page shows a notice explaining how to configure it.

---

## Dependencies

### ntdst-assistant plugin
- ntdst-core ≥ [version TBD at implementation] (required — graceful deactivation if missing or outdated)
- WordPress ≥ 6.9 (Abilities API)
- `erusev/parsedown` (composer — server-side markdown)
- `anthropic-ai/client-php` or similar (composer require-dev — dev/testing only, fallback to HTTP if missing)
- Alpine.js 3.x (loaded by plugin for admin page)

### stride-core addition
- New service: `AbilityRegistrar` in `Modules/Assistant/`
- New prompts: `Modules/Assistant/prompts/domain.md`, `formatting.md`
- No new dependencies — uses existing Stride services
