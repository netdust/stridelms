# ntdst-core Modern PHP Refactor — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Modernize all 14 PHP files in ntdst-core to match stride-core coding standards (strict types, readonly, enums, match, union types).

**Architecture:** In-place modernization only. No class renames, no file splits, no structural changes. Keep the `NTDST_` prefixed class names and all global helper functions (`ntdst_get()`, `ntdst_log()`, etc.) unchanged. Replace Logger constants with a new `LogLevel` backed enum.

**Tech Stack:** PHP 8.1+, WordPress, ntdst-core framework

**Key references:**
- Design doc: `docs/plans/2026-03-10-ntdst-core-modern-php-design.md`
- All files live in: `web/app/mu-plugins/ntdst-core/`
- stride-core examples: `web/app/mu-plugins/stride-core/` (for reference patterns)

---

## Modernization Checklist (apply to every file)

Every file gets ALL of these changes where applicable:

1. `declare(strict_types=1)` after `<?php`
2. `readonly` on properties that are never reassigned after construction
3. Constructor promotion where constructors assign params to properties
4. Union return types (`WP_Post|WP_Error`, `string|false`, etc.)
5. `mixed` type hints on all untyped parameters
6. `?Type` nullable types where nulls are possible
7. `match` expressions replacing switch/if-else chains
8. `final` on concrete classes that are not extended
9. Arrow functions for single-expression callbacks
10. `str_starts_with()`/`str_ends_with()`/`str_contains()` replacing `strpos()` patterns
11. Return type declarations on all methods and functions

**Do NOT change:**
- Class names
- Public method signatures (parameter names/order)
- Global helper function signatures
- File locations or structure
- Architecture or design patterns

---

## Task 1: ServiceInterface + LogLevel enum

**Files:**
- Modify: `core/ServiceInterface.php`
- Create: `core/LogLevel.php`

**Step 1: Modernize ServiceInterface.php**

Add `declare(strict_types=1)` and keep the interface clean:

```php
<?php

declare(strict_types=1);

/**
 * Service Metadata Interface
 */

defined('ABSPATH') || exit;

interface NTDST_Service_Meta
{
    /**
     * @return array{name: string, description: string, admin_only?: bool, enabled?: bool, priority?: int}
     */
    public static function metadata(): array;
}
```

**Step 2: Create LogLevel enum**

```php
<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

enum LogLevel: int
{
    case Debug = 0;
    case Info = 1;
    case Warning = 2;
    case Error = 3;
    case Critical = 4;

    public function label(): string
    {
        return match ($this) {
            self::Debug => 'DEBUG',
            self::Info => 'INFO',
            self::Warning => 'WARNING',
            self::Error => 'ERROR',
            self::Critical => 'CRITICAL',
        };
    }
}
```

**Step 3: Verify**

Run: `ddev exec wp eval "require_once WPMU_PLUGIN_DIR . '/ntdst-core/core/LogLevel.php'; echo LogLevel::Warning->label();"`
Expected: `WARNING`

**Step 4: Commit**

```
feat: add LogLevel enum and strict types to ServiceInterface
```

---

## Task 2: Logger modernization

**Files:**
- Modify: `services/Logger.php`

**Changes:**
1. `declare(strict_types=1)`
2. Require and use `LogLevel` enum — replace all `self::DEBUG` etc. with `LogLevel::Debug->value`
3. Remove the 6 constants (`DEBUG`, `INFO`, `WARNING`, `ERROR`, `CRITICAL`)
4. Remove the `$levels` static array — use `LogLevel::from($level)->label()` instead
5. `readonly` on `$channel`
6. Keep `$min_level` as `int` (not readonly — it has a setter `setMinLevel()`)
7. Type all method parameters: `$level` stays `int`, `$context` stays `array`
8. The `log()` method: replace `self::ERROR` with `LogLevel::Error->value`
9. The `$levels` lookups: replace `self::$levels[$level] ?? 'UNKNOWN'` with `LogLevel::tryFrom($level)?->label() ?? 'UNKNOWN'`
10. `setMinLevel` should accept `LogLevel|int` — convert enum to int internally

