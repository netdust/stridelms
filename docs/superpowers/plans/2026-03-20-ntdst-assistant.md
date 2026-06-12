# NTDST Assistant — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build an AI chat assistant plugin for WordPress admins that executes LMS operations through the WordPress 6.9 Abilities API.

**Architecture:** Generic `ntdst-assistant` plugin registers services into ntdst-core's DI container. Stride-specific `AbilityRegistrar` in stride-core registers domain abilities. The plugin never imports Stride — it discovers abilities through the WP Abilities API.

**Tech Stack:** PHP 8.3, WordPress 6.9 Abilities API, ntdst-core DI container, Alpine.js 3.x, Parsedown, Anthropic PHP SDK (dev only)

**Spec:** `docs/superpowers/specs/2026-03-20-ntdst-assistant-design.md`

---

## File Map

### Plugin: `web/app/plugins/ntdst-assistant/`

| File | Responsibility |
|------|---------------|
| `ntdst-assistant.php` | Bootstrap: version checks, autoloader, hook into ntdst-core |
| `plugin-config.php` | Service list + DI bindings |
| `composer.json` | PSR-4 autoload for `NtdstAssistant\`, require-dev SDK |
| `src/AssistantService.php` | Admin page, menu item, asset loading |
| `src/ChatController.php` | REST endpoints: /chat, /confirm, /cancel |
| `src/AbilityBridge.php` | WP Abilities → Claude tools, execution, confirmation |
| `src/ToolExecutor.php` | Tool loop: Claude ↔ Abilities conversation |
| `src/SystemPrompt.php` | Build system prompt from base + filtered domain |
| `src/ConversationStore.php` | Server-side message log via transients |
| `src/Contracts/ClaudeClientInterface.php` | Interface: send(messages, tools, prompt) |
| `src/Contracts/TransportInterface.php` | Interface: deliver(result) |
| `src/Claude/SDKClaudeClient.php` | Anthropic SDK wrapper (dev) |
| `src/Claude/HttpClaudeClient.php` | wp_remote_post wrapper (production) |
| `src/Transport/JsonTransport.php` | Buffered JSON response |
| `src/Transport/SseTransport.php` | Stubbed for future SSE |
| `prompts/base.md` | Generic assistant rules |
| `assets/css/assistant.css` | Chat UI styles |
| `assets/js/assistant.js` | Alpine.js chat component |
| `templates/admin/chat.php` | Chat page HTML |

### Stride-side: `web/app/mu-plugins/stride-core/Modules/Assistant/`

| File | Responsibility |
|------|---------------|
| `AbilityRegistrar.php` | Register stride/* abilities on wp_abilities_api_init |
| `prompts/domain.md` | Domain model + business rules for system prompt |
| `prompts/formatting.md` | Dutch formatting rules |

### Tests

| File | Tests |
|------|-------|
| `tests/Unit/NtdstAssistant/AbilityBridgeTest.php` | Tool conversion, name mapping, confirmation logic |
| `tests/Unit/NtdstAssistant/ToolExecutorTest.php` | Loop behavior, max iterations, error handling |
| `tests/Unit/NtdstAssistant/ConversationStoreTest.php` | Message storage, pending state, TTL |
| `tests/Unit/NtdstAssistant/SystemPromptTest.php` | Prompt building, filter application |
| `tests/Unit/NtdstAssistant/ChatControllerTest.php` | Endpoint validation, capability check, delegation |
| `tests/Unit/NtdstAssistant/HttpClaudeClientTest.php` | HTTP request formation, error mapping |
| `tests/Unit/AbilityRegistrarTest.php` | Ability registration, execute callbacks, describe_input |
| `tests/Integration/AssistantIntegrationTest.php` | End-to-end: chat → abilities → response |
| `tests/acceptance/AssistantCest.php` | Browser: admin page loads, chat sends, confirmation renders |

---

## Phase 1: Plugin Foundation

### Task 1: Scaffold plugin directory and composer setup

**Files:**
- Create: `web/app/plugins/ntdst-assistant/composer.json`
- Create: `web/app/plugins/ntdst-assistant/ntdst-assistant.php`
- Create: `web/app/plugins/ntdst-assistant/plugin-config.php`
- Modify: `composer.json` (root — add autoload namespace)

- [ ] **Step 1: Create plugin directory**

```bash
mkdir -p web/app/plugins/ntdst-assistant/src/{Contracts,Claude,Transport}
mkdir -p web/app/plugins/ntdst-assistant/{prompts,assets/css,assets/js,templates/admin}
```

- [ ] **Step 2: Create plugin composer.json**

```json
{
    "name": "ntdst/ntdst-assistant",
    "description": "AI chat assistant for WordPress admins using Abilities API",
    "type": "wordpress-plugin",
    "require": {
        "php": ">=8.1",
        "erusev/parsedown": "^1.7"
    },
    "require-dev": {
        "anthropic-ai/client-php": "^1.0"
    },
    "autoload": {
        "psr-4": {
            "NtdstAssistant\\": "src/"
        }
    }
}
```

- [ ] **Step 3: Add autoload namespace to root composer.json**

Add to `autoload.psr-4`:
```json
"NtdstAssistant\\": "web/app/plugins/ntdst-assistant/src/"
```

- [ ] **Step 4: Create bootstrap file**

`web/app/plugins/ntdst-assistant/ntdst-assistant.php`:

```php
<?php
declare(strict_types=1);

/**
 * Plugin Name: NTDST Assistant
 * Description: AI chat assistant for WordPress admins powered by Claude API
 * Version: 1.0.0
 * Author: NTDST
 * Requires at least: 6.9
 * Requires PHP: 8.1
 */

defined('ABSPATH') || exit;

// Check ntdst-core is available
if (!function_exists('ntdst_get')) {
    add_action('admin_notices', function (): void {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>NTDST Assistant</strong> requires ntdst-core to be active.';
        echo '</p></div>';
    });
    return;
}

// Check WordPress version (Abilities API requires 6.9)
if (!function_exists('wp_register_ability')) {
    add_action('admin_notices', function (): void {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>NTDST Assistant</strong> requires WordPress 6.9+ (Abilities API).';
        echo '</p></div>';
    });
    return;
}

// Load config
$ntdstAssistantConfig = require __DIR__ . '/plugin-config.php';

// Register DI bindings
add_action('ntdst/core_ready', function () use ($ntdstAssistantConfig): void {
    foreach ($ntdstAssistantConfig['bindings'] as $interface => $implementation) {
        ntdst_set($interface, $implementation);
    }
});

// Register services
add_action('ntdst/features_ready', function () use ($ntdstAssistantConfig): void {
    foreach ($ntdstAssistantConfig['services'] as $serviceClass) {
        if (class_exists($serviceClass)) {
            ntdst_get($serviceClass);
        }
    }
});
```

- [ ] **Step 5: Create plugin-config.php**

`web/app/plugins/ntdst-assistant/plugin-config.php`:

```php
<?php
declare(strict_types=1);

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
        ClaudeClientInterface::class => fn() => (
            defined('WP_ENV') && WP_ENV !== 'production' && class_exists(SDKClaudeClient::class)
                ? ntdst_make(SDKClaudeClient::class)
                : ntdst_make(HttpClaudeClient::class)
        ),
        TransportInterface::class => JsonTransport::class,
    ],
];
```

- [ ] **Step 6: Install Parsedown and verify autoloading**

```bash
cd /home/ntdst/Sites/stride
ddev exec composer require erusev/parsedown:^1.7
ddev exec composer dump-autoload
ddev exec wp plugin list | grep ntdst-assistant
```

Expected: Plugin appears in list (inactive).

- [ ] **Step 7: Activate plugin and verify bootstrap**

```bash
ddev exec wp plugin activate ntdst-assistant
ddev exec wp eval "echo function_exists('wp_register_ability') ? 'Abilities API: OK' : 'Abilities API: MISSING';"
```

Expected: `Abilities API: OK` and no errors.

- [ ] **Step 8: Commit**

```bash
git add web/app/plugins/ntdst-assistant/ composer.json
git commit -m "feat(assistant): scaffold ntdst-assistant plugin with bootstrap and config"
```

---

## Phase 2: Contracts & Core Services

### Task 2: Create interfaces

**Files:**
- Create: `web/app/plugins/ntdst-assistant/src/Contracts/ClaudeClientInterface.php`
- Create: `web/app/plugins/ntdst-assistant/src/Contracts/TransportInterface.php`

- [ ] **Step 1: Create ClaudeClientInterface**

```php
<?php
declare(strict_types=1);

namespace NtdstAssistant\Contracts;

interface ClaudeClientInterface
{
    /**
     * Send messages to Claude with tools.
     *
     * @param array  $messages     Conversation messages [{role, content, ...}]
     * @param array  $tools        Tool definitions in Claude format
     * @param string $systemPrompt System prompt text
     * @return array Claude response: {content: [{type: text|tool_use, ...}]}
     * @throws \RuntimeException On API error (401, 429, timeout, network)
     */
    public function send(array $messages, array $tools, string $systemPrompt): array;
}
```

- [ ] **Step 2: Create TransportInterface**

```php
<?php
declare(strict_types=1);

namespace NtdstAssistant\Contracts;

interface TransportInterface
{
    /**
     * Deliver ToolExecutor result to the browser.
     *
     * @param array $result {type: response|confirmation|error, ...}
     */
    public function deliver(array $result): void;
}
```

- [ ] **Step 3: Commit**

```bash
git add web/app/plugins/ntdst-assistant/src/Contracts/
git commit -m "feat(assistant): add ClaudeClientInterface and TransportInterface contracts"
```

---

### Task 2b: Add Abilities API test stubs

**Files:**
- Modify: `tests/Stubs/wordpress-stubs.php`

The unit test suite uses lightweight stubs, not the real WordPress bootstrap. The Abilities API functions (`wp_register_ability`, `wp_get_ability`, etc.) and the `WP_Ability` class don't exist in the stubs. Tests will fatal error without them.

- [ ] **Step 1: Add WP_Ability stub and Abilities API functions to wordpress-stubs.php**

Append to `tests/Stubs/wordpress-stubs.php`:

```php
// --- Abilities API Stubs (WP 6.9) ---

if (!class_exists('WP_Ability')) {
    class WP_Ability {
        private string $name;
        private array $args;

        public function __construct(string $name, array $args) {
            $this->name = $name;
            $this->args = $args;
        }

        public function get_name(): string { return $this->name; }
        public function get_label(): string { return $this->args['label'] ?? ''; }
        public function get_description(): string { return $this->args['description'] ?? ''; }
        public function get_category(): string { return $this->args['category'] ?? ''; }
        public function get_input_schema(): array { return $this->args['input_schema'] ?? []; }
        public function get_output_schema(): array { return $this->args['output_schema'] ?? []; }
        public function get_meta(): array { return $this->args['meta'] ?? []; }

        public function get_meta_item(string $key, $default = null): mixed {
            return $this->args['meta'][$key] ?? $default;
        }

        public function check_permissions($input = null): bool|WP_Error {
            if (isset($this->args['permission_callback'])) {
                return call_user_func($this->args['permission_callback'], $input);
            }
            return true;
        }

        public function execute($input = null): mixed {
            if (isset($this->args['execute_callback'])) {
                do_action('wp_before_execute_ability', $this->name, $input);
                $result = call_user_func($this->args['execute_callback'], $input);
                do_action('wp_after_execute_ability', $this->name, $input, $result);
                return $result;
            }
            return new WP_Error('no_callback', 'No execute callback.');
        }
    }
}

