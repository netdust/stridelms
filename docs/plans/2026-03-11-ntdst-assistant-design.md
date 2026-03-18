# NTDST Assistant — Design Document

**Date:** 2026-03-11
**Status:** Approved
**Plugin:** `ntdst-assistant` (standalone WordPress plugin)

## Overview

An AI chat interface in the WordPress admin that acts as a "Stride colleague" for LMS administrators. Powered by Claude API (Sonnet 4.6) with WordPress Abilities as tools. Any NTDST project can register abilities — the assistant discovers and calls them server-side, while the official WP MCP adapter exposes the same abilities to external clients (Claude Desktop, Cursor, etc.) for free.

## Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| API calls | PHP backend | API key stays server-side, direct service access via DI |
| Model | Claude Sonnet 4.6 | Balance of speed and intelligence for admin chat |
| Confirmations | Always confirm actions | Safe — read tools show results, write tools show confirmation card |
| Persistence | Session only (in-memory) | MCP tools carry domain knowledge, short-term context is sufficient |
| Plugin type | Standalone plugin | Portable across NTDST projects, not Stride-specific |
| Tool system | WP Abilities API (hybrid) | Register once, consumed by assistant + MCP adapter |
| UI | Full admin page | Room for rich interactive elements (confirmation cards, data tables) |

## Design Philosophy: 80% of Admin Work, Zero Mistakes

The assistant should handle the admin's daily reality conversationally:

| Task | Frequency | Example |
|------|-----------|---------|
| Enrollment lookups | Daily | "Who's enrolled for next week's edition?" |
| Bulk enrollment | Weekly | "Enroll these 3 people in Excel April" |
| Unenrollment | Weekly | "Jan cancelled, remove him" |
| Create offerings | Monthly | "New Python training, May, 3 days, 12 seats" |
| Capacity checks | Daily | "How many seats left for Leadership?" |
| Quote management | Weekly | "Send the quote for Company X" |
| Attendance | Daily | "Mark attendance for today's session" |
| Status overview | Daily | "What's the status of the Brussels edition?" |

### Reliability Principles

1. **Always query before acting.** Claude never guesses a user ID, edition, or any reference. It looks up first, confirms the match with the admin, then acts. If "Jan" matches 3 users, it asks which one.

2. **Rich tool results.** Every tool returns full context — names, dates, statuses, counts — so Claude can give precise, complete answers. No vague "it's been done" responses.

3. **Confirmation cards show everything.** The admin sees exactly what will happen: which user, which edition, what side effects (LMS access revoked, quote cancelled, etc.). No surprises.

4. **Domain rules in the system prompt.** Claude knows: editions vs courses, valid status transitions, enrollment paths (individual vs company), pricing rules, capacity enforcement. It won't try to enroll someone in a full edition or cancel an already-completed registration.

5. **Graceful failures with explanation.** If something can't be done (edition full, user already enrolled, invalid status transition), Claude explains *why* clearly and suggests alternatives.

6. **Dutch first, precise formatting.** Dates in Dutch format (15 april 2026), money as € 450,00, status labels match what the admin sees in the dashboard. Consistent with the rest of the Stride UI.

7. **Clean handoff.** After every action, Claude confirms what happened and shows the resulting state. The admin can immediately see the effect without navigating away.

## Architecture

```
┌─────────────────────────────────────────────┐
│  WP Admin — Chat Page (Alpine.js)           │
│  ┌────────────────────────────────────────┐  │
│  │ Chat messages + confirmation cards     │  │
│  └──────────────┬─────────────────────────┘  │
│                 │ SSE stream                  │
│                 ▼                             │
│  ┌──────────────────────────────┐            │
│  │ ntdst-assistant plugin       │            │
│  │  ChatController (REST API)   │            │
│  │  ClaudeClient (Sonnet 4.6)   │            │
│  │  AbilityBridge               │◄───────┐   │
│  └──────────────────────────────┘        │   │
│                 │                         │   │
│                 ▼                         │   │
│  ┌──────────────────────────────┐        │   │
│  │ WordPress Abilities API      │        │   │
│  │  stride/get-editions         │        │   │
│  │  stride/get-enrollments      │        │   │
│  │  stride/create-offering      │        │   │
│  │  stride/unenroll-user        │        │   │
│  │  ...                         │        │   │
│  └──────────────────────────────┘        │   │
│                 ▲                         │   │
│                 │ registers               │   │
│  ┌──────────────────────────────┐        │   │
│  │ stride-core                  │        │   │
│  │  AbilityRegistrar service    │────────┘   │
│  └──────────────────────────────┘            │
│                                              │
│  ┌──────────────────────────────┐            │
│  │ WordPress/mcp-adapter        │ (optional) │
│  │  Exposes same abilities to   │            │
│  │  Claude Desktop, Cursor, etc │            │
│  └──────────────────────────────┘            │
└─────────────────────────────────────────────┘
```