**Key replacements in Logger.php:**

| Old | New |
|-----|-----|
| `public const DEBUG = 0;` ... `CRITICAL = 4;` | Remove all 5 constants |
| `protected static array $levels = [...]` | Remove entire array |
| `self::DEBUG` | `LogLevel::Debug->value` |
| `self::INFO` | `LogLevel::Info->value` |
| `self::WARNING` | `LogLevel::Warning->value` |
| `self::ERROR` | `LogLevel::Error->value` |
| `self::CRITICAL` | `LogLevel::Critical->value` |
| `self::$levels[$level] ?? 'UNKNOWN'` | `LogLevel::tryFrom($level)?->label() ?? 'UNKNOWN'` |
| `$this->min_level = defined('WP_DEBUG')...` | `$this->min_level = (defined('WP_DEBUG') && WP_DEBUG) ? LogLevel::Debug->value : LogLevel::Warning->value;` |

**setMinLevel signature change:**

```php
public function setMinLevel(LogLevel|int $level): self
{
    $this->min_level = $level instanceof LogLevel ? $level->value : $level;
    return $this;
}
```

**Step: Verify**

Run: `ddev exec wp eval "echo ntdst_log()->info('test') === null ? 'OK' : 'FAIL';"`
Expected: `OK`

Run: `ddev exec vendor/bin/phpunit --testsuite Unit --filter Logger 2>&1 | tail -5`

**Commit:**

```
refactor: replace Logger constants with LogLevel enum, add strict types
```

---

## Task 3: Container modernization

**Files:**
- Modify: `core/Container.php`

**Changes:**
1. `declare(strict_types=1)`
2. Do NOT add `final` — `NTDST_Container` might be extended by consumers
3. Return types on all methods:
   - `set()` → `: self` (already has it)
   - `get()` → `: mixed`
   - `make()` → `: object`
   - `resolve()` → `: mixed`
   - `resolveFactory()` → `: mixed`
   - `resolveClass()` → `: object`
   - `resolveParameters()` → `: array` (already has it)
   - `call()` → `: mixed`
   - `forget()` → `: self` (already has it)
   - `flush()` → `: self` (already has it)
   - `keys()` → `: array` (already has it)
4. Parameter types on `set()`: `$value` → `mixed $value = null`
5. Parameter types on global helpers:
   - `ntdst_set()`: `$value` → `mixed $value = null`
   - `ntdst_get()`: return type → `: mixed`
   - `ntdst_make()`: return type → `: mixed`

**Step: Verify**

Run: `ddev exec wp eval "ntdst_set('test_val', 42); echo ntdst_get('test_val');"`
Expected: `42`

**Commit:**

```
refactor: add strict types and return types to Container
```

---

## Task 4: Bootstrap modernization

**Files:**
- Modify: `core/Bootstrap.php`

**Changes:**
1. `declare(strict_types=1)`
2. Do NOT add `final` — themes may extend
3. `readonly` on `$sectors` (set in constructor, never reassigned)
4. `config()` method: `$default` → `mixed $default = null`, return type → `: mixed`
5. All `private` methods already have return types — verify none are missing
6. No `match` opportunities (the if/else chains have side effects)

**Step: Verify**

Run: `ddev exec wp eval "echo class_exists('NTDST_Bootstrap') ? 'OK' : 'FAIL';"`

**Commit:**

```
refactor: add strict types and readonly to Bootstrap
```

---

## Task 5: Router modernization

**Files:**
- Modify: `core/Router.php`

**Changes:**
1. `declare(strict_types=1)`
2. Do NOT add `final` — may be extended
3. Return types on all methods:
   - `preventRedirectForRoutes()` → `: string|false` (returns redirect URL or false)
   - `handleTemplateInclude()` → `: string`
   - `redirect()` → `: never`