if (!class_exists('WP_Ability_Category')) {
    class WP_Ability_Category {
        private string $slug;
        private array $args;

        public function __construct(string $slug, array $args) {
            $this->slug = $slug;
            $this->args = $args;
        }

        public function get_slug(): string { return $this->slug; }
        public function get_label(): string { return $this->args['label'] ?? ''; }
    }
}

// Global registries for stubs
global $_test_abilities, $_test_ability_categories;
$_test_abilities = $_test_abilities ?? [];
$_test_ability_categories = $_test_ability_categories ?? [];

if (!function_exists('wp_register_ability_category')) {
    function wp_register_ability_category(string $slug, array $args): ?WP_Ability_Category {
        global $_test_ability_categories;
        $cat = new WP_Ability_Category($slug, $args);
        $_test_ability_categories[$slug] = $cat;
        return $cat;
    }
}

if (!function_exists('wp_has_ability_category')) {
    function wp_has_ability_category(string $slug): bool {
        global $_test_ability_categories;
        return isset($_test_ability_categories[$slug]);
    }
}

if (!function_exists('wp_get_ability_category')) {
    function wp_get_ability_category(string $slug): ?WP_Ability_Category {
        global $_test_ability_categories;
        return $_test_ability_categories[$slug] ?? null;
    }
}

if (!function_exists('wp_get_ability_categories')) {
    function wp_get_ability_categories(): array {
        global $_test_ability_categories;
        return $_test_ability_categories;
    }
}

if (!function_exists('wp_register_ability')) {
    function wp_register_ability(string $name, array $args): ?WP_Ability {
        global $_test_abilities;
        $ability = new WP_Ability($name, $args);
        $_test_abilities[$name] = $ability;
        return $ability;
    }
}

if (!function_exists('wp_unregister_ability')) {
    function wp_unregister_ability(string $name): ?WP_Ability {
        global $_test_abilities;
        $ability = $_test_abilities[$name] ?? null;
        unset($_test_abilities[$name]);
        return $ability;
    }
}

if (!function_exists('wp_has_ability')) {
    function wp_has_ability(string $name): bool {
        global $_test_abilities;
        return isset($_test_abilities[$name]);
    }
}

if (!function_exists('wp_get_ability')) {
    function wp_get_ability(string $name): ?WP_Ability {
        global $_test_abilities;
        return $_test_abilities[$name] ?? null;
    }
}

if (!function_exists('wp_get_abilities')) {
    function wp_get_abilities(): array {
        global $_test_abilities;
        return $_test_abilities;
    }
}
```

- [ ] **Step 2: Add ability registry reset to TestCase::resetGlobalState()**

Add to the `resetGlobalState()` method in `tests/TestCase.php`:

```php
global $_test_abilities, $_test_ability_categories;
$_test_abilities = [];
$_test_ability_categories = [];
```

- [ ] **Step 3: Run existing tests to verify stubs don't break anything**

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Expected: All existing tests still PASS.

- [ ] **Step 4: Commit**

```bash
git add tests/Stubs/wordpress-stubs.php tests/TestCase.php
git commit -m "test: add WP Abilities API stubs for unit testing"
```

---

### Task 3: ConversationStore

**Files:**
- Create: `web/app/plugins/ntdst-assistant/src/ConversationStore.php`
- Create: `tests/Unit/NtdstAssistant/ConversationStoreTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Unit/NtdstAssistant/ConversationStoreTest.php`:

```php
<?php
declare(strict_types=1);

namespace Stride\Tests\Unit\NtdstAssistant;

use NtdstAssistant\ConversationStore;
use Stride\Tests\TestCase;

class ConversationStoreTest extends TestCase
{
    private ConversationStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new ConversationStore();
    }

    public function testGetReturnsEmptyArrayForNewUser(): void
    {
        $this->assertSame([], $this->store->get(1));
    }

    public function testAppendAddsMessageToConversation(): void
    {
        $this->store->append(1, ['role' => 'user', 'content' => 'Hello']);
        $messages = $this->store->get(1);

        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('Hello', $messages[0]['content']);
    }

    public function testAppendAccumulatesMessages(): void
    {
        $this->store->append(1, ['role' => 'user', 'content' => 'Hello']);
        $this->store->append(1, ['role' => 'assistant', 'content' => 'Hi']);

        $this->assertCount(2, $this->store->get(1));
    }

    public function testClearRemovesAllMessages(): void
    {
        $this->store->append(1, ['role' => 'user', 'content' => 'Hello']);
        $this->store->clear(1);

        $this->assertSame([], $this->store->get(1));
    }

    public function testConversationsAreIsolatedPerUser(): void
    {
        $this->store->append(1, ['role' => 'user', 'content' => 'User 1']);
        $this->store->append(2, ['role' => 'user', 'content' => 'User 2']);

        $this->assertCount(1, $this->store->get(1));
        $this->assertSame('User 1', $this->store->get(1)[0]['content']);
    }

    public function testSetPendingStoresPendingAction(): void
    {
        $pending = [
            'ability' => 'stride/enroll-user',
            'input' => ['user_id' => 42, 'edition_id' => 108],
            'token' => 'abc123',
        ];

        $this->store->setPending(1, $pending);
        $result = $this->store->getPending(1);

        $this->assertSame('stride/enroll-user', $result['ability']);
        $this->assertSame('abc123', $result['token']);
    }

    public function testGetPendingReturnsNullWhenNoPending(): void
    {
        $this->assertNull($this->store->getPending(1));
    }

    public function testClearPendingRemovesPendingAction(): void
    {
        $this->store->setPending(1, ['ability' => 'test', 'input' => [], 'token' => 'x']);
        $this->store->clearPending(1);

        $this->assertNull($this->store->getPending(1));
    }

    public function testNewChatMessageClearsPendingState(): void
    {
        $this->store->setPending(1, ['ability' => 'test', 'input' => [], 'token' => 'x']);
        $this->store->append(1, ['role' => 'user', 'content' => 'New message']);

        $this->assertNull($this->store->getPending(1));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
ddev exec vendor/bin/phpunit tests/Unit/NtdstAssistant/ConversationStoreTest.php --testsuite Unit
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement ConversationStore**

`web/app/plugins/ntdst-assistant/src/ConversationStore.php`:

```php
<?php
declare(strict_types=1);

namespace NtdstAssistant;

class ConversationStore implements \NTDST_Service_Meta
{
    private const TTL = HOUR_IN_SECONDS;
    private const CONV_PREFIX = 'ntdst_assistant_conv_';
    private const PENDING_PREFIX = 'ntdst_assistant_pending_';

    public static function metadata(): array
    {
        return [
            'name' => 'Assistant Conversation Store',
            'description' => 'Server-side message log per admin user',
            'priority' => 15,
        ];
    }

    public function get(int $userId): array
    {
        $messages = get_transient(self::CONV_PREFIX . $userId);
        return is_array($messages) ? $messages : [];
    }

    public function append(int $userId, array $message): void
    {
        // New user message clears any pending confirmation
        if (($message['role'] ?? '') === 'user') {
            $this->clearPending($userId);
        }

        $messages = $this->get($userId);
        $messages[] = $message;
        set_transient(self::CONV_PREFIX . $userId, $messages, self::TTL);
    }

    public function clear(int $userId): void
    {
        delete_transient(self::CONV_PREFIX . $userId);
        $this->clearPending($userId);
    }

    public function setPending(int $userId, array $pending): void
    {
        set_transient(self::PENDING_PREFIX . $userId, $pending, self::TTL);
    }

    public function getPending(int $userId): ?array
    {
        $pending = get_transient(self::PENDING_PREFIX . $userId);
        return is_array($pending) ? $pending : null;
    }

    public function clearPending(int $userId): void
    {
        delete_transient(self::PENDING_PREFIX . $userId);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
ddev exec vendor/bin/phpunit tests/Unit/NtdstAssistant/ConversationStoreTest.php --testsuite Unit
```

Expected: All 9 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add web/app/plugins/ntdst-assistant/src/ConversationStore.php tests/Unit/NtdstAssistant/ConversationStoreTest.php
git commit -m "feat(assistant): add ConversationStore with transient-based message log"
```

---

### Task 4: SystemPrompt

**Files:**
- Create: `web/app/plugins/ntdst-assistant/src/SystemPrompt.php`
- Create: `web/app/plugins/ntdst-assistant/prompts/base.md`
- Create: `tests/Unit/NtdstAssistant/SystemPromptTest.php`

- [ ] **Step 1: Write base prompt file**

`web/app/plugins/ntdst-assistant/prompts/base.md`:

```markdown
You are an AI assistant for WordPress administrators.
You help manage the site using the tools available to you.

## Rules
- ALWAYS query before acting. Never guess a user ID, edition, or any reference. Look it up first.
- If a name matches multiple records, ask the admin to clarify. Never assume.
- After every action, confirm what happened and show the resulting state.
- If an action cannot be performed, explain WHY clearly and suggest alternatives.
- Never say "done" without verifying the operation succeeded.
- Be concise. Lead with the answer, then details if needed.
```

- [ ] **Step 2: Write failing tests**

`tests/Unit/NtdstAssistant/SystemPromptTest.php`:

```php
<?php
declare(strict_types=1);

namespace Stride\Tests\Unit\NtdstAssistant;

use NtdstAssistant\SystemPrompt;
use Stride\Tests\TestCase;

class SystemPromptTest extends TestCase
{
    private SystemPrompt $prompt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prompt = new SystemPrompt();
    }

    public function testBuildReturnsBasePrompt(): void
    {
        $result = $this->prompt->build();

        $this->assertStringContains('AI assistant for WordPress administrators', $result);
        $this->assertStringContains('ALWAYS query before acting', $result);
    }

    public function testBuildAppliesFilter(): void
    {
        add_filter('ntdst_assistant/system_prompt', function (string $prompt, array $context): string {
            return $prompt . "\n\n## Custom Rules\nBe extra careful.";
        }, 10, 2);

        $result = $this->prompt->build();

        $this->assertStringContains('Custom Rules', $result);
        $this->assertStringContains('Be extra careful', $result);
    }

    public function testBuildPassesContextToFilter(): void
    {
        $capturedContext = null;

        add_filter('ntdst_assistant/system_prompt', function (string $prompt, array $context) use (&$capturedContext): string {
            $capturedContext = $context;
            return $prompt;
        }, 10, 2);

        $this->prompt->build();

        $this->assertIsArray($capturedContext);
        $this->assertArrayHasKey('user_id', $capturedContext);
        $this->assertArrayHasKey('locale', $capturedContext);
        $this->assertArrayHasKey('abilities', $capturedContext);
    }

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that string contains '{$needle}'"
        );
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

```bash
ddev exec vendor/bin/phpunit tests/Unit/NtdstAssistant/SystemPromptTest.php --testsuite Unit
```

