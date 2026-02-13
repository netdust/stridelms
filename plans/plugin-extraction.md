# Plugin Extraction: Stride LMS Business Logic

## Problem/Feature

Extract business logic from theme to mu-plugin for proper WordPress architecture. Currently all services (45+ files) live in `web/app/themes/stride/services/`. This violates WordPress conventions where themes should only handle presentation.

**Why now:** End of Phase 2, starting Phase 3. Every additional phase makes extraction harder. Do it now while cost is lowest.

## Acceptance Criteria

- [ ] Business logic services moved to `web/app/mu-plugins/stride-core/`
- [ ] Theme retains only: templates, assets, presentation hooks, frontend services
- [ ] No functionality changes - all existing code works identically
- [ ] Namespace updated from `stride\services\` to `StrideCore\`
- [ ] Service registration moves to plugin, theme only registers presentation services
- [ ] Custom tables created by plugin (not theme activation)
- [ ] NTDST Core pattern followed (loader file + organized directories)

## Architecture: Before vs After

### Before (Current)
```
web/app/themes/stride/
├── functions.php         # Bootstrap + services config
├── theme-config.php      # All service registration
├── services/             # ALL 45+ service files
│   ├── core/
│   ├── enrollment/
│   ├── invoicing/
│   ├── handlers/
│   ├── sync/
│   ├── admin/
│   ├── frontend/         # Stays in theme
│   └── ...
└── templates/
```

### After (Target)
```
web/app/mu-plugins/
├── ntdst-coreloader.php          # Existing framework
├── ntdst-core/                   # Existing framework
├── stride-coreloader.php         # NEW: Plugin loader
└── stride-core/                  # NEW: Business logic
    ├── core/                     # EditionService, SessionService, etc.
    ├── enrollment/               # EnrollmentService, FormSubmissionHandler
    ├── invoicing/                # QuoteService, VoucherService
    ├── handlers/                 # EnrollmentQuoteHandler, etc.
    ├── sync/                     # UserDataSync, storage backends
    ├── adapters/                 # LearnDash, FluentCRM adapters
    ├── contracts/                # Interfaces
    ├── admin/                    # AdminMenuService
    └── plugin-config.php         # Service registration