## Plugin Structure

```
web/app/plugins/ntdst-assistant/
├── ntdst-assistant.php                # Bootstrap
├── plugin-config.php                  # Service registration
├── composer.json                      # anthropic-sdk-php dependency
├── src/
│   ├── AssistantService.php           # Admin page registration, asset loading
│   ├── ChatController.php            # REST: /ntdst-assistant/v1/chat
│   ├── ClaudeClient.php              # Claude API wrapper (streaming, tool loop)
│   ├── AbilityBridge.php             # WP Abilities → Claude tool definitions
│   ├── ToolExecutor.php              # Executes abilities, handles confirmation flow
│   └── Domain/
│       └── ChatMessage.php           # Message value object
├── assets/
│   ├── css/assistant.css              # Chat UI styles
│   └── js/assistant.js               # Alpine.js chat application
└── templates/
    └── admin/chat.php                 # Chat page HTML template
```

## Conversation Flow

### Read Query (no confirmation)

```
Admin: "How many students enrolled for Excel Basics?"

→ Claude selects tool: stride/get-enrollments {search: "Excel Basics"}
→ AbilityBridge: read type → execute immediately
→ Result: {count: 12, capacity: 15, edition: "Excel Basics — March 2026"}
→ Claude: "Er zijn 12 studenten ingeschreven voor Excel Basics (maart editie).
   Er zijn nog 3 plaatsen beschikbaar van de 15."
```

### Write Action (confirmation required)

```
Admin: "Schrijf Jan Peeters uit voor die editie"

→ Claude selects tool: stride/unenroll-user {user_id: 42, edition_id: 108}
→ AbilityBridge: write type → return pending_confirmation
→ UI renders confirmation card:

  ┌─────────────────────────────────────────┐
  │ ⚠ Gebruiker uitschrijven                │
  │                                         │
  │ Gebruiker: Jan Peeters                  │
  │ Editie:    Excel Basics (maart 2026)    │
  │                                         │
  │ Dit zal:                                │
  │ • Inschrijving annuleren                │
  │ • LearnDash toegang intrekken           │
  │                                         │
  │ [Annuleren]  [Bevestigen]               │
  └─────────────────────────────────────────┘

→ Admin clicks Bevestigen
→ AbilityBridge executes stride/unenroll-user
→ Claude: "Gedaan. Jan Peeters is uitgeschreven voor Excel Basics.
   LearnDash toegang is ingetrokken. Er zijn nu 4 plaatsen beschikbaar."
```

### Workflow Action (create full offering)

```
Admin: "Maak een nieuwe Excel training, 3 dagen, 15-17 april,
        10 plaatsen, €450 per persoon, in ons kantoor Brussel"

→ Claude selects tool: stride/create-offering {
    course_title: "Excel Training",
    edition_label: "April 2026 — Brussel",
    sessions: [
      {date: "2026-04-15", start: "09:00", end: "17:00"},
      {date: "2026-04-16", start: "09:00", end: "17:00"},
      {date: "2026-04-17", start: "09:00", end: "17:00"},
    ],
    capacity: 10,
    price_cents: 45000,
    venue: "Kantoor Brussel",
    create_course: true,
  }
→ Confirmation card:

  ┌─────────────────────────────────────────┐
  │ 📋 Cursusaanbod aanmaken                │
  │                                         │
  │ Cursus:    Excel Training (nieuw)       │
  │ Editie:    April 2026 — Brussel         │
  │ Sessies:   15 apr, 16 apr, 17 apr       │
  │            09:00–17:00                   │
  │ Capaciteit: 10 plaatsen                 │
  │ Prijs:     € 450,00                     │
  │ Locatie:   Kantoor Brussel              │
  │                                         │
  │ Dit zal:                                │
  │ • LearnDash cursus aanmaken             │
  │ • Editie aanmaken met 3 sessies         │
  │ • Prijs en capaciteit instellen         │
  │                                         │
  │ [Annuleren]  [Bevestigen]               │
  └─────────────────────────────────────────┘

→ Admin confirms
→ Chains: LearnDashService → EditionService → SessionService
→ Claude: "Aangemaakt! Excel Training is beschikbaar:
   • Cursus: Excel Training (ID: 523)
   • Editie: April 2026 — Brussel (ID: 891)
   • 3 sessies gepland (15-17 april)
   • 10 plaatsen à € 450,00"
```