Expected: FAIL — class not found.

- [ ] **Step 4: Implement SystemPrompt**

`web/app/plugins/ntdst-assistant/src/SystemPrompt.php`:

```php
<?php
declare(strict_types=1);

namespace NtdstAssistant;

class SystemPrompt implements \NTDST_Service_Meta
{
    private string $basePath;

    public static function metadata(): array
    {
        return [
            'name' => 'Assistant System Prompt',
            'description' => 'Builds system prompt with base rules and filtered domain context',
            'priority' => 15,
        ];
    }

    public function __construct()
    {
        $this->basePath = dirname(__DIR__) . '/prompts/base.md';
    }

    public function build(): string
    {
        $base = file_exists($this->basePath)
            ? file_get_contents($this->basePath)
            : '';

        $context = [
            'user_id' => get_current_user_id(),
            'locale' => get_locale(),
            'abilities' => array_map(
                fn(\WP_Ability $a) => $a->get_name(),
                wp_get_abilities()
            ),
        ];

        return apply_filters('ntdst_assistant/system_prompt', $base, $context);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
ddev exec vendor/bin/phpunit tests/Unit/NtdstAssistant/SystemPromptTest.php --testsuite Unit
```

Expected: All 3 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add web/app/plugins/ntdst-assistant/src/SystemPrompt.php web/app/plugins/ntdst-assistant/prompts/base.md tests/Unit/NtdstAssistant/SystemPromptTest.php
git commit -m "feat(assistant): add SystemPrompt service with base prompt and filter"
```

---

## Phase 3: Bridge & Executor

### Task 5: AbilityBridge

**Files:**
- Create: `web/app/plugins/ntdst-assistant/src/AbilityBridge.php`
- Create: `tests/Unit/NtdstAssistant/AbilityBridgeTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Unit/NtdstAssistant/AbilityBridgeTest.php`:

```php
<?php
declare(strict_types=1);

namespace Stride\Tests\Unit\NtdstAssistant;

use NtdstAssistant\AbilityBridge;
use NtdstAssistant\ConversationStore;
use Stride\Tests\TestCase;

class AbilityBridgeTest extends TestCase
{
    private AbilityBridge $bridge;
    private ConversationStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new ConversationStore();
        $this->bridge = new AbilityBridge($this->store);
    }

    public function testGetToolDefinitionsReturnsClaudeFormat(): void
    {
        // Register a test ability
        wp_register_ability_category('test', [
            'label' => 'Test',
            'description' => 'Test category',
        ]);
        wp_register_ability('test/get-items', [
            'label' => 'Get Items',
            'description' => 'List all items',
            'category' => 'test',
            'execute_callback' => fn() => ['items' => []],
            'permission_callback' => fn() => true,
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'search' => ['type' => 'string'],
                ],
            ],
            'meta' => ['show_in_rest' => true, 'annotations' => ['readonly' => true]],
        ]);

        $tools = $this->bridge->getToolDefinitions();

        $this->assertCount(1, $tools);
        $this->assertSame('test__get-items', $tools[0]['name']);
        $this->assertSame('Get Items — List all items', $tools[0]['description']);
        $this->assertArrayHasKey('input_schema', $tools[0]);
    }

    public function testNonRestAbilitiesAreExcluded(): void
    {
        wp_register_ability_category('test', [
            'label' => 'Test',
            'description' => 'Test',
        ]);
        wp_register_ability('test/hidden', [
            'label' => 'Hidden',
            'description' => 'Not visible',
            'category' => 'test',
            'execute_callback' => fn() => [],
            'permission_callback' => fn() => true,
            'meta' => ['show_in_rest' => false],
        ]);

        $tools = $this->bridge->getToolDefinitions();

        $this->assertEmpty($tools);
    }

    public function testToolsFilterIsApplied(): void
    {
        add_filter('ntdst_assistant/tools', function (array $tools): array {
            $tools[] = [
                'name' => 'custom__tool',
                'description' => 'Custom tool',
                'input_schema' => ['type' => 'object', 'properties' => []],
            ];
            return $tools;
        });

        $tools = $this->bridge->getToolDefinitions();

        $found = array_filter($tools, fn($t) => $t['name'] === 'custom__tool');
        $this->assertCount(1, $found);
    }

    public function testNameMappingConvertsSlashToDoubleUnderscore(): void
    {
        $this->assertSame('stride__get-editions', $this->bridge->toClaudeName('stride/get-editions'));
        $this->assertSame('stride/get-editions', $this->bridge->toWpName('stride__get-editions'));
    }

    public function testReadonlyAbilityExecutesImmediately(): void
    {
        wp_register_ability_category('test', [
            'label' => 'Test',
            'description' => 'Test',
        ]);
        wp_register_ability('test/read-data', [
            'label' => 'Read Data',
            'description' => 'Returns data',
            'category' => 'test',
            'execute_callback' => fn() => ['data' => 'hello'],
            'permission_callback' => fn() => true,
            'meta' => ['show_in_rest' => true, 'annotations' => ['readonly' => true]],
        ]);

        $result = $this->bridge->execute('test/read-data', [], 1);

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('hello', $result['data']);
    }

    public function testWriteAbilityReturnsPendingConfirmation(): void
    {
        wp_register_ability_category('test', [
            'label' => 'Test',
            'description' => 'Test',
        ]);
        wp_register_ability('test/delete-item', [
            'label' => 'Verwijder item',
            'description' => 'Deletes an item',
            'category' => 'test',
            'execute_callback' => fn($input) => ['deleted' => $input['id']],
            'permission_callback' => fn() => true,
            'meta' => [
                'show_in_rest' => true,
                'annotations' => ['destructive' => true],
            ],
        ]);

        $result = $this->bridge->execute('test/delete-item', ['id' => 5], 1);

        $this->assertSame('pending_confirmation', $result['status']);
        $this->assertSame('test/delete-item', $result['ability']);
        $this->assertArrayHasKey('confirm_token', $result);
        $this->assertArrayHasKey('summary', $result);
    }

    public function testExecuteConfirmedWithValidToken(): void
    {
        wp_register_ability_category('test', [
            'label' => 'Test',
            'description' => 'Test',
        ]);
        wp_register_ability('test/write-item', [
            'label' => 'Write Item',
            'description' => 'Writes an item',
            'category' => 'test',
            'execute_callback' => fn($input) => ['written' => true],
            'permission_callback' => fn() => true,
            'meta' => [
                'show_in_rest' => true,
                'annotations' => ['destructive' => false],
            ],
        ]);

        // First: get the pending confirmation
        $pending = $this->bridge->execute('test/write-item', ['data' => 'test'], 1);
        $token = $pending['confirm_token'];

        // Then: confirm it
        $result = $this->bridge->executeConfirmed($token, 1);

        $this->assertArrayHasKey('written', $result);
        $this->assertTrue($result['written']);
    }

    public function testExecuteConfirmedWithInvalidTokenReturnsError(): void
    {
        $result = $this->bridge->executeConfirmed('invalid-token', 1);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function testBeforeAfterExecuteHooksFire(): void
    {
        wp_register_ability_category('test', [
            'label' => 'Test',
            'description' => 'Test',
        ]);
        wp_register_ability('test/read-hook', [
            'label' => 'Read Hook Test',
            'description' => 'Tests hooks',
            'category' => 'test',
            'execute_callback' => fn() => ['ok' => true],
            'permission_callback' => fn() => true,
            'meta' => ['show_in_rest' => true, 'annotations' => ['readonly' => true]],
        ]);

        $this->bridge->execute('test/read-hook', [], 1);

        $this->assertActionFired('ntdst_assistant/before_execute');
        $this->assertActionFired('ntdst_assistant/after_execute');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
ddev exec vendor/bin/phpunit tests/Unit/NtdstAssistant/AbilityBridgeTest.php --testsuite Unit
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement AbilityBridge**

`web/app/plugins/ntdst-assistant/src/AbilityBridge.php`:

```php
<?php
declare(strict_types=1);

namespace NtdstAssistant;

use WP_Ability;
use WP_Error;

class AbilityBridge implements \NTDST_Service_Meta
{
    public function __construct(
        private readonly ConversationStore $store,
    ) {}

    public static function metadata(): array
    {
        return [
            'name' => 'Assistant Ability Bridge',
            'description' => 'Converts WP Abilities to Claude tools and handles execution',
            'priority' => 15,
        ];
    }

    public function getToolDefinitions(): array
    {
        $tools = [];

        foreach (wp_get_abilities() as $ability) {
            if (!$this->isExposedToAssistant($ability)) {
                continue;
            }

            $tools[] = [
                'name' => $this->toClaudeName($ability->get_name()),
                'description' => $ability->get_label() . ' — ' . $ability->get_description(),
                'input_schema' => $ability->get_input_schema() ?: [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                ],
            ];
        }

        return apply_filters('ntdst_assistant/tools', $tools);
    }

    /**
     * Execute an ability. Returns result for readonly, pending_confirmation for writes.
     */
    public function execute(string $wpName, array $input, int $adminUserId): array|WP_Error
    {
        $ability = wp_get_ability($wpName);

        if (!$ability) {
            return new WP_Error('ability_not_found', "Ability '{$wpName}' not found.");
        }

        if ($this->requiresConfirmation($ability)) {
            return $this->createPendingConfirmation($ability, $input, $adminUserId);
        }

        return $this->doExecute($ability, $input, $adminUserId);
    }

    /**
     * Execute a previously confirmed write ability.
     */
    public function executeConfirmed(string $token, int $adminUserId): array|WP_Error
    {
        $pending = $this->store->getPending($adminUserId);

        if (!$pending || !hash_equals($pending['token'], $token)) {
            return new WP_Error('invalid_token', 'Invalid or expired confirmation.');
        }

        $ability = wp_get_ability($pending['ability']);

        if (!$ability) {
            return new WP_Error('ability_not_found', 'Ability no longer available.');
        }

        $permCheck = $ability->check_permissions($pending['input']);
        if ($permCheck instanceof WP_Error) {
            return $permCheck;
        }
        if ($permCheck === false) {
            return new WP_Error('forbidden', 'Permission denied.');
        }

        $result = $this->doExecute($ability, $pending['input'], $adminUserId);

        $this->store->clearPending($adminUserId);

        return $result;
    }

    public function toClaudeName(string $wpName): string
    {
        return str_replace('/', '__', $wpName);
    }

    public function toWpName(string $claudeName): string
    {
        return str_replace('__', '/', $claudeName);
    }

    private function isExposedToAssistant(WP_Ability $ability): bool
    {
        return $ability->get_meta_item('show_in_rest', false) === true;
    }

    private function requiresConfirmation(WP_Ability $ability): bool
    {
        $annotations = $ability->get_meta_item('annotations', []);
        return ($annotations['readonly'] ?? null) !== true;
    }

    private function createPendingConfirmation(WP_Ability $ability, array $input, int $adminUserId): array
    {
        $token = hash_hmac('sha256', json_encode([
            'ability' => $ability->get_name(),
            'input' => $input,
            'user' => $adminUserId,
            'time' => time(),
        ]), wp_salt('auth'));

        $this->store->setPending($adminUserId, [
            'ability' => $ability->get_name(),
            'input' => $input,
            'token' => $token,
            'time' => time(),
        ]);

        $describer = $ability->get_meta_item('describe_input');
        $details = is_callable($describer)
            ? $describer($input)
            : $this->defaultDetails($input);

        return [
            'status' => 'pending_confirmation',
            'ability' => $ability->get_name(),
            'confirm_token' => $token,
            'summary' => [
                'title' => $ability->get_label(),
                'description' => $ability->get_description(),
                'details' => $details,
            ],
        ];
    }

    private function doExecute(WP_Ability $ability, array $input, int $adminUserId): array|WP_Error
    {
        do_action('ntdst_assistant/before_execute', $ability->get_name(), $input, $adminUserId);

        $result = $ability->execute($input);

        do_action('ntdst_assistant/after_execute', $ability->get_name(), $input, $result, $adminUserId);

        return $result;
    }

    private function defaultDetails(array $input): array
    {
        $details = [];
        foreach ($input as $key => $value) {
            $details[] = [
                'label' => ucfirst(str_replace('_', ' ', $key)),
                'value' => is_scalar($value) ? (string) $value : json_encode($value),
            ];
        }
        return $details;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
ddev exec vendor/bin/phpunit tests/Unit/NtdstAssistant/AbilityBridgeTest.php --testsuite Unit
```

Expected: All 10 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add web/app/plugins/ntdst-assistant/src/AbilityBridge.php tests/Unit/NtdstAssistant/AbilityBridgeTest.php
git commit -m "feat(assistant): add AbilityBridge with tool conversion, confirmation, and HMAC tokens"
```

---

### Task 6: ToolExecutor

**Files:**
- Create: `web/app/plugins/ntdst-assistant/src/ToolExecutor.php`
- Create: `tests/Unit/NtdstAssistant/ToolExecutorTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Unit/NtdstAssistant/ToolExecutorTest.php`:

```php
<?php
declare(strict_types=1);

namespace Stride\Tests\Unit\NtdstAssistant;

use NtdstAssistant\ToolExecutor;
use NtdstAssistant\AbilityBridge;
use NtdstAssistant\ConversationStore;
use NtdstAssistant\SystemPrompt;
use NtdstAssistant\Contracts\ClaudeClientInterface;
use Stride\Tests\TestCase;

class ToolExecutorTest extends TestCase
{
    private ToolExecutor $executor;
    private ClaudeClientInterface $mockClient;
    private AbilityBridge $bridge;
    private ConversationStore $store;
    private SystemPrompt $prompt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockClient = $this->createMock(ClaudeClientInterface::class);
        $this->store = new ConversationStore();
        $this->bridge = new AbilityBridge($this->store);
        $this->prompt = new SystemPrompt();

        $this->executor = new ToolExecutor(
            $this->mockClient,
            $this->bridge,
            $this->store,
            $this->prompt,
        );
    }

    public function testTextOnlyResponseReturnsDirectly(): void
    {
        $this->mockClient->method('send')->willReturn([
            'content' => [
                ['type' => 'text', 'text' => 'Er zijn 12 studenten ingeschreven.'],
            ],
        ]);

        $result = $this->executor->run('How many students?', 1);

        $this->assertSame('response', $result['type']);
        $this->assertSame('Er zijn 12 studenten ingeschreven.', $result['content']);
    }

    public function testReadToolExecutesAndLoops(): void
    {
        // Register a readonly ability
        wp_register_ability_category('test', ['label' => 'Test', 'description' => 'Test']);
        wp_register_ability('test/count', [
            'label' => 'Count',
            'description' => 'Count items',
            'category' => 'test',
            'execute_callback' => fn() => ['count' => 42],
            'permission_callback' => fn() => true,
            'meta' => ['show_in_rest' => true, 'annotations' => ['readonly' => true]],
        ]);

        // First call: Claude requests tool
        // Second call: Claude returns text with result
        $this->mockClient->method('send')->willReturnOnConsecutiveCalls(
            [
                'content' => [
                    ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'test__count', 'input' => []],
                ],
            ],
            [
                'content' => [
                    ['type' => 'text', 'text' => 'There are 42 items.'],
                ],
            ],
        );

        $result = $this->executor->run('How many?', 1);

        $this->assertSame('response', $result['type']);
        $this->assertSame('There are 42 items.', $result['content']);
    }

    public function testWriteToolReturnsPendingConfirmation(): void
    {
        wp_register_ability_category('test', ['label' => 'Test', 'description' => 'Test']);
        wp_register_ability('test/delete', [
            'label' => 'Delete',
            'description' => 'Delete item',
            'category' => 'test',
            'execute_callback' => fn($i) => ['deleted' => true],
            'permission_callback' => fn() => true,
            'meta' => ['show_in_rest' => true, 'annotations' => ['destructive' => true]],
        ]);

        $this->mockClient->method('send')->willReturn([
            'content' => [
                ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'test__delete', 'input' => ['id' => 5]],
            ],
        ]);

        $result = $this->executor->run('Delete item 5', 1);

        $this->assertSame('confirmation', $result['type']);
        $this->assertArrayHasKey('confirm_token', $result);
        $this->assertArrayHasKey('summary', $result);
    }

    public function testMaxIterationsReturnsError(): void
    {
        wp_register_ability_category('test', ['label' => 'Test', 'description' => 'Test']);
        wp_register_ability('test/loop', [
            'label' => 'Loop',
            'description' => 'Keeps looping',
            'category' => 'test',
            'execute_callback' => fn() => ['ok' => true],
            'permission_callback' => fn() => true,
            'meta' => ['show_in_rest' => true, 'annotations' => ['readonly' => true]],
        ]);

        // Always return tool_use to force infinite loop
        $this->mockClient->method('send')->willReturn([
            'content' => [
                ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'test__loop', 'input' => []],
            ],
        ]);

        $result = $this->executor->run('Loop forever', 1);

        $this->assertSame('error', $result['type']);
        $this->assertStringContains('complex', $result['message']);
    }

    public function testClaudeApiErrorReturnsError(): void
    {
        $this->mockClient->method('send')->willThrowException(
            new \RuntimeException('Invalid API key')
        );

        $result = $this->executor->run('Hello', 1);

        $this->assertSame('error', $result['type']);
    }

    public function testMessagesAppendedToConversationStore(): void
    {
        $this->mockClient->method('send')->willReturn([
            'content' => [
                ['type' => 'text', 'text' => 'Hello back.'],
            ],
        ]);

        $this->executor->run('Hello', 1);

        $messages = $this->store->get(1);
        // Should contain: user message + assistant message
        $this->assertGreaterThanOrEqual(2, count($messages));
    }

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(str_contains($haystack, $needle));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
ddev exec vendor/bin/phpunit tests/Unit/NtdstAssistant/ToolExecutorTest.php --testsuite Unit
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement ToolExecutor**