4. Parameter types:
   - `preventRedirectForRoutes($redirect_url, $requested_url = null)` → `(string|false $redirect_url, ?string $requested_url = null)`
   - `handleTemplateInclude($template)` → `(string $template)`
   - `page($slug_or_callback, ...)` → `(string|callable $slug_or_callback, ...)`
   - `single($post_type, $callback)` → `(?string $post_type = null, ...)`
   - `archive($post_type, $callback)` → same as single
5. Global helper return types already correct

**Step: Verify**

Run: `ddev exec wp eval "echo ntdst_router() instanceof NTDST_Router ? 'OK' : 'FAIL';"`

**Commit:**

```
refactor: add strict types and return types to Router
```

---

## Task 6: Theme modernization

**Files:**
- Modify: `core/Theme.php`

**Changes (this file has the most gaps):**
1. `declare(strict_types=1)` + `defined('ABSPATH') || exit;`
2. Property types:
   - `private $config;` → `private array $config;` (already typed in constructor but declaration is untyped)
   - `private $mixins = [];` → `private array $mixins = [];`
3. `init()` return type: `private function init(): void`
4. `setup_theme()` return type: `public function setup_theme(): void`
5. `enqueue_assets()` return type: `public function enqueue_assets(): void`
6. `enqueue_admin_assets()` return type: `public function enqueue_admin_assets(): void`
7. `module()` return type: `public function module(string $module): object`
8. Anonymous class inside `module()`:
   - `private $module;` → `private readonly string $module;`
   - `private $theme;` → `private readonly NTDST_Theme $theme;`
   - Constructor: `public function __construct(string $module, NTDST_Theme $theme)`
   - `config()` return type: `public function config(callable $callback, int $priority = 20): NTDST_Theme`
   - `path()` return type: same
   - `before()`/`after()` return type: same
   - `disable()`/`enable()` return type: same
9. `on()` / `filter()` / `when()`: return type → `: self`
10. `taxonomy()`: `$post_types` → `string|array $post_types`
11. `single()`/`page()`/`archive()`: `$post_type` → `string|callable|null $post_type = null`
12. `mixin()`: `$nameOrInstance` → `string|object $nameOrInstance`
13. `__call()` return type: `: mixed`
14. `apiAction()`: `$args` → `array|int $args = 10`

**Step: Verify**

Run: `ddev exec wp eval "echo class_exists('NTDST_Theme') ? 'OK' : 'FAIL';"`

**Commit:**

```
refactor: add strict types, return types, and readonly to Theme
```

---

## Task 7: SectorRegistry modernization

**Files:**
- Modify: `core/SectorRegistry.php`

**Changes:**
1. `declare(strict_types=1)`
2. `final class` — this is a singleton, should not be extended
3. `readonly` on `TIER_HIERARCHY` — already `const`, no change needed
4. `checkRequirements()` parameter: `$requirements` → `array|string|null $requirements`
5. Return types — most are already typed, verify completeness
6. All good otherwise — this file is already near stride-core quality

**Step: Verify**

Run: `ddev exec wp eval "echo ntdst_sectors() instanceof NTDST_SectorRegistry ? 'OK' : 'FAIL';"`

**Commit:**

```
refactor: add strict types and final to SectorRegistry
```

---

## Task 8: Response + TemplateLoader modernization

**Files:**
- Modify: `api/Response.php`

**Changes:**
1. `declare(strict_types=1)`
2. `with()`: `$value` → `mixed $value`
3. `NTDST_Template_Loader`: add `final class`
4. Both classes are already well-typed — mostly just adding strict_types

**Step: Verify**

Run: `ddev exec wp eval "echo ntdst_response() instanceof NTDST_Response ? 'OK' : 'FAIL';"`

**Commit:**

```
refactor: add strict types to Response and TemplateLoader
```

---

## Task 9: QueryCache modernization

**Files:**
- Modify: `api/QueryCache.php`

**Changes:**
1. `declare(strict_types=1)`
2. `final class` — singleton, not meant to be extended
3. Already very well-typed — mostly just the header change

**Step: Verify**