## Components

### ClaudeClient

```php
class ClaudeClient
{
    private const MODEL = 'claude-sonnet-4-6';

    public function __construct(
        private readonly string $apiKey,
    ) {}

    /**
     * Send messages to Claude with tools, streaming response via SSE.
     *
     * @return \Generator yields SSE events
     */
    public function chat(array $messages, array $tools): \Generator
    {
        $response = $this->client->messages->create([
            'model'      => self::MODEL,
            'max_tokens' => 4096,
            'system'     => $this->buildSystemPrompt(),
            'tools'      => $tools,
            'messages'   => $messages,
            'stream'     => true,
        ]);

        foreach ($response as $event) {
            yield $event;
        }
    }

    private function buildSystemPrompt(): string
    {
        $lang = get_option('ntdst_assistant_language', 'nl_BE');

        // Domain context is critical — this is what makes the assistant
        // reliable. It must know business rules to avoid mistakes.
        return <<<PROMPT
        You are the Stride Assistant, an AI colleague for LMS administrators.
        You help manage courses, editions, enrollments, attendance, and users.

        ## Domain Model
        - Course (sfwd-courses): LearnDash content only (lessons, quizzes).
        - Edition (vad_edition): A scheduled offering of a course (dates, price, venue, capacity).
        - Session (vad_session): Individual meeting days within an edition.
        - Registration: User enrollment in an edition (not a course directly).
        - Users enroll in EDITIONS, not courses. Editions belong to courses.

        ## Business Rules
        - An edition has a capacity. Never enroll beyond capacity.
        - Registration statuses: pending → confirmed → completed | cancelled.
        - A cancelled registration cannot be re-confirmed — create a new one.
        - Unenrolling revokes LearnDash access. Always mention this side effect.
        - Quotes follow registrations. Cancelling a registration should prompt about the quote.
        - Creating an offering = LearnDash course + edition + sessions + pricing. All required.
        - Attendance is per-session, not per-edition. Mark each session day individually.

        ## Reliability Rules
        - ALWAYS query before acting. Never guess a user, edition, or ID. Look it up first.
        - If a name matches multiple records, ask the admin to clarify. Never assume.
        - After every action, confirm what happened and show the resulting state.
        - If an action cannot be performed, explain WHY and suggest alternatives.
        - Never say "done" without verifying the operation succeeded.

        ## Formatting
        - Respond in Dutch (nl_BE) unless the admin writes in English.
        - Dates: 15 april 2026 (Dutch, lowercase month).
        - Money: € 450,00 (euro sign, comma for decimals).
        - Use the same status labels the admin sees in the dashboard.
        - Be concise. Lead with the answer, then details if needed.
        PROMPT;
    }
}
```

### AbilityBridge

```php
/**
 * Bridges WordPress Abilities API to Claude tool definitions.
 * Discovers registered abilities and converts them to Claude's tool format.
 * Handles execution with confirmation flow for write operations.
 */
class AbilityBridge
{
    /**
     * Convert all assistant-exposed abilities to Claude tool definitions.
     */
    public function getToolDefinitions(): array
    {
        $abilities = wp_get_abilities();
        $tools = [];

        foreach ($abilities as $name => $ability) {
            if (!$this->isExposedToAssistant($ability)) {
                continue;
            }

            $tools[] = [
                'name'         => str_replace('/', '_', $name),
                'description'  => $ability['description'],
                'input_schema' => $ability['input_schema'],
            ];
        }

        return $tools;
    }

    /**
     * Execute an ability. Returns pending_confirmation for write operations,
     * or the result directly for read operations.
     */
    public function execute(string $name, array $input): array
    {
        $ability = wp_get_ability($name);

        // Permission check
        if (!call_user_func($ability['permission_callback'])) {
            return ['error' => 'Permission denied'];
        }

        // Write operations need confirmation
        if ($this->requiresConfirmation($ability)) {
            return [
                'status'  => 'pending_confirmation',
                'ability' => $name,
                'input'   => $input,
                'summary' => $this->buildConfirmationSummary($ability, $input),
            ];
        }

        // Read operations execute immediately
        return call_user_func($ability['execute_callback'], $input);
    }

    /**
     * Execute a previously confirmed write ability.
     */
    public function executeConfirmed(string $name, array $input): array
    {
        $ability = wp_get_ability($name);
        return call_user_func($ability['execute_callback'], $input);
    }

    private function isExposedToAssistant(array $ability): bool
    {
        return !empty($ability['meta']['mcp']['public']);
    }

    private function requiresConfirmation(array $ability): bool
    {
        return ($ability['meta']['assistant']['confirm'] ?? false) === true;
    }
}
```