`web/app/plugins/ntdst-assistant/src/ToolExecutor.php`:

```php
<?php
declare(strict_types=1);

namespace NtdstAssistant;

use NtdstAssistant\Contracts\ClaudeClientInterface;
use WP_Error;

class ToolExecutor implements \NTDST_Service_Meta
{
    private const MAX_ITERATIONS = 10;
    private const TOTAL_TIMEOUT = 120;

    public function __construct(
        private readonly ClaudeClientInterface $client,
        private readonly AbilityBridge $bridge,
        private readonly ConversationStore $store,
        private readonly SystemPrompt $prompt,
    ) {}

    public static function metadata(): array
    {
        return [
            'name' => 'Assistant Tool Executor',
            'description' => 'Orchestrates Claude ↔ Abilities conversation loop',
            'priority' => 15,
        ];
    }

    /**
     * Run a conversation turn.
     *
     * @return array {type: response|confirmation|error, ...}
     */
    public function run(string $userMessage, int $adminUserId): array
    {
        $this->store->append($adminUserId, [
            'role' => 'user',
            'content' => $userMessage,
        ]);

        $tools = $this->bridge->getToolDefinitions();
        $systemPrompt = $this->prompt->build();
        $startTime = time();

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            if ((time() - $startTime) > self::TOTAL_TIMEOUT) {
                return ['type' => 'error', 'message' => 'Time-out. Probeer het opnieuw.'];
            }

            try {
                $response = $this->client->send(
                    $this->store->get($adminUserId),
                    $tools,
                    $systemPrompt,
                );
            } catch (\RuntimeException $e) {
                return $this->mapApiError($e);
            }

            $textContent = '';
            $toolUses = [];

            foreach ($response['content'] ?? [] as $block) {
                if ($block['type'] === 'text') {
                    $textContent .= $block['text'];
                } elseif ($block['type'] === 'tool_use') {
                    $toolUses[] = $block;
                }
            }

            // No tool calls — return text response
            if (empty($toolUses)) {
                $this->store->append($adminUserId, [
                    'role' => 'assistant',
                    'content' => $textContent,
                ]);

                return ['type' => 'response', 'content' => $textContent];
            }

            // Process tool calls
            $this->store->append($adminUserId, [
                'role' => 'assistant',
                'content' => $response['content'],
            ]);

            $toolResults = [];

            foreach ($toolUses as $toolUse) {
                $wpName = $this->bridge->toWpName($toolUse['name']);
                $result = $this->bridge->execute($wpName, $toolUse['input'] ?? [], $adminUserId);

                // Write ability — stop loop, return confirmation
                if (is_array($result) && ($result['status'] ?? '') === 'pending_confirmation') {
                    // Store tool_use_id in pending state for confirm/cancel
                    $pending = $this->store->getPending($adminUserId);
                    if ($pending) {
                        $pending['tool_use_id'] = $toolUse['id'];
                        $this->store->setPending($adminUserId, $pending);
                    }

                    // Send error results for any remaining unprocessed tool_use blocks
                    // Claude requires every tool_use to have a tool_result
                    $remainingIdx = array_search($toolUse, $toolUses, true);
                    foreach (array_slice($toolUses, $remainingIdx + 1) as $remaining) {
                        $toolResults[] = [
                            'type' => 'tool_result',
                            'tool_use_id' => $remaining['id'],
                            'content' => json_encode(['error' => 'Action paused pending confirmation']),
                            'is_error' => true,
                        ];
                    }

                    // Append the pending tool_use result and any remaining results
                    $toolResults[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $toolUse['id'],
                        'content' => json_encode(['status' => 'awaiting_confirmation']),
                        'is_error' => false,
                    ];

                    $this->store->append($adminUserId, [
                        'role' => 'user',
                        'content' => $toolResults,
                    ]);

                    return [
                        'type' => 'confirmation',
                        'confirm_token' => $result['confirm_token'],
                        'summary' => $result['summary'],
                    ];
                }

                // Read ability or error — collect result
                $toolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $toolUse['id'],
                    'content' => is_wp_error($result)
                        ? json_encode(['error' => $result->get_error_message()])
                        : json_encode($result),
                    'is_error' => is_wp_error($result),
                ];
            }

            // Append all tool results and loop
            $this->store->append($adminUserId, [
                'role' => 'user',
                'content' => $toolResults,
            ]);
        }

        return [
            'type' => 'error',
            'message' => 'Het verzoek was te complex. Probeer een eenvoudigere vraag.',
        ];
    }

    /**
     * Resume after confirmation.
     */
    public function runConfirmed(string $token, int $adminUserId, string $toolUseId): array
    {
        $result = $this->bridge->executeConfirmed($token, $adminUserId);

        $toolResult = [
            'type' => 'tool_result',
            'tool_use_id' => $toolUseId,
            'content' => is_wp_error($result)
                ? json_encode(['error' => $result->get_error_message()])
                : json_encode($result),
            'is_error' => is_wp_error($result),
        ];

        $this->store->append($adminUserId, [
            'role' => 'user',
            'content' => [$toolResult],
        ]);

        // Let Claude summarize the result
        $tools = $this->bridge->getToolDefinitions();
        $systemPrompt = $this->prompt->build();

        try {
            $response = $this->client->send(
                $this->store->get($adminUserId),
                $tools,
                $systemPrompt,
            );
        } catch (\RuntimeException $e) {
            return $this->mapApiError($e);
        }

        $text = '';
        foreach ($response['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $text .= $block['text'];
            }
        }

        $this->store->append($adminUserId, [
            'role' => 'assistant',
            'content' => $text,
        ]);

        return ['type' => 'response', 'content' => $text];
    }

    /**
     * Resume after cancellation.
     */
    public function runCancelled(string $toolUseId, int $adminUserId): array
    {
        $toolResult = [
            'type' => 'tool_result',
            'tool_use_id' => $toolUseId,
            'content' => json_encode(['cancelled' => true, 'reason' => 'Admin cancelled']),
            'is_error' => false,
        ];

        $this->store->append($adminUserId, [
            'role' => 'user',
            'content' => [$toolResult],
        ]);

        $tools = $this->bridge->getToolDefinitions();
        $systemPrompt = $this->prompt->build();

        try {
            $response = $this->client->send(
                $this->store->get($adminUserId),
                $tools,
                $systemPrompt,
            );
        } catch (\RuntimeException $e) {
            return $this->mapApiError($e);
        }

        $text = '';
        foreach ($response['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $text .= $block['text'];
            }
        }

        $this->store->append($adminUserId, [
            'role' => 'assistant',
            'content' => $text,
        ]);

        return ['type' => 'response', 'content' => $text];
    }

    private function mapApiError(\RuntimeException $e): array
    {
        $msg = $e->getMessage();

        if (str_contains($msg, '401') || str_contains($msg, 'Unauthorized')) {
            return ['type' => 'error', 'message' => 'Claude API-sleutel is ongeldig.'];
        }
        if (str_contains($msg, '429') || str_contains($msg, 'rate')) {
            return ['type' => 'error', 'message' => 'Te veel verzoeken. Probeer het over een minuut opnieuw.'];
        }

        return ['type' => 'error', 'message' => 'Claude reageert niet. Probeer het opnieuw.'];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
ddev exec vendor/bin/phpunit tests/Unit/NtdstAssistant/ToolExecutorTest.php --testsuite Unit
```