Run: `ddev exec wp eval "echo NTDST_Query_Cache::instance() instanceof NTDST_Query_Cache ? 'OK' : 'FAIL';"`

**Commit:**

```
refactor: add strict types and final to QueryCache
```

---

## Task 10: Endpoints modernization

**Files:**
- Modify: `api/Endpoints.php`

**Changes:**
1. `declare(strict_types=1)`
2. `final class Endpoints` (not extended)
3. Fix brace style: `class Endpoints {` → `class Endpoints\n{`
4. Fix method brace style: `public function foo() {` → `public function foo(): void\n    {`
5. Replace `strpos()` patterns in `verifyOrigin()`:
   - `strpos($origin, parse_url($home_url, PHP_URL_HOST)) !== false` → `str_contains($origin, parse_url($home_url, PHP_URL_HOST))`
   - `strpos($referer, $home_url) === 0` → `str_starts_with($referer, $home_url)`
6. Return types on all methods:
   - `handle_get_nonce()` → `: array`
   - `handle_action()` → `: array`
   - `success()` → `: array`
   - `error()` → `: array`
   - `check_nonce_permission()` → `: bool`
   - `check_action_permission()` → `: bool`
   - `checkRateLimit()` → `: bool`
   - `verifyOrigin()` → `: bool`
   - `getClientIp()` → `: string`
   - `clear_post_cache()` → `: void`
   - `register_routes()` → `: void`
   - `register_example_actions()` → `: void`
   - `get_request_params()` → `: array`
7. Global helper already has return type

**Step: Verify**

Run: `ddev exec wp eval "echo ntdst_endpoints() instanceof Endpoints ? 'OK' : 'FAIL';"`

**Commit:**

```
refactor: add strict types, final, and modern string functions to Endpoints
```

---

## Task 11: Data.php modernization

**Files:**
- Modify: `api/Data.php`

**Changes (large file — 1695 lines):**
1. `declare(strict_types=1)`
2. `NTDST_Data_Model`: Do NOT add `final` — extended by custom models
3. Return types on all public/protected methods — scan file for missing ones:
   - `sanitizeField()`: `$value` → `mixed $value`, return → `: mixed`
   - `validateData()`: return → `: true|\WP_Error`
   - `formatRepeaterField()`: `$value` → `mixed $value`
   - All `get*()` query methods need proper return types
4. `NTDST_Data_Manager` class (if present in file): add return types
5. Global helpers: add return types where missing

**Important:** This file already uses `match` expressions (line 96-111). Just verify all methods have return types and all parameters are typed.

**Step: Verify**

Run: `ddev exec wp eval "echo ntdst_data() instanceof NTDST_Data_Manager ? 'OK' : 'FAIL';"`

**Commit:**

```
refactor: add strict types and return types to Data layer
```

---

## Task 12: MetaboxGenerator modernization

**Files:**
- Modify: `api/MetaboxGenerator.php`

**Changes (1656 lines):**
1. `declare(strict_types=1)` + `defined('ABSPATH') || exit;`
2. `final class NTDST_MetaboxGenerator` — singleton, not extended
3. Property types:
   - `private static $instance = null;` → `private static ?self $instance = null;`
   - `private $registered_models = [];` → `private array $registered_models = [];`
4. Return types on all public methods:
   - `instance()` → `: self` (already has it)
   - `register()` → `: void`
   - `register_metaboxes()` → `: void`
   - `render_metabox()` → `: void`
   - `render_tabbed_metabox()` → `: void`
   - `save_metabox_data()` → `: void`
   - `enqueue_metabox_scripts()` → `: void` (already has it)
5. All private methods: add return types
6. Add `mixed` type hints to untyped parameters

**Step: Verify**

Run: `ddev exec wp eval "echo NTDST_MetaboxGenerator::instance() instanceof NTDST_MetaboxGenerator ? 'OK' : 'FAIL';"`

**Commit:**

```
refactor: add strict types, final, and property types to MetaboxGenerator
```

---

## Task 13: Mailer modernization