### ChatController (REST API)

```php
/**
 * REST endpoint for the chat interface.
 * Handles message exchange and SSE streaming.
 */
class ChatController
{
    public function registerRoutes(): void
    {
        register_rest_route('ntdst-assistant/v1', '/chat', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleChat'],
            'permission_callback' => fn() => current_user_can('edit_others_posts'),
        ]);

        register_rest_route('ntdst-assistant/v1', '/confirm', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleConfirm'],
            'permission_callback' => fn() => current_user_can('edit_others_posts'),
        ]);
    }

    /**
     * Main chat endpoint. Receives messages, calls Claude with tools,
     * streams response back via SSE.
     */
    public function handleChat(\WP_REST_Request $request): void
    {
        $messages = $request->get_param('messages');
        $tools = $this->bridge->getToolDefinitions();

        // Set SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');

        foreach ($this->claude->chat($messages, $tools) as $event) {
            if ($event->type === 'tool_use') {
                $result = $this->bridge->execute($event->name, $event->input);

                if ($result['status'] === 'pending_confirmation') {
                    // Send confirmation card to UI
                    echo "event: confirmation\n";
                    echo "data: " . json_encode($result) . "\n\n";
                    flush();
                    return; // Pause until admin confirms
                }

                // Feed tool result back to Claude
                // (continues the streaming loop)
            }

            // Stream text deltas to UI
            if ($event->type === 'content_block_delta') {
                echo "event: delta\n";
                echo "data: " . json_encode(['text' => $event->delta->text]) . "\n\n";
                flush();
            }
        }

        echo "event: done\n";
        echo "data: {}\n\n";
    }

    /**
     * Confirmation endpoint. Admin confirmed a write action.
     * Executes the ability, feeds result to Claude for final response.
     */
    public function handleConfirm(\WP_REST_Request $request): void
    {
        $ability = $request->get_param('ability');
        $input = $request->get_param('input');
        $messages = $request->get_param('messages');

        $result = $this->bridge->executeConfirmed($ability, $input);

        // Feed result back to Claude for a natural language summary
        // Stream the response
    }
}
```

## Ability Registration (Stride Side)

New service in `stride-core/Modules/Assistant/AbilityRegistrar.php`:

```php
class AbilityRegistrar implements \NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return [
            'name' => 'Ability Registrar',
            'description' => 'Registers Stride abilities for AI assistant and MCP',
            'priority' => 20,
        ];
    }

    public function __construct()
    {
        add_action('init', [$this, 'register']);
    }

    public function register(): void
    {
        $this->registerReadAbilities();
        $this->registerWriteAbilities();
    }
}
```

### Phase 1 Abilities

#### Read Abilities (no confirmation)

| Ability | Description | Services Used |
|---------|-------------|---------------|
| `stride/get-editions` | List/search editions with filters | EditionService, EditionRepository |
| `stride/get-edition` | Single edition with full details | EditionService, SessionService |
| `stride/get-enrollments` | Enrollments by edition or user | RegistrationRepository |
| `stride/get-user-info` | User details + enrollment history | WP_User, RegistrationRepository |
| `stride/get-stats` | Dashboard statistics | AdminDashboardService |
| `stride/search-users` | Find users by name/email/org | WP_User_Query |
| `stride/get-sessions` | Sessions for an edition | SessionRepository |
| `stride/get-attendance` | Attendance records | AttendanceService |
| `stride/get-quotes` | Quotes by edition/user/status | QuoteService |

#### Write Abilities (confirmation required)

| Ability | Description | Services Orchestrated |
|---------|-------------|----------------------|
| `stride/create-offering` | Full course offering: LD course + edition + sessions + pricing | LearnDashService → EditionService → SessionService |
| `stride/add-sessions` | Add sessions to existing edition | SessionService |
| `stride/update-edition` | Modify edition details (price, capacity, venue, dates) | EditionService |
| `stride/enroll-user` | Register user for edition + grant LMS access | EnrollmentService → LearnDashService |
| `stride/unenroll-user` | Cancel registration + revoke LMS access | EnrollmentService → LearnDashService |
| `stride/mark-attendance` | Mark user present/absent/excused for session | AttendanceService |
| `stride/update-quote` | Change quote status (send, cancel) | QuoteService |
| `stride/cancel-edition` | Cancel edition + notify enrolled users | EditionService → EnrollmentService |