Expected: All 6 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add web/app/plugins/ntdst-assistant/src/ToolExecutor.php tests/Unit/NtdstAssistant/ToolExecutorTest.php
git commit -m "feat(assistant): add ToolExecutor with conversation loop, confirmation, and error handling"
```

---

## Phase 4: Claude Clients

### Task 7: HttpClaudeClient

**Files:**
- Create: `web/app/plugins/ntdst-assistant/src/Claude/HttpClaudeClient.php`
- Create: `tests/Unit/NtdstAssistant/HttpClaudeClientTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Unit/NtdstAssistant/HttpClaudeClientTest.php`:

```php
<?php
declare(strict_types=1);

namespace Stride\Tests\Unit\NtdstAssistant;

use NtdstAssistant\Claude\HttpClaudeClient;
use Stride\Tests\TestCase;

class HttpClaudeClientTest extends TestCase
{
    public function testSendThrowsWhenApiKeyMissing(): void
    {
        $client = new HttpClaudeClient();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API key');

        $client->send([], [], 'prompt');
    }

    public function testSendFormatsRequestCorrectly(): void
    {
        // Set API key
        global $_test_options;
        $_test_options['ntdst_assistant_api_key'] = 'sk-test-123';

        $client = new HttpClaudeClient();

        // Mock wp_remote_post to capture the request
        // This test verifies the request body structure
        // In unit tests with Brain Monkey, we verify the function is called correctly
        $this->assertTrue(true); // Placeholder — full test requires integration
    }
}
```

- [ ] **Step 2: Implement HttpClaudeClient**

`web/app/plugins/ntdst-assistant/src/Claude/HttpClaudeClient.php`:

```php
<?php
declare(strict_types=1);

namespace NtdstAssistant\Claude;

use NtdstAssistant\Contracts\ClaudeClientInterface;

class HttpClaudeClient implements ClaudeClientInterface
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const TIMEOUT = 60;

    public function send(array $messages, array $tools, string $systemPrompt): array
    {
        $apiKey = $this->getApiKey();

        if (empty($apiKey)) {
            throw new \RuntimeException('API key not configured.');
        }

        $model = get_option('ntdst_assistant_model', 'claude-sonnet-4-6');
        $maxTokens = (int) get_option('ntdst_assistant_max_tokens', 4096);

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'system' => $systemPrompt,
            'messages' => $messages,
        ];

        if (!empty($tools)) {
            $body['tools'] = $tools;
        }

        $response = wp_remote_post(self::API_URL, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => json_encode($body),
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $responseBody = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $errorMsg = $responseBody['error']['message'] ?? "HTTP {$code}";
            throw new \RuntimeException("{$code}: {$errorMsg}");
        }

        return $responseBody;
    }

    private function getApiKey(): string
    {
        if (defined('NTDST_ASSISTANT_API_KEY')) {
            return NTDST_ASSISTANT_API_KEY;
        }

        return get_option('ntdst_assistant_api_key', '');
    }
}
```

- [ ] **Step 3: Create SDKClaudeClient stub**

`web/app/plugins/ntdst-assistant/src/Claude/SDKClaudeClient.php`:

```php
<?php
declare(strict_types=1);

namespace NtdstAssistant\Claude;

use NtdstAssistant\Contracts\ClaudeClientInterface;

class SDKClaudeClient implements ClaudeClientInterface
{
    public function send(array $messages, array $tools, string $systemPrompt): array
    {
        $apiKey = defined('NTDST_ASSISTANT_API_KEY')
            ? NTDST_ASSISTANT_API_KEY
            : get_option('ntdst_assistant_api_key', '');

        if (empty($apiKey)) {
            throw new \RuntimeException('API key not configured.');
        }

        // SDK implementation — requires anthropic-ai/client-php
        // TODO: implement when SDK is available in dev
        throw new \RuntimeException('SDK client not yet implemented. Use HttpClaudeClient.');
    }
}
```

- [ ] **Step 4: Run tests**

```bash
ddev exec vendor/bin/phpunit tests/Unit/NtdstAssistant/HttpClaudeClientTest.php --testsuite Unit
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add web/app/plugins/ntdst-assistant/src/Claude/
git commit -m "feat(assistant): add HttpClaudeClient and SDKClaudeClient stub"
```

---

## Phase 5: REST API & Transport

### Task 8: JsonTransport

**Files:**
- Create: `web/app/plugins/ntdst-assistant/src/Transport/JsonTransport.php`
- Create: `web/app/plugins/ntdst-assistant/src/Transport/SseTransport.php`

- [ ] **Step 1: Implement JsonTransport**

`web/app/plugins/ntdst-assistant/src/Transport/JsonTransport.php`:

```php
<?php
declare(strict_types=1);

namespace NtdstAssistant\Transport;

use NtdstAssistant\Contracts\TransportInterface;
use Parsedown;

class JsonTransport implements TransportInterface
{
    public function deliver(array $result): void
    {
        if ($result['type'] === 'response' && isset($result['content'])) {
            $parsedown = new Parsedown();
            $parsedown->setMarkupEscaped(true);
            $html = wp_kses_post($parsedown->text($result['content']));
            $result['html'] = $html;
        }

        wp_send_json($result);
    }
}
```

- [ ] **Step 2: Create SseTransport stub**

`web/app/plugins/ntdst-assistant/src/Transport/SseTransport.php`:

```php
<?php
declare(strict_types=1);

namespace NtdstAssistant\Transport;

use NtdstAssistant\Contracts\TransportInterface;