**Files:**
- Modify: `services/Mailer.php`

**Changes:**
1. `declare(strict_types=1)`
2. `to()`, `cc()`, `bcc()`: parameter `$email` → `string|array $email`
3. Replace `strpos()` patterns:
   - `strpos($this->message, '<html') === false` → `!str_contains($this->message, '<html')`
   - `strpos($content, '<div style=...') === 0` → `str_starts_with($content, '<div style=...')`
   - `strpos($content, '<html') === false` → `!str_contains($content, '<html')`
4. In the `wp_mail` filter at bottom:
   - `stripos($header, 'Content-Type: text/html') !== false` → `stripos()` is fine (case-insensitive), keep it
   - `strpos($args['message'], '<html') === false` → `!str_contains($args['message'], '<html')`
5. Return types on all methods — most already typed
6. Global function `ntdst_wrap_email_in_layout()`: return type `: string` already present

**Step: Verify**

Run: `ddev exec wp eval "echo ntdst_mail() instanceof NTDST_Mailer ? 'OK' : 'FAIL';"`

**Commit:**

```
refactor: add strict types and modern string functions to Mailer
```

---

## Task 14: RelationField modernization

**Files:**
- Modify: `services/RelationField.php`

**Changes:**
1. `declare(strict_types=1)`
2. `final class NTDST_RelationField` — not extended
3. Constructor promotion: `private NTDST_Theme $theme` → `private readonly NTDST_Theme $theme`
4. Return types — most already correct
5. Fix tab indentation to spaces (currently uses tabs in some places)

**Step: Verify**

Run: `ddev exec wp eval "echo class_exists('NTDST_RelationField') ? 'OK' : 'FAIL';"`

**Commit:**

```
refactor: add strict types, final, and readonly to RelationField
```

---

## Task 15: Integration verification

**Step 1: Run unit tests**

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Expected: All tests pass.

**Step 2: Verify full plugin load**

```bash
ddev exec wp eval "
echo class_exists('NTDST_Container') ? 'Container OK' : 'Container FAIL';
echo PHP_EOL;
echo class_exists('NTDST_Bootstrap') ? 'Bootstrap OK' : 'Bootstrap FAIL';
echo PHP_EOL;
echo class_exists('NTDST_Router') ? 'Router OK' : 'Router FAIL';
echo PHP_EOL;
echo class_exists('NTDST_Theme') ? 'Theme OK' : 'Theme FAIL';
echo PHP_EOL;
echo class_exists('NTDST_Logger') ? 'Logger OK' : 'Logger FAIL';
echo PHP_EOL;
echo class_exists('NTDST_Data_Model') ? 'DataModel OK' : 'DataModel FAIL';
echo PHP_EOL;
echo enum_exists('LogLevel') ? 'LogLevel OK' : 'LogLevel FAIL';
echo PHP_EOL;
echo ntdst_log()->info('Integration test') === null ? 'Logging OK' : 'Logging FAIL';
"
```

Expected: All OK.

**Step 3: Verify stride-core services still resolve**

```bash
ddev exec wp eval "
echo ntdst_get(\Stride\Modules\Edition\EditionService::class) ? 'EditionService OK' : 'FAIL';
echo PHP_EOL;
echo ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class) ? 'EnrollmentService OK' : 'FAIL';
"
```

**Step 4: Commit verification**

```
test: verify ntdst-core modernization integration
```

---

## Execution Notes

- **Batch strategy:** Tasks 1-2 (Logger/enum) are the riskiest — verify thoroughly before proceeding.
- **Tasks 3-10** are low-risk (mostly adding type declarations to already-working code).
- **Tasks 11-12** (Data.php, MetaboxGenerator.php) are large files — be careful with strict_types as it changes type coercion behavior. Pay attention to any implicit string-to-int conversions.
- **Task 15** is the final integration check — do not skip.
- **If strict_types breaks something:** The most common issue is WordPress passing string `"0"` or `"1"` where an `int` is expected. Fix by adding explicit `(int)` casts at the boundary.