web/app/themes/stride/
├── functions.php                 # Simplified bootstrap
├── theme-config.php              # Theme features only
├── services/
│   └── frontend/                 # ONLY frontend services remain
│       ├── DashboardService.php
│       ├── DashboardShortcodes.php
│       └── ICalService.php
└── templates/
```

## Implementation

### Step 1: Create Plugin Structure

Create `web/app/mu-plugins/stride-coreloader.php`:
```php
<?php
/**
 * Plugin Name: Stride LMS Core
 * Description: Business logic for Stride LMS
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

define('STRIDE_CORE_PATH', __DIR__ . '/stride-core');
define('STRIDE_CORE_URL', plugins_url('stride-core', __FILE__));

// Autoloader for StrideCore namespace
spl_autoload_register(function ($class) {
    $prefix = 'StrideCore\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relative_class = substr($class, strlen($prefix));
    $file = STRIDE_CORE_PATH . '/' . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Load plugin config and register services
add_action('after_setup_theme', function () {
    $config = require STRIDE_CORE_PATH . '/plugin-config.php';

    // Register all plugin services with NTDST Bootstrap
    foreach ($config['services'] as $service) {
        ntdst_set($service, fn() => new $service());
    }

    // Boot services
    foreach ($config['services'] as $service) {
        ntdst_get($service);
    }
}, 4); // Before theme bootstrap (priority 5)

// Create tables on plugin load (once)
register_activation_hook(__FILE__, function () {
    \StrideCore\core\RegistrationRepository::createTable();
});
```

### Step 2: Create Plugin Config

Create `web/app/mu-plugins/stride-core/plugin-config.php`:
```php
<?php
return [
    'services' => [
        // Core (Phase 1.5)
        \StrideCore\core\RegistrationRepository::class,
        \StrideCore\core\EditionService::class,
        \StrideCore\core\SessionService::class,
        \StrideCore\core\CourseService::class,
        \StrideCore\core\SubscriberService::class,
        \StrideCore\core\OrganizationService::class,
        \StrideCore\core\HistoricalDataService::class,

        // Enrollment (Phase 2)
        \StrideCore\enrollment\EnrollmentService::class,

        // Invoicing (Phase 3-4)
        \StrideCore\invoicing\QuoteService::class,
        \StrideCore\invoicing\VoucherService::class,

        // Handlers
        \StrideCore\handlers\EnrollmentQuoteHandler::class,
        \StrideCore\handlers\QuoteUpdateHandler::class,

        // Sync
        \StrideCore\sync\UserDataSync::class,

        // SmartCode
        \StrideCore\smartcode\SmartCodeService::class,

        // Admin
        \StrideCore\admin\AdminMenuService::class,
    ],
];
```

### Step 3: Move Service Files

| From | To |
|------|-----|
| `themes/stride/services/core/` | `mu-plugins/stride-core/core/` |
| `themes/stride/services/enrollment/` | `mu-plugins/stride-core/enrollment/` |
| `themes/stride/services/invoicing/` | `mu-plugins/stride-core/invoicing/` |
| `themes/stride/services/handlers/` | `mu-plugins/stride-core/handlers/` |
| `themes/stride/services/sync/` | `mu-plugins/stride-core/sync/` |
| `themes/stride/services/adapters/` | `mu-plugins/stride-core/adapters/` |
| `themes/stride/services/contracts/` | `mu-plugins/stride-core/contracts/` |
| `themes/stride/services/admin/` | `mu-plugins/stride-core/admin/` |
| `themes/stride/services/smartcode/` | `mu-plugins/stride-core/smartcode/` |
| `themes/stride/services/FieldRegistry.php` | `mu-plugins/stride-core/FieldRegistry.php` |

**Keep in theme:**
- `themes/stride/services/frontend/` (DashboardService, DashboardShortcodes, ICalService)

### Step 4: Update Namespaces

Find and replace in all moved files:
```
namespace stride\services\  →  namespace StrideCore\
use stride\services\        →  use StrideCore\
```

### Step 5: Update Theme

Simplify `theme-config.php`:
```php
'services' => [
    'core' => [
        // Only frontend services remain in theme
        'stride\\services\\frontend\\DashboardService',
        'stride\\services\\frontend\\DashboardShortcodes',
        'stride\\services\\frontend\\ICalService',
    ],
    // Remove all other service registrations
],
```

Update `functions.php`:
```php
// Remove service registrations - plugin handles them
// Keep only: theme features, assets, DI bindings for adapters

// Update adapter bindings to use new namespace
ntdst_set(
    \StrideCore\contracts\LearnDashAdapterInterface::class,
    fn() => new \StrideCore\adapters\LearnDashAdapter()
);
```

### Step 6: Update CLAUDE.md

Update project structure and namespaces to reflect new architecture.

## File Count

**Moving to plugin:** ~40 files
- core/: 7 files
- enrollment/: 2 files
- invoicing/: 15 files (includes Support/, Admin/, Helpers/, Export/)
- handlers/: 2 files
- sync/: 5 files
- adapters/: 2 files
- contracts/: 3 files
- admin/: 1 file
- smartcode/: 1 file
- FieldRegistry.php: 1 file

**Staying in theme:** ~3 files
- frontend/: 3 files

## Verification

After extraction, verify:
```bash
# Check plugin loads
ddev exec wp eval "echo class_exists('\StrideCore\core\EditionService') ? 'OK' : 'FAIL';"

# Check services resolve
ddev exec wp eval "var_dump(ntdst_get(\StrideCore\core\EditionService::class));"

# Run seed script
ddev exec wp eval-file scripts/seed.php
```

## Risk Mitigation

1. **Git branch:** Create `feature/plugin-extraction` branch
2. **Incremental:** Move one directory at a time, test after each
3. **Namespace script:** Use sed/find to bulk update namespaces
4. **Keep originals:** Don't delete theme files until extraction verified

## Estimated Effort

- Create plugin structure: 15 min
- Move files: 15 min
- Update namespaces: 30 min (scripted)
- Update theme: 15 min
- Testing: 30 min
- Documentation: 15 min

**Total: ~2 hours**

## References

- NTDST Core pattern: `web/app/mu-plugins/ntdst-coreloader.php`
- Current services: `web/app/themes/stride/services/`
- Service registration: `web/app/themes/stride/theme-config.php:97-161`