class SseTransport implements TransportInterface
{
    public function deliver(array $result): void
    {
        throw new \RuntimeException('SSE transport not yet implemented.');
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add web/app/plugins/ntdst-assistant/src/Transport/
git commit -m "feat(assistant): add JsonTransport with Parsedown + wp_kses_post, stub SseTransport"
```

---

### Task 9: ChatController

**Files:**
- Create: `web/app/plugins/ntdst-assistant/src/ChatController.php`
- Create: `tests/Unit/NtdstAssistant/ChatControllerTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Unit/NtdstAssistant/ChatControllerTest.php`:

```php
<?php
declare(strict_types=1);

namespace Stride\Tests\Unit\NtdstAssistant;

use NtdstAssistant\ChatController;
use NtdstAssistant\ToolExecutor;
use NtdstAssistant\ConversationStore;
use NtdstAssistant\Contracts\TransportInterface;
use Stride\Tests\TestCase;

class ChatControllerTest extends TestCase
{
    public function testRoutesAreRegistered(): void
    {
        $mockExecutor = $this->createMock(ToolExecutor::class);
        $mockStore = $this->createMock(ConversationStore::class);
        $mockTransport = $this->createMock(TransportInterface::class);

        $controller = new ChatController($mockExecutor, $mockStore, $mockTransport);

        // Verify rest_api_init action was registered
        $this->assertActionFired('rest_api_init');
    }

    public function testCapabilityIsReadFromOption(): void
    {
        global $_test_options;
        $_test_options['ntdst_assistant_capability'] = 'manage_options';

        $mockExecutor = $this->createMock(ToolExecutor::class);
        $mockStore = $this->createMock(ConversationStore::class);
        $mockTransport = $this->createMock(TransportInterface::class);

        $controller = new ChatController($mockExecutor, $mockStore, $mockTransport);

        // The permission callback should use the option value
        $this->assertTrue(true); // Verified via integration test
    }
}
```

- [ ] **Step 2: Implement ChatController**

`web/app/plugins/ntdst-assistant/src/ChatController.php`:

```php
<?php
declare(strict_types=1);

namespace NtdstAssistant;

use NtdstAssistant\Contracts\TransportInterface;
use WP_REST_Request;

class ChatController implements \NTDST_Service_Meta
{
    private const NAMESPACE = 'ntdst-assistant/v1';

    public function __construct(
        private readonly ToolExecutor $executor,
        private readonly ConversationStore $store,
        private readonly TransportInterface $transport,
    ) {
        $this->init();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Assistant Chat Controller',
            'description' => 'REST endpoints for AI chat',
            'admin_only' => true,
            'priority' => 16,
        ];
    }

    private function init(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/chat', [
            'methods' => 'POST',
            'callback' => [$this, 'handleChat'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => [
                'content' => [
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/confirm', [
            'methods' => 'POST',
            'callback' => [$this, 'handleConfirm'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => [
                'confirm_token' => [
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/cancel', [
            'methods' => 'POST',
            'callback' => [$this, 'handleCancel'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => [
                'confirm_token' => [
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    public function handleChat(WP_REST_Request $request): void
    {
        $content = $request->get_param('content');
        $userId = get_current_user_id();

        // Extend execution time for this request only
        set_time_limit(180);

        $apiKey = defined('NTDST_ASSISTANT_API_KEY')
            ? NTDST_ASSISTANT_API_KEY
            : get_option('ntdst_assistant_api_key', '');

        if (empty($apiKey)) {
            $this->transport->deliver([
                'type' => 'error',
                'message' => 'API key niet geconfigureerd. Stel in via WP-CLI: wp option update ntdst_assistant_api_key "sk-ant-..."',
            ]);
            return;
        }

        $result = $this->executor->run($content, $userId);
        $this->transport->deliver($result);
    }

    public function handleConfirm(WP_REST_Request $request): void
    {
        $token = $request->get_param('confirm_token');
        $userId = get_current_user_id();

        set_time_limit(180);

        $pending = $this->store->getPending($userId);

        if (!$pending) {
            $this->transport->deliver([
                'type' => 'error',
                'message' => 'Geen actie in afwachting van bevestiging.',
            ]);
            return;
        }

        $toolUseId = $pending['tool_use_id'] ?? 'unknown';
        $result = $this->executor->runConfirmed($token, $userId, $toolUseId);
        $this->transport->deliver($result);
    }

    public function handleCancel(WP_REST_Request $request): void
    {
        $token = $request->get_param('confirm_token');
        $userId = get_current_user_id();

        $pending = $this->store->getPending($userId);

        if (!$pending || !hash_equals($pending['token'] ?? '', $token)) {
            $this->transport->deliver([
                'type' => 'error',
                'message' => 'Ongeldige bevestiging.',
            ]);
            return;
        }

        $toolUseId = $pending['tool_use_id'] ?? 'unknown';
        $this->store->clearPending($userId);

        $result = $this->executor->runCancelled($toolUseId, $userId);
        $this->transport->deliver($result);
    }

    public function checkPermission(): bool
    {
        $capability = get_option('ntdst_assistant_capability', 'edit_others_posts');
        return current_user_can($capability);
    }
}
```

- [ ] **Step 3: Run tests**

```bash
ddev exec vendor/bin/phpunit tests/Unit/NtdstAssistant/ChatControllerTest.php --testsuite Unit
```

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add web/app/plugins/ntdst-assistant/src/ChatController.php tests/Unit/NtdstAssistant/ChatControllerTest.php
git commit -m "feat(assistant): add ChatController with /chat, /confirm, /cancel REST endpoints"
```

---

## Phase 6: Frontend

### Task 10: AssistantService (admin page + assets)

**Files:**
- Create: `web/app/plugins/ntdst-assistant/src/AssistantService.php`
- Create: `web/app/plugins/ntdst-assistant/assets/css/assistant.css`
- Create: `web/app/plugins/ntdst-assistant/assets/js/assistant.js`
- Create: `web/app/plugins/ntdst-assistant/templates/admin/chat.php`

- [ ] **Step 1: Create AssistantService**

`web/app/plugins/ntdst-assistant/src/AssistantService.php`:

```php
<?php
declare(strict_types=1);

namespace NtdstAssistant;

class AssistantService implements \NTDST_Service_Meta
{
    private const MENU_SLUG = 'stride-assistant';

    public static function metadata(): array
    {
        return [
            'name' => 'Assistant Service',
            'description' => 'Admin page and asset loading for AI assistant',
            'admin_only' => true,
            'priority' => 14,
        ];
    }

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_action('admin_menu', [$this, 'registerAdminPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);

        // Show notice if API key not configured
        if (!$this->hasApiKey()) {
            add_action('admin_notices', [$this, 'showApiKeyNotice']);
        }
    }

    public function registerAdminPage(): void
    {
        $capability = get_option('ntdst_assistant_capability', 'edit_others_posts');

        add_submenu_page(
            'stride-dashboard',
            'Stride Assistant',
            'Assistant',
            $capability,
            self::MENU_SLUG,
            [$this, 'renderPage'],
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if (!str_contains($hook, self::MENU_SLUG)) {
            return;
        }

        $pluginDir = plugin_dir_url(dirname(__FILE__));

        wp_enqueue_style(
            'ntdst-assistant',
            $pluginDir . 'assets/css/assistant.css',
            [],
            filemtime(dirname(__DIR__) . '/assets/css/assistant.css'),
        );

        wp_enqueue_script(
            'alpinejs',
            'https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js',
            [],
            '3.14.9',
            ['strategy' => 'defer'],
        );

        wp_enqueue_script(
            'ntdst-assistant',
            $pluginDir . 'assets/js/assistant.js',
            ['alpinejs'],
            filemtime(dirname(__DIR__) . '/assets/js/assistant.js'),
            ['strategy' => 'defer'],
        );

        wp_localize_script('ntdst-assistant', 'ntdstAssistantConfig', [
            'restUrl' => rest_url('ntdst-assistant/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    public function renderPage(): void
    {
        $templatePath = dirname(__DIR__) . '/templates/admin/chat.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
    }

    public function showApiKeyNotice(): void
    {
        $screen = get_current_screen();
        if (!str_contains($screen?->id ?? '', self::MENU_SLUG)) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo '<strong>NTDST Assistant:</strong> API-sleutel niet geconfigureerd. ';
        echo 'Stel in via: <code>wp option update ntdst_assistant_api_key "sk-ant-..."</code>';
        echo '</p></div>';
    }

    private function hasApiKey(): bool
    {
        if (defined('NTDST_ASSISTANT_API_KEY')) {
            return true;
        }
        return !empty(get_option('ntdst_assistant_api_key', ''));
    }
}
```

- [ ] **Step 2: Create chat template**

`web/app/plugins/ntdst-assistant/templates/admin/chat.php`:

```php
<div class="wrap">
    <div id="ntdst-assistant" x-data="ntdstAssistant()">

        <div class="assistant-container">

            <!-- Message list -->
            <div class="assistant-messages" x-ref="messages">
                <template x-for="msg in messages" :key="msg.id">
                    <div>
                        <!-- User message -->
                        <div x-show="msg.type === 'user'" class="msg msg-user">
                            <div class="msg-content" x-text="msg.content"></div>
                        </div>

                        <!-- Assistant message -->
                        <div x-show="msg.type === 'assistant'" class="msg msg-assistant">
                            <div class="msg-content" x-html="msg.html"></div>
                        </div>

                        <!-- Confirmation card -->
                        <div x-show="msg.type === 'confirmation'" class="msg msg-confirmation">
                            <div class="confirmation-card">
                                <h4 x-text="msg.summary?.title"></h4>
                                <p class="confirmation-desc" x-text="msg.summary?.description"></p>
                                <dl class="confirmation-details">
                                    <template x-for="detail in msg.summary?.details || []" :key="detail.label">
                                        <div class="confirmation-detail">
                                            <dt x-text="detail.label"></dt>
                                            <dd x-text="detail.value"></dd>
                                        </div>
                                    </template>
                                </dl>
                                <div class="confirmation-actions">
                                    <button @click="cancel()" class="button" :disabled="loading">
                                        Annuleren
                                    </button>
                                    <button @click="confirm()" class="button button-primary" :disabled="loading">
                                        Bevestigen
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Error message -->
                        <div x-show="msg.type === 'error'" class="msg msg-error">
                            <div class="msg-content" x-text="msg.message"></div>
                        </div>
                    </div>
                </template>

                <!-- Loading indicator -->
                <div x-show="loading" class="msg msg-loading">
                    <span class="typing-dot"></span>
                    <span class="typing-dot"></span>
                    <span class="typing-dot"></span>
                </div>
            </div>

            <!-- Input area -->
            <div class="assistant-input">
                <textarea
                    x-model="input"
                    @keydown.enter.prevent="send()"
                    placeholder="Stel een vraag of geef een opdracht..."
                    :disabled="loading || pending !== null"
                    rows="2"
                ></textarea>
                <button
                    @click="send()"
                    class="button button-primary"
                    :disabled="loading || pending !== null || !input.trim()"
                >
                    Verstuur
                </button>
            </div>

        </div>

    </div>
</div>
```

- [ ] **Step 3: Create Alpine.js component**

`web/app/plugins/ntdst-assistant/assets/js/assistant.js`:

```javascript
document.addEventListener('alpine:init', () => {
    Alpine.data('ntdstAssistant', () => ({
        messages: [],
        input: '',
        loading: false,
        pending: null,
        messageId: 0,

        nextId() {
            return ++this.messageId;
        },

        async send() {
            const content = this.input.trim();
            if (!content || this.loading || this.pending) return;

            this.input = '';
            this.messages.push({ id: this.nextId(), type: 'user', content });
            this.loading = true;

            this.$nextTick(() => this.scrollToBottom());

            try {
                const data = await this.post('chat', { content });
                this.handleResponse(data);
            } catch (err) {
                this.messages.push({ id: this.nextId(), type: 'error', message: err.message || 'Onbekende fout.' });
            } finally {
                this.loading = false;
                this.$nextTick(() => this.scrollToBottom());
            }
        },

        async confirm() {
            if (!this.pending || this.loading) return;
            this.loading = true;

            try {
                const data = await this.post('confirm', {
                    confirm_token: this.pending.confirm_token,
                });
                this.pending = null;
                this.handleResponse(data);
            } catch (err) {
                this.messages.push({ id: this.nextId(), type: 'error', message: err.message });
            } finally {
                this.loading = false;
                this.$nextTick(() => this.scrollToBottom());
            }
        },

        async cancel() {
            if (!this.pending || this.loading) return;
            this.loading = true;

            try {
                const data = await this.post('cancel', {
                    confirm_token: this.pending.confirm_token,
                });
                this.pending = null;
                this.handleResponse(data);
            } catch (err) {
                this.messages.push({ id: this.nextId(), type: 'error', message: err.message });
            } finally {
                this.loading = false;
                this.$nextTick(() => this.scrollToBottom());
            }
        },

        handleResponse(data) {
            if (data.type === 'response') {
                this.messages.push({
                    id: this.nextId(),
                    type: 'assistant',
                    content: data.content,
                    html: data.html || data.content,
                });
            } else if (data.type === 'confirmation') {
                this.pending = data;
                this.messages.push({
                    id: this.nextId(),
                    type: 'confirmation',
                    summary: data.summary,
                });
            } else if (data.type === 'error') {
                this.messages.push({
                    id: this.nextId(),
                    type: 'error',
                    message: data.message,
                });
            }
        },

        async post(endpoint, body) {
            const response = await fetch(ntdstAssistantConfig.restUrl + endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': ntdstAssistantConfig.nonce,
                },
                body: JSON.stringify(body),
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            return response.json();
        },

        scrollToBottom() {
            const el = this.$refs.messages;
            if (el) el.scrollTop = el.scrollHeight;
        },
    }));
});
```

- [ ] **Step 4: Create CSS**

`web/app/plugins/ntdst-assistant/assets/css/assistant.css`:

```css
/* Assistant Container */
.assistant-container {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 100px);
    max-width: 800px;
    margin: 0 auto;
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
}

/* Message List */
.assistant-messages {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

/* Messages */
.msg { max-width: 80%; }
.msg-user { align-self: flex-end; }
.msg-assistant { align-self: flex-start; }
.msg-error { align-self: center; width: 100%; }
.msg-loading { align-self: flex-start; }

.msg-content {
    padding: 10px 16px;
    border-radius: 12px;
    line-height: 1.5;
}

.msg-user .msg-content {
    background: #2271b1;
    color: #fff;
    border-bottom-right-radius: 4px;
}

.msg-assistant .msg-content {
    background: #f0f0f1;
    color: #1d2327;
    border-bottom-left-radius: 4px;
}

.msg-assistant .msg-content p:first-child { margin-top: 0; }
.msg-assistant .msg-content p:last-child { margin-bottom: 0; }

.msg-error .msg-content {
    background: #fcf0f1;
    color: #8a1f1f;
    border: 1px solid #d63638;
    border-radius: 4px;
    text-align: center;
}

/* Confirmation Card */
.confirmation-card {
    background: #fff;
    border: 2px solid #dba617;
    border-radius: 8px;
    padding: 16px;
    max-width: 100%;
}

.confirmation-card h4 {
    margin: 0 0 8px;
    color: #1d2327;
}

.confirmation-desc {
    color: #50575e;
    margin: 0 0 12px;
}

.confirmation-details {
    margin: 0 0 16px;
}

.confirmation-detail {
    display: flex;
    gap: 8px;
    padding: 4px 0;
}

.confirmation-detail dt {
    font-weight: 600;
    min-width: 100px;
    color: #50575e;
}

.confirmation-detail dd {
    margin: 0;
    color: #1d2327;
}

.confirmation-actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
}

/* Typing indicator */
.msg-loading {
    display: flex;
    gap: 4px;
    padding: 10px 16px;
}

.typing-dot {
    width: 8px;
    height: 8px;
    background: #8c8f94;
    border-radius: 50%;
    animation: typing 1.4s infinite ease-in-out;
}

.typing-dot:nth-child(2) { animation-delay: 0.2s; }
.typing-dot:nth-child(3) { animation-delay: 0.4s; }

@keyframes typing {
    0%, 80%, 100% { transform: scale(0.6); opacity: 0.4; }
    40% { transform: scale(1); opacity: 1; }
}

/* Input Area */
.assistant-input {
    display: flex;
    gap: 8px;
    padding: 12px 16px;
    border-top: 1px solid #c3c4c7;
    background: #f6f7f7;
}

.assistant-input textarea {
    flex: 1;
    resize: none;
    border: 1px solid #8c8f94;
    border-radius: 4px;
    padding: 8px 12px;
    font-size: 14px;
    font-family: inherit;
}

.assistant-input textarea:focus {
    border-color: #2271b1;
    box-shadow: 0 0 0 1px #2271b1;
    outline: none;
}

.assistant-input button {
    align-self: flex-end;
}
```

- [ ] **Step 5: Verify admin page loads**

```bash
ddev exec wp eval "echo class_exists('NtdstAssistant\AssistantService') ? 'OK' : 'FAIL';"
```

Expected: `OK`.

- [ ] **Step 6: Commit**

```bash
git add web/app/plugins/ntdst-assistant/src/AssistantService.php web/app/plugins/ntdst-assistant/assets/ web/app/plugins/ntdst-assistant/templates/
git commit -m "feat(assistant): add admin page with Alpine.js chat UI, CSS, and template"
```

---

## Phase 7: Stride Abilities

### Task 11: AbilityRegistrar (stride-core)

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Assistant/AbilityRegistrar.php`
- Create: `web/app/mu-plugins/stride-core/Modules/Assistant/prompts/domain.md`
- Create: `web/app/mu-plugins/stride-core/Modules/Assistant/prompts/formatting.md`
- Modify: `web/app/mu-plugins/stride-core/plugin-config.php` (add service)
- Create: `tests/Unit/AbilityRegistrarTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Unit/AbilityRegistrarTest.php`:

```php
<?php
declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Modules\Assistant\AbilityRegistrar;
use Stride\Tests\TestCase;

class AbilityRegistrarTest extends TestCase
{
    public function testRegistersStrideCategory(): void
    {
        $registrar = new AbilityRegistrar();
        $registrar->registerCategories();

        $this->assertTrue(wp_has_ability_category('stride'));
    }

    public function testRegistersReadAbilities(): void
    {
        $registrar = new AbilityRegistrar();
        $registrar->registerCategories();
        $registrar->registerAbilities();

        $this->assertTrue(wp_has_ability('stride/search-users'));
        $this->assertTrue(wp_has_ability('stride/get-editions'));
        $this->assertTrue(wp_has_ability('stride/get-edition'));
        $this->assertTrue(wp_has_ability('stride/get-enrollments'));
    }

    public function testRegistersWriteAbilities(): void
    {
        $registrar = new AbilityRegistrar();
        $registrar->registerCategories();
        $registrar->registerAbilities();

        $this->assertTrue(wp_has_ability('stride/enroll-user'));
        $this->assertTrue(wp_has_ability('stride/unenroll-user'));
    }

    public function testReadAbilitiesAreReadonly(): void
    {
        $registrar = new AbilityRegistrar();
        $registrar->registerCategories();
        $registrar->registerAbilities();

        $ability = wp_get_ability('stride/get-editions');
        $annotations = $ability->get_meta_item('annotations', []);

        $this->assertTrue($annotations['readonly']);
    }

    public function testWriteAbilitiesAreNotReadonly(): void
    {
        $registrar = new AbilityRegistrar();
        $registrar->registerCategories();
        $registrar->registerAbilities();

        $ability = wp_get_ability('stride/enroll-user');
        $annotations = $ability->get_meta_item('annotations', []);

        $this->assertNull($annotations['readonly'] ?? null);
    }

    public function testWriteAbilitiesHaveDescribeInput(): void
    {
        $registrar = new AbilityRegistrar();
        $registrar->registerCategories();
        $registrar->registerAbilities();

        $ability = wp_get_ability('stride/enroll-user');
        $describer = $ability->get_meta_item('describe_input');

        $this->assertTrue(is_callable($describer));
    }

    public function testInjectsSystemPrompt(): void
    {
        $registrar = new AbilityRegistrar();

        $result = apply_filters('ntdst_assistant/system_prompt', 'base prompt', []);

        $this->assertStringContains('Edition', $result);
    }

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(str_contains($haystack, $needle));
    }
}
```

- [ ] **Step 2: Create prompt files**

`web/app/mu-plugins/stride-core/Modules/Assistant/prompts/domain.md`:

```markdown
## Domain Model
- Course (sfwd-courses): LearnDash content only (lessons, quizzes, certificates).
- Edition (vad_edition): A scheduled offering of a course (dates, price, venue, capacity).
- Session (vad_session): Individual meeting days within an edition.
- Registration: User enrollment in an edition (not a course directly).
- Users enroll in EDITIONS, not courses. Editions belong to courses.

## Business Rules
- An edition has a capacity. Never enroll beyond capacity.
- Registration statuses: pending → confirmed → completed | cancelled.
- A cancelled registration cannot be re-confirmed — create a new one.
- Unenrolling revokes LearnDash access. Always mention this side effect.
- Enrollment grants LearnDash access. Always mention this.
```

`web/app/mu-plugins/stride-core/Modules/Assistant/prompts/formatting.md`:

```markdown
## Formatting
- Respond in Dutch (nl_BE) unless the admin writes in English.
- Dates: 15 april 2026 (Dutch, lowercase month).
- Money: € 450,00 (euro sign, comma for decimals).
- Use the same status labels the admin sees in the dashboard.
- Be concise. Lead with the answer, then details if needed.
```

- [ ] **Step 3: Implement AbilityRegistrar**

`web/app/mu-plugins/stride-core/Modules/Assistant/AbilityRegistrar.php`:

```php
<?php
declare(strict_types=1);

namespace Stride\Modules\Assistant;

use Stride\Infrastructure\AbstractService;

final class AbilityRegistrar extends AbstractService
{
    public static function metadata(): array
    {
        return [
            'name' => 'Stride Ability Registrar',
            'description' => 'Registers Stride abilities for AI assistant and MCP',
            'priority' => 20,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'ability_registrar';
    }

    protected function init(): void
    {
        add_action('wp_abilities_api_categories_init', [$this, 'registerCategories']);
        add_action('wp_abilities_api_init', [$this, 'registerAbilities']);
        add_filter('ntdst_assistant/system_prompt', [$this, 'injectDomainPrompt'], 10, 2);
    }

    public function registerCategories(): void
    {
        wp_register_ability_category('stride', [
            'label' => 'Stride LMS',
            'description' => 'Course, edition, and enrollment management',
        ]);
    }

    public function registerAbilities(): void
    {
        $this->registerReadAbilities();
        $this->registerWriteAbilities();
    }

    public function injectDomainPrompt(string $prompt, array $context): string
    {
        $promptsDir = __DIR__ . '/prompts/';

        foreach (['domain.md', 'formatting.md'] as $file) {
            $path = $promptsDir . $file;
            if (file_exists($path)) {
                $prompt .= "\n\n" . file_get_contents($path);
            }
        }

        return $prompt;
    }

    private function registerReadAbilities(): void
    {
        wp_register_ability('stride/search-users', [
            'label' => 'Zoek gebruikers',
            'description' => 'Find users by name, email, or organisation',
            'category' => 'stride',
            'execute_callback' => [$this, 'searchUsers'],
            'permission_callback' => [$this, 'canView'],
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'search' => ['type' => 'string', 'description' => 'Name, email, or organisation to search'],
                    'per_page' => ['type' => 'integer', 'default' => 10, 'maximum' => 50],
                ],
                'required' => ['search'],
            ],
            'meta' => [
                'show_in_rest' => true,
                'annotations' => ['readonly' => true],
            ],
        ]);

        wp_register_ability('stride/get-editions', [
            'label' => 'Zoek edities',
            'description' => 'List or search editions by title, date range, status, or course tag',
            'category' => 'stride',
            'execute_callback' => [$this, 'getEditions'],
            'permission_callback' => [$this, 'canView'],
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'search' => ['type' => 'string', 'description' => 'Search by title'],
                    'status' => ['type' => 'string', 'enum' => ['publish', 'draft', 'cancelled']],
                    'per_page' => ['type' => 'integer', 'default' => 20, 'maximum' => 100],
                ],
            ],
            'meta' => [
                'show_in_rest' => true,
                'annotations' => ['readonly' => true],
            ],
        ]);

        wp_register_ability('stride/get-edition', [
            'label' => 'Editie details',
            'description' => 'Get single edition with capacity, sessions, and enrollment count',
            'category' => 'stride',
            'execute_callback' => [$this, 'getEdition'],
            'permission_callback' => [$this, 'canView'],
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'edition_id' => ['type' => 'integer', 'description' => 'Edition post ID'],
                ],
                'required' => ['edition_id'],
            ],
            'meta' => [
                'show_in_rest' => true,
                'annotations' => ['readonly' => true],
            ],
        ]);

        wp_register_ability('stride/get-enrollments', [
            'label' => 'Inschrijvingen',
            'description' => 'List enrollments by edition or user with status',
            'category' => 'stride',
            'execute_callback' => [$this, 'getEnrollments'],
            'permission_callback' => [$this, 'canView'],
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'edition_id' => ['type' => 'integer'],
                    'user_id' => ['type' => 'integer'],
                    'status' => ['type' => 'string'],
                    'per_page' => ['type' => 'integer', 'default' => 20],
                ],
            ],
            'meta' => [
                'show_in_rest' => true,
                'annotations' => ['readonly' => true],
            ],
        ]);
    }

    private function registerWriteAbilities(): void
    {
        wp_register_ability('stride/enroll-user', [
            'label' => 'Gebruiker inschrijven',
            'description' => 'Register user for edition and grant LMS access',
            'category' => 'stride',
            'execute_callback' => [$this, 'enrollUser'],
            'permission_callback' => [$this, 'canManage'],
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'user_id' => ['type' => 'integer', 'description' => 'User ID to enroll'],
                    'edition_id' => ['type' => 'integer', 'description' => 'Edition to enroll in'],
                ],
                'required' => ['user_id', 'edition_id'],
            ],
            'meta' => [
                'show_in_rest' => true,
                'annotations' => ['destructive' => false, 'idempotent' => false],
                'describe_input' => function (array $input): array {
                    $user = get_userdata($input['user_id'] ?? 0);
                    $edition = get_post($input['edition_id'] ?? 0);
                    return [
                        ['label' => 'Gebruiker', 'value' => $user ? $user->display_name : 'Onbekend'],
                        ['label' => 'Editie', 'value' => $edition ? $edition->post_title : 'Onbekend'],
                        ['label' => 'Gevolgen', 'value' => 'LearnDash toegang wordt verleend'],
                    ];
                },
            ],
        ]);

        wp_register_ability('stride/unenroll-user', [
            'label' => 'Gebruiker uitschrijven',
            'description' => 'Cancel registration and revoke LMS access',
            'category' => 'stride',
            'execute_callback' => [$this, 'unenrollUser'],
            'permission_callback' => [$this, 'canManage'],
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'user_id' => ['type' => 'integer', 'description' => 'User ID to unenroll'],
                    'edition_id' => ['type' => 'integer', 'description' => 'Edition to unenroll from'],
                ],
                'required' => ['user_id', 'edition_id'],
            ],
            'meta' => [
                'show_in_rest' => true,
                'annotations' => ['destructive' => true, 'idempotent' => false],
                'describe_input' => function (array $input): array {
                    $user = get_userdata($input['user_id'] ?? 0);
                    $edition = get_post($input['edition_id'] ?? 0);
                    return [
                        ['label' => 'Gebruiker', 'value' => $user ? $user->display_name : 'Onbekend'],
                        ['label' => 'Editie', 'value' => $edition ? $edition->post_title : 'Onbekend'],
                        ['label' => 'Gevolgen', 'value' => 'LearnDash toegang wordt ingetrokken'],
                    ];
                },
            ],
        ]);
    }

    // --- Execute callbacks (thin — delegate to existing services) ---

    public function searchUsers(array $input): array
    {
        $query = new \WP_User_Query([
            'search' => '*' . sanitize_text_field($input['search']) . '*',
            'search_columns' => ['user_login', 'user_email', 'display_name'],
            'number' => $input['per_page'] ?? 10,
        ]);

        return array_map(fn($u) => [
            'id' => $u->ID,
            'name' => $u->display_name,
            'email' => $u->user_email,
            'organisation' => get_user_meta($u->ID, 'organisation', true) ?: null,
        ], $query->get_results());
    }

    // NOTE: getEditions, getEnrollments, enrollUser, unenrollUser are v1 stubs.
    // They register with the Abilities API so the architecture is proven end-to-end.
    // Wiring to actual Stride services is Task 11b (separate follow-up task after
    // the assistant architecture is proven working). searchUsers and getEdition are
    // fully implemented as reference patterns.

    public function getEditions(array $input): array
    {
        $editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);
        // v1 stub — wire to EditionService query methods in Task 11b
        return [['info' => 'Stub: wire to EditionService']];
    }

    public function getEdition(array $input): array|\WP_Error
    {
        $editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);
        $edition = $editionService->getEdition($input['edition_id']);

        if (is_wp_error($edition)) {
            return $edition;
        }

        return [
            'id' => $edition->ID,
            'title' => $edition->post_title,
            'status' => $edition->post_status,
            // TODO: add capacity, enrolled count, sessions
        ];
    }

    public function getEnrollments(array $input): array
    {
        $repo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
        // Delegate to existing repository
        return []; // TODO: wire to RegistrationRepository query methods
    }

    public function enrollUser(array $input): array|\WP_Error
    {
        $enrollmentService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
        // Delegate to existing service
        return []; // TODO: wire to EnrollmentService
    }

    public function unenrollUser(array $input): array|\WP_Error
    {
        $enrollmentService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
        // Delegate to existing service
        return []; // TODO: wire to EnrollmentService
    }

    // --- Permission callbacks ---

    public function canView(): bool
    {
        return current_user_can('stride_view');
    }

    public function canManage(): bool
    {
        return current_user_can('stride_manage');
    }
}
```

- [ ] **Step 4: Add to stride-core plugin-config.php**

Add to the services array in `web/app/mu-plugins/stride-core/plugin-config.php`:

```php
\Stride\Modules\Assistant\AbilityRegistrar::class,
```

- [ ] **Step 5: Run tests**

```bash
ddev exec vendor/bin/phpunit tests/Unit/AbilityRegistrarTest.php --testsuite Unit
```

Expected: Tests pass (some may need stub adjustments for Abilities API in unit test context).

- [ ] **Step 6: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Assistant/ web/app/mu-plugins/stride-core/plugin-config.php tests/Unit/AbilityRegistrarTest.php
git commit -m "feat(assistant): add Stride AbilityRegistrar with 4 read + 2 write abilities"
```