## Chat UI

### Template Structure (`templates/admin/chat.php`)

```html
<div x-data="ntdstAssistant()" class="ntdst-assistant">

    <!-- Message list -->
    <div class="assistant-messages" x-ref="messages">
        <template x-for="msg in messages" :key="msg.id">

            <!-- Text message (admin or AI) -->
            <div x-show="msg.type === 'text'"
                 :class="msg.role === 'user' ? 'msg-user' : 'msg-assistant'">
                <div x-html="msg.content"></div>
            </div>

            <!-- Confirmation card -->
            <div x-show="msg.type === 'confirmation'" class="msg-confirmation">
                <div class="confirmation-card">
                    <h4 x-text="msg.title"></h4>
                    <div x-html="msg.summary"></div>
                    <div class="confirmation-actions">
                        <button @click="cancelAction(msg)" class="btn-cancel">
                            Annuleren
                        </button>
                        <button @click="confirmAction(msg)" class="btn-confirm">
                            Bevestigen
                        </button>
                    </div>
                </div>
            </div>

            <!-- Data table (inline results) -->
            <div x-show="msg.type === 'table'" class="msg-table">
                <!-- Rendered enrollment lists, edition details, etc. -->
            </div>

        </template>

        <!-- Typing indicator -->
        <div x-show="isStreaming" class="msg-typing">
            <span class="typing-dot"></span>
            <span class="typing-dot"></span>
            <span class="typing-dot"></span>
        </div>
    </div>

    <!-- Input area -->
    <div class="assistant-input">
        <textarea x-model="input"
                  @keydown.enter.prevent="send()"
                  placeholder="Stel een vraag of geef een opdracht..."
                  :disabled="isStreaming">
        </textarea>
        <button @click="send()" :disabled="isStreaming || !input.trim()">
            Verstuur
        </button>
    </div>
</div>
```

### Alpine.js App (`assets/js/assistant.js`)

Core state and methods:
- `messages[]` — conversation history (text, confirmation cards, data tables)
- `input` — current input field
- `isStreaming` — loading state
- `pendingConfirmation` — current confirmation waiting for admin action
- `send()` — post to `/ntdst-assistant/v1/chat`, consume SSE stream
- `confirmAction(msg)` — post to `/ntdst-assistant/v1/confirm`
- `cancelAction(msg)` — dismiss confirmation, inform Claude of cancellation

## Configuration

### WP Options

| Option | Default | Description |
|--------|---------|-------------|
| `ntdst_assistant_api_key` | — | Claude API key (required) |
| `ntdst_assistant_model` | `claude-sonnet-4-6` | Model override |
| `ntdst_assistant_language` | `nl_BE` | Default response language |
| `ntdst_assistant_capability` | `edit_others_posts` | Required WP capability |

### Settings Page

Simple settings page under the assistant menu item:
- API key input (masked)
- Model selector dropdown
- Language selector
- Test connection button

## Security

- **Access:** Admin-only, gated by WP capability (`edit_others_posts`)
- **API key:** Stored server-side in `wp_options`, never sent to browser
- **Abilities:** Each ability has its own `permission_callback` — double-checked on execution
- **Confirmations:** All write operations require explicit admin confirmation
- **Audit:** Write actions logged via `ntdst-audit` plugin (already integrated)
- **Input:** All ability inputs validated against JSON Schema before execution
- **Rate limiting:** Optional, configurable per-admin request limit

## Dependencies

### ntdst-assistant plugin
- `anthropic/anthropic-php` — Official PHP SDK for Claude API
- WordPress 6.9+ (Abilities API)
- Alpine.js (loaded by WP or included)

### stride-core addition
- New service: `AbilityRegistrar` in `Modules/Assistant/`
- No new dependencies — uses existing services

## Future Enhancements (not in scope)

- **Conversation persistence** — Store threads in custom table, history sidebar
- **Multi-admin awareness** — "Jan just enrolled 5 users in Excel Basics"
- **Proactive notifications** — "Edition X is almost full (9/10 seats)"
- **Document upload** — Admin uploads syllabus, AI proposes course structure
- **Bulk operations** — "Enroll all users from Company X in Leadership Q2"
- **Sidebar widget** — Floating chat accessible from any admin page
- **Voice input** — Whisper API for spoken commands
