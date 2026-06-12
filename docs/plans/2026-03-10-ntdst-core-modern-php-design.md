# ntdst-core Modern PHP Refactor

**Date:** 2026-03-10
**Status:** Approved
**Scope:** All 14 PHP files in `web/app/mu-plugins/ntdst-core/`

## Goal

Bring ntdst-core to stride-core coding standards. Modernize PHP style without changing the public API, class names, file structure, or architecture.

## Changes Applied Per File

| Change | Description |
|--------|-------------|
| `declare(strict_types=1)` | Add to every file after `<?php` |
| `readonly` properties | All injected/config properties that aren't reassigned |
| Constructor promotion | Where constructors assign to properties |
| Union return types | `WP_Post\|WP_Error`, `array\|WP_Error`, `int\|false` etc. |
| Nullable types | `?string`, `?int`, `?array` where nulls are possible |
| `mixed` type hints | All untyped parameters get explicit `mixed` |
| `match` expressions | Replace switch/if-else chains where appropriate |
| `final` on concrete classes | All non-extended classes get `final` |
| Arrow functions | Short closures where single-expression callbacks exist |
| `str_starts_with()`/`str_ends_with()` | Replace `strpos() === 0` patterns |
| Logger enum | Replace `NTDST_Logger` constants with `LogLevel` backed enum |

## What Does NOT Change

- Class names (`NTDST_Container`, `NTDST_Bootstrap`, etc.)
- File locations
- Public method signatures (parameter names/order)
- Global helper functions (`ntdst_get()`, `ntdst_log()`, etc.)
- Architecture or file structure
- No splitting of large files

## Logger Enum

New file `core/LogLevel.php`:

```php
<?php

declare(strict_types=1);

enum LogLevel: int
{
    case Debug = 0;
    case Info = 1;
    case Warning = 2;
    case Error = 3;
    case Critical = 4;
}
```

Remove constants from `NTDST_Logger`. Update all `NTDST_Logger::WARNING` style usage across the project.

## File Order (by dependency)

1. `core/ServiceInterface.php` — add strict_types
2. `core/LogLevel.php` — new enum file
3. `services/Logger.php` — enum integration, readonly, strict_types
4. `core/Container.php` — readonly, final, strict_types
5. `core/Bootstrap.php` — readonly, match, strict_types
6. `core/Router.php` — final, readonly, strict_types
7. `core/Theme.php` — biggest typing gaps, readonly, mixed hints
8. `core/SectorRegistry.php` — final, readonly, strict_types
9. `api/Response.php` — final, strict_types
10. `api/QueryCache.php` — final, readonly, strict_types
11. `api/Endpoints.php` — final, strict_types, match
12. `api/Data.php` — strict_types, readonly, union types
13. `api/MetaboxGenerator.php` — strict_types, typing gaps
14. `services/Mailer.php` — readonly, strict_types, typing
15. `services/RelationField.php` — final, strict_types

## Verification

- Run `ddev exec vendor/bin/phpunit --testsuite Unit` after each batch
- Verify plugin loads: `ddev exec wp eval "echo class_exists('NTDST_Container') ? 'OK' : 'FAIL';"`
- Check stride-core services still resolve

## Decisions

- **Keep `NTDST_` prefixed names** — zero breaking changes for external consumers
- **Modernize all files** — full sweep, no exceptions
- **In-place only** — no file splitting or structural changes
- **Replace Logger constants with enum** — project is young, fix it now