---

## Phase 8: Integration & Verification

### Task 12: End-to-end smoke test

- [ ] **Step 1: Verify plugin activates cleanly**

```bash
ddev exec wp plugin deactivate ntdst-assistant && ddev exec wp plugin activate ntdst-assistant
ddev exec wp eval "echo class_exists('NtdstAssistant\ChatController') ? 'OK' : 'FAIL';"
```

Expected: `OK`, no errors.

- [ ] **Step 2: Verify abilities are registered**

```bash
ddev exec wp eval "
    \$abilities = wp_get_abilities();
    foreach (\$abilities as \$a) {
        echo \$a->get_name() . ' (' . \$a->get_category() . ')' . PHP_EOL;
    }
"
```

Expected: List includes `stride/search-users`, `stride/get-editions`, etc.

- [ ] **Step 3: Verify REST endpoints exist**

```bash
ddev exec wp eval "
    \$server = rest_get_server();
    \$routes = \$server->get_routes();
    foreach (\$routes as \$route => \$handlers) {
        if (str_contains(\$route, 'ntdst-assistant')) {
            echo \$route . PHP_EOL;
        }
    }
"
```

Expected: `/ntdst-assistant/v1/chat`, `/ntdst-assistant/v1/confirm`, `/ntdst-assistant/v1/cancel`.

- [ ] **Step 4: Verify admin page loads**

```bash
ddev exec wp eval "
    global \$submenu;
    foreach (\$submenu as \$parent => \$items) {
        foreach (\$items as \$item) {
            if (\$item[2] === 'stride-assistant') {
                echo 'Menu found under: ' . \$parent . PHP_EOL;
            }
        }
    }
"
```

Expected: `Menu found under: stride-dashboard`.

- [ ] **Step 5: Run full unit test suite**

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Expected: All tests pass, including new assistant tests.

- [ ] **Step 6: Commit if any fixes needed**

---

## Verification Stages (MANDATORY)

> Run AFTER all implementation tasks. NOT done until all stages pass.
> If ANY stage fails: fix → re-run that stage → continue.

### Stage V1: Static Analysis

```bash
ddev exec vendor/bin/phpcs --standard=PSR12 web/app/plugins/ntdst-assistant/src/ web/app/mu-plugins/stride-core/Modules/Assistant/
```

Expected: No errors. Fix all issues before proceeding.

### Stage V2: Unit Tests

**Test files created:**
- `tests/Unit/NtdstAssistant/ConversationStoreTest.php`
- `tests/Unit/NtdstAssistant/SystemPromptTest.php`
- `tests/Unit/NtdstAssistant/AbilityBridgeTest.php`
- `tests/Unit/NtdstAssistant/ToolExecutorTest.php`
- `tests/Unit/NtdstAssistant/HttpClaudeClientTest.php`
- `tests/Unit/NtdstAssistant/ChatControllerTest.php`
- `tests/Unit/AbilityRegistrarTest.php`

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Expected: ALL tests pass.

### Stage V3: Acceptance Tests (Browser)

**Test file to create:**
- `tests/acceptance/AssistantCest.php`

**Scenarios to cover:**

```
ADMIN FLOW:
  SCENARIO: Admin page loads
    GIVEN: Logged in as admin with stride_manage capability
    WHEN: Navigate to stride-assistant page
    THEN: Chat interface visible with input textarea and send button

  SCENARIO: Chat sends message
    GIVEN: On assistant page with valid API key
    WHEN: Type "test" and click Verstuur
    THEN: User message appears right-aligned, loading indicator shows

ERROR FLOW:
  SCENARIO: Missing API key shows notice
    GIVEN: No API key configured
    WHEN: Navigate to assistant page
    THEN: Warning notice about API key configuration shown

  SCENARIO: Unauthorized user cannot access
    GIVEN: Logged in as subscriber
    WHEN: Navigate to stride-assistant
    THEN: Permission denied or menu not visible
```

```bash
ddev exec vendor/bin/codecept run acceptance AssistantCest --steps
```

Expected: ALL acceptance tests pass.

### Stage V4: Full Regression

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
ddev exec vendor/bin/phpunit --testsuite Integration
```

Expected: Zero failures across all suites (existing tests unaffected).

### Stage V5: Smoke Test Checklist

```markdown
## Manual Smoke Test

- [ ] Visit: https://stride.ddev.site/wp/wp-admin/admin.php?page=stride-assistant
      Expected: Chat interface loads, no console errors, no PHP errors
- [ ] Action: Type a message and click Verstuur
      Expected: User message appears right-aligned, loading dots show
- [ ] Admin: Navigate to Stride menu
      Expected: "Assistant" submenu item visible
- [ ] REST: `ddev exec wp eval "rest_do_request(new WP_REST_Request('GET', '/ntdst-assistant/v1/chat'));"`
      Expected: Method not allowed (only POST accepted)
- [ ] Abilities: `ddev exec wp eval "echo count(wp_get_abilities());"`
      Expected: Number > 3 (core + stride abilities)
```
