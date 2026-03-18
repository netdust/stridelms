# Profile Types & Settings Page — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a Profile Types system with admin CRUD, registration integration, and dashboard profile editing — powered by a redesigned Alpine.js settings page.

**Architecture:** ProfileTypeService (wp_options + usermeta) is the data layer. StrideSettingsService is rewritten as an Alpine.js tabbed settings hub with AJAX save. Registration form and dashboard profile tab consume ProfileTypeService.

**Tech Stack:** PHP 8.3, Alpine.js, Tailwind CSS, WordPress Settings API (for menu only), ntdstAPI for AJAX

**Spec:** `docs/plans/2026-03-18-profile-types-settings-design.md`

---

## File Structure

### New files
| File | Responsibility |
|------|---------------|
| `stride-core/Modules/User/ProfileTypeService.php` | Service: CRUD for profile types option, user type get/set |
| `stride-core/assets/css/admin/settings.css` | Settings page styles (tabs, type table, color badges) |
| `stride-core/assets/js/admin/settings.js` | Alpine.js app: tab nav, general settings, profile types CRUD |
| `stride-core/templates/admin/settings.php` | Settings page shell (Alpine root, tab sidebar, content area) |
| `stride-core/templates/admin/settings/tab-general.php` | General tab: URL slugs form |
| `stride-core/templates/admin/settings/tab-profile-types.php` | Profile types tab: CRUD table |
| `tests/Unit/ProfileTypeServiceTest.php` | Unit tests for ProfileTypeService |

### Modified files
| File | Change |
|------|--------|
| `stride-core/Admin/StrideSettingsService.php` | Rewrite: Alpine.js shell, AJAX save handler, asset loading |
| `stride-core/plugin-config.php` | Add ProfileTypeService to services array |
| `stride-core/Handlers/ProfileHandler.php` | Add `profile_type` case to match() |
| `ntdst-auth/src/Handlers/AuthHandler.php` | Pass `profile_type` in $data |
| `ntdst-auth/assets/js/auth.js` | Add `profileType` field to authRegister() |
| `ntdst-auth/templates/pages/register.php` | Add profile type select field |
| `stridence/templates/dashboard/tab-profiel.php` | Add profile type section |

---

## Task 1: ProfileTypeService

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/User/ProfileTypeService.php`
- Create: `tests/Unit/ProfileTypeServiceTest.php`
- Modify: `web/app/mu-plugins/stride-core/plugin-config.php`

- [ ] **Step 1: Write unit tests**

```php
<?php
// tests/Unit/ProfileTypeServiceTest.php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stride\Modules\User\ProfileTypeService;

class ProfileTypeServiceTest extends TestCase
{
    private ProfileTypeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // Reset the option before each test
        update_option('stride_profile_types', []);
        $this->service = new ProfileTypeService();
    }

    public function test_getTypes_returns_empty_array_when_no_types(): void
    {
        $this->assertSame([], $this->service->getTypes());
    }

    public function test_getTypes_returns_stored_types(): void
    {
        $types = [
            ['slug' => 'arts', 'label' => 'Arts', 'description' => '', 'color' => '#3B82F6', 'icon' => '', 'order' => 0],
        ];
        update_option('stride_profile_types', $types);

        $service = new ProfileTypeService(); // fresh instance to clear cache
        $result = $service->getTypes();

        $this->assertCount(1, $result);
        $this->assertSame('arts', $result[0]['slug']);
    }

    public function test_getType_returns_type_by_slug(): void
    {
        $types = [
            ['slug' => 'arts', 'label' => 'Arts', 'description' => '', 'color' => '#3B82F6', 'icon' => '', 'order' => 0],
            ['slug' => 'student', 'label' => 'Student', 'description' => '', 'color' => '#10B981', 'icon' => '', 'order' => 1],
        ];
        update_option('stride_profile_types', $types);

        $service = new ProfileTypeService();
        $type = $service->getType('student');

        $this->assertNotNull($type);
        $this->assertSame('Student', $type['label']);
    }

    public function test_getType_returns_null_for_unknown_slug(): void
    {
        $this->assertNull($this->service->getType('nonexistent'));
    }

    public function test_setUserType_stores_as_array(): void
    {
        $types = [
            ['slug' => 'arts', 'label' => 'Arts', 'description' => '', 'color' => '#3B82F6', 'icon' => '', 'order' => 0],
        ];
        update_option('stride_profile_types', $types);
        $service = new ProfileTypeService();

        $result = $service->setUserType(1, 'arts');

        $this->assertTrue($result);
        $stored = get_user_meta(1, '_stride_profile_type', true);
        $this->assertSame(['arts'], $stored);
    }

    public function test_setUserType_rejects_unknown_slug(): void
    {
        $result = $this->service->setUserType(1, 'nonexistent');
        $this->assertFalse($result);
    }

    public function test_getUserType_returns_resolved_type(): void
    {
        $types = [
            ['slug' => 'arts', 'label' => 'Arts', 'description' => '', 'color' => '#3B82F6', 'icon' => '', 'order' => 0],
        ];
        update_option('stride_profile_types', $types);
        update_user_meta(1, '_stride_profile_type', ['arts']);

        $service = new ProfileTypeService();
        $type = $service->getUserType(1);

        $this->assertNotNull($type);
        $this->assertSame('arts', $type['slug']);
    }

    public function test_getUserType_returns_null_for_orphaned_slug(): void
    {
        update_user_meta(1, '_stride_profile_type', ['deleted_type']);
        $this->assertNull($this->service->getUserType(1));
    }

    public function test_getUserType_returns_null_when_no_type_set(): void
    {
        $this->assertNull($this->service->getUserType(1));
    }

    public function test_userHasType_returns_true_when_matched(): void
    {
        $types = [
            ['slug' => 'arts', 'label' => 'Arts', 'description' => '', 'color' => '#3B82F6', 'icon' => '', 'order' => 0],
        ];
        update_option('stride_profile_types', $types);
        update_user_meta(1, '_stride_profile_type', ['arts']);

        $service = new ProfileTypeService();
        $this->assertTrue($service->userHasType(1, 'arts'));
        $this->assertFalse($service->userHasType(1, 'student'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
ddev exec vendor/bin/phpunit tests/Unit/ProfileTypeServiceTest.php
```

Expected: FAIL — class `ProfileTypeService` not found.

- [ ] **Step 3: Implement ProfileTypeService**

```php
<?php
// web/app/mu-plugins/stride-core/Modules/User/ProfileTypeService.php
declare(strict_types=1);

namespace Stride\Modules\User;

/**
 * Manages profile types stored in wp_options and user profile type in usermeta.
 *
 * Profile types are admin-defined categories (e.g., "Apotheker", "Arts").
 * Used for content differentiation, pricing rules, and reporting.
 */
class ProfileTypeService implements \NTDST_Service_Meta
{
    private const OPTION_KEY = 'stride_profile_types';
    private const USER_META_KEY = '_stride_profile_type';

    /** @var array<int, array{slug: string, label: string, description: string, color: string, icon: string, order: int}>|null */
    private ?array $cachedTypes = null;

    public static function metadata(): array
    {
        return [
            'name' => 'Profile Type Service',
            'description' => 'Manages user profile types',
            'priority' => 3,
        ];
    }

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_action('ntdst_auth_registration_complete', [$this, 'onRegistrationComplete'], 10, 2);
    }

    /**
     * Get all defined profile types, ordered.
     *
     * @return array<int, array{slug: string, label: string, description: string, color: string, icon: string, order: int}>
     */
    public function getTypes(): array
    {
        if ($this->cachedTypes === null) {
            $this->cachedTypes = get_option(self::OPTION_KEY, []);
            if (!is_array($this->cachedTypes)) {
                $this->cachedTypes = [];
            }
        }

        return $this->cachedTypes;
    }

    /**
     * Get a single profile type by slug.
     */
    public function getType(string $slug): ?array
    {
        foreach ($this->getTypes() as $type) {
            if ($type['slug'] === $slug) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Get user's primary profile type (first in stored array).
     * Returns null if no type set or type no longer exists.
     */
    public function getUserType(int $userId): ?array
    {
        $slugs = get_user_meta($userId, self::USER_META_KEY, true);

        if (!is_array($slugs) || empty($slugs)) {
            return null;
        }

        return $this->getType($slugs[0]);
    }

    /**
     * Get all of user's profile types (for future multi-select).
     *
     * @return array<int, array{slug: string, label: string, description: string, color: string, icon: string, order: int}>
     */
    public function getUserTypes(int $userId): array
    {
        $slugs = get_user_meta($userId, self::USER_META_KEY, true);

        if (!is_array($slugs) || empty($slugs)) {
            return [];
        }

        $types = [];
        foreach ($slugs as $slug) {
            $type = $this->getType($slug);
            if ($type !== null) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Set user's profile type (replaces current value).
     * Returns false if slug is not a known type.
     */
    public function setUserType(int $userId, string $slug): bool
    {
        if ($this->getType($slug) === null) {
            return false;
        }

        update_user_meta($userId, self::USER_META_KEY, [$slug]);

        return true;
    }

    /**
     * Check if user has a specific profile type.
     */
    public function userHasType(int $userId, string $slug): bool
    {
        $slugs = get_user_meta($userId, self::USER_META_KEY, true);

        return is_array($slugs) && in_array($slug, $slugs, true);
    }

    /**
     * Count users with a specific profile type.
     * Uses direct DB query for performance.
     */
    public function countUsersWithType(string $slug): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta}
             WHERE meta_key = %s AND meta_value LIKE %s",
            self::USER_META_KEY,
            '%"' . $wpdb->esc_like($slug) . '"%'
        ));
    }

    /**
     * Hook: set profile type after registration.
     */
    public function onRegistrationComplete(int $userId, array $data): void
    {
        $slug = sanitize_text_field($data['profile_type'] ?? '');

        if (!empty($slug)) {
            $this->setUserType($userId, $slug);
        }
    }
}
```

- [ ] **Step 4: Register service in plugin-config.php**

Add `\Stride\Modules\User\ProfileTypeService::class` to the services array in `web/app/mu-plugins/stride-core/plugin-config.php`, after the existing User-related entries:

```php
// In the 'services' array, add:
\Stride\Modules\User\ProfileTypeService::class,
```

- [ ] **Step 5: Run tests**

```bash
ddev exec vendor/bin/phpunit tests/Unit/ProfileTypeServiceTest.php
```

Expected: ALL PASS

- [ ] **Step 6: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/User/ProfileTypeService.php tests/Unit/ProfileTypeServiceTest.php web/app/mu-plugins/stride-core/plugin-config.php
git commit -m "feat(profile-types): add ProfileTypeService with user type management"
```

---

## Task 2: Rewrite StrideSettingsService (Alpine.js shell)

**Files:**
- Rewrite: `web/app/mu-plugins/stride-core/Admin/StrideSettingsService.php`
- Create: `web/app/mu-plugins/stride-core/templates/admin/settings.php`
- Create: `web/app/mu-plugins/stride-core/templates/admin/settings/tab-general.php`
- Create: `web/app/mu-plugins/stride-core/assets/css/admin/settings.css`
- Create: `web/app/mu-plugins/stride-core/assets/js/admin/settings.js`

**Reference:** Current `StrideSettingsService.php` (247 lines), `AdminDashboardService.php` (asset loading pattern), `FieldGroupSettingsPage.php` (enqueue pattern)

- [ ] **Step 1: Rewrite StrideSettingsService.php**

Keep static slug methods intact (used by CPT registration). Replace rendering, add AJAX save handler, add asset loading.

```php
<?php
// web/app/mu-plugins/stride-core/Admin/StrideSettingsService.php
declare(strict_types=1);

namespace Stride\Admin;

use Stride\Modules\User\ProfileTypeService;

/**
 * Stride Settings Page
 *
 * Extensible Alpine.js tabbed settings hub.
 * Static slug methods remain available for CPT registration.
 *
 * Plain class — owned by EditionService (slugs affect CPT registration).
 */
class StrideSettingsService
{
    private const OPTION_URL_SLUGS = 'stride_url_slugs';
    private const SETTINGS_SLUG = 'stride-settings';
    private const CAPABILITY = 'manage_options';

    private const DEFAULT_SLUGS = [
        'trajectory' => 'trajecten',
        'edition' => 'vormingen',
    ];

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_action('admin_menu', [$this, 'registerSettingsPage'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_filter('ntdst/api_data/stride_save_settings', [$this, 'handleSaveSettings'], 10, 2);
    }

    // ─── Static slug accessors (unchanged) ───────────────────────

    public static function getTrajectorySlug(): string
    {
        $slugs = get_option(self::OPTION_URL_SLUGS, self::DEFAULT_SLUGS);
        return $slugs['trajectory'] ?? self::DEFAULT_SLUGS['trajectory'];
    }

    public static function getEditionSlug(): string
    {
        $slugs = get_option(self::OPTION_URL_SLUGS, self::DEFAULT_SLUGS);
        return $slugs['edition'] ?? self::DEFAULT_SLUGS['edition'];
    }

    public static function getAllSlugs(): array
    {
        return get_option(self::OPTION_URL_SLUGS, self::DEFAULT_SLUGS);
    }

    // ─── Admin page ──────────────────────────────────────────────

    public function registerSettingsPage(): void
    {
        add_submenu_page(
            'stride-dashboard',
            'Instellingen',
            'Instellingen',
            self::CAPABILITY,
            self::SETTINGS_SLUG,
            [$this, 'renderSettingsPage']
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if (!str_contains($hook, 'stride-settings')) {
            return;
        }

        $basePath = dirname(__DIR__);
        $cssFile = $basePath . '/assets/css/admin/settings.css';
        $jsFile = $basePath . '/assets/js/admin/settings.js';

        wp_enqueue_script('alpinejs', 'https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js', [], '3.14.3', true);

        if (file_exists($cssFile)) {
            wp_enqueue_style(
                'stride-settings',
                plugins_url('assets/css/admin/settings.css', $basePath . '/stride-core.php'),
                [],
                (string) filemtime($cssFile)
            );
        }

        if (file_exists($jsFile)) {
            wp_enqueue_script(
                'stride-settings',
                plugins_url('assets/js/admin/settings.js', $basePath . '/stride-core.php'),
                ['alpinejs'],
                (string) filemtime($jsFile),
                true
            );

            wp_localize_script('stride-settings', 'strideSettings', $this->getLocalizedData());
        }
    }

    public function renderSettingsPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $templatePath = dirname(__DIR__) . '/templates/admin/settings.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
    }

    // ─── AJAX save handler ───────────────────────────────────────

    public function handleSaveSettings(mixed $data, array $params): array|\WP_Error
    {
        if (!current_user_can(self::CAPABILITY)) {
            return new \WP_Error('forbidden', 'Geen toegang.');
        }

        $tab = sanitize_text_field($params['tab'] ?? '');

        return match ($tab) {
            'general' => $this->saveGeneralSettings($params),
            'profile-types' => $this->saveProfileTypes($params),
            default => new \WP_Error('invalid_tab', 'Onbekend tabblad.'),
        };
    }

    // ─── Tab save handlers ───────────────────────────────────────

    private function saveGeneralSettings(array $params): array
    {
        $slugs = [
            'trajectory' => sanitize_title($params['trajectory_slug'] ?? self::DEFAULT_SLUGS['trajectory']),
            'edition' => sanitize_title($params['edition_slug'] ?? self::DEFAULT_SLUGS['edition']),
        ];

        update_option(self::OPTION_URL_SLUGS, $slugs);
        delete_option('rewrite_rules');

        return [
            'success' => true,
            'message' => 'Instellingen opgeslagen.',
        ];
    }

    private function saveProfileTypes(array $params): array|\WP_Error
    {
        $rawTypes = json_decode($params['types'] ?? '[]', true);

        if (!is_array($rawTypes)) {
            return new \WP_Error('invalid_data', 'Ongeldige data.');
        }

        $sanitized = $this->sanitizeProfileTypes($rawTypes);
        update_option('stride_profile_types', $sanitized);

        // Return updated types with fresh user counts
        $profileService = ntdst_get(ProfileTypeService::class);
        $typesWithCounts = array_map(function (array $type) use ($profileService): array {
            $type['userCount'] = $profileService->countUsersWithType($type['slug']);
            return $type;
        }, $sanitized);

        return [
            'success' => true,
            'message' => 'Profieltypes opgeslagen.',
            'types' => $typesWithCounts,
        ];
    }

    private function sanitizeProfileTypes(array $types): array
    {
        $seen = [];
        $sanitized = [];

        foreach ($types as $index => $type) {
            if (!is_array($type)) {
                continue;
            }

            $slug = sanitize_title($type['slug'] ?? $type['label'] ?? '');
            $label = sanitize_text_field($type['label'] ?? '');

            if (empty($slug) || empty($label)) {
                continue;
            }

            if (isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;

            $sanitized[] = [
                'slug' => $slug,
                'label' => $label,
                'description' => sanitize_text_field($type['description'] ?? ''),
                'color' => sanitize_hex_color($type['color'] ?? '') ?: '#6B7280',
                'icon' => sanitize_text_field($type['icon'] ?? ''),
                'order' => $index,
            ];
        }

        return $sanitized;
    }

    // ─── Localized data ──────────────────────────────────────────

    private function getLocalizedData(): array
    {
        $profileService = ntdst_get(ProfileTypeService::class);
        $types = $profileService->getTypes();

        $typesWithCounts = array_map(function (array $type) use ($profileService): array {
            $type['userCount'] = $profileService->countUsersWithType($type['slug']);
            return $type;
        }, $types);

        // Discover available icons from theme
        $iconDir = get_theme_root() . '/' . get_stylesheet() . '/icons';
        $availableIcons = [];
        if (is_dir($iconDir)) {
            foreach (glob($iconDir . '/*.svg') as $file) {
                $availableIcons[] = basename($file, '.svg');
            }
            sort($availableIcons);
        }

        return [
            'general' => [
                'trajectory_slug' => self::getTrajectorySlug(),
                'edition_slug' => self::getEditionSlug(),
                'siteUrl' => home_url(),
            ],
            'profileTypes' => [
                'types' => $typesWithCounts,
                'availableIcons' => $availableIcons,
            ],
        ];
    }
}
```

- [ ] **Step 2: Create settings.php template shell**

```php
<?php
// web/app/mu-plugins/stride-core/templates/admin/settings.php
declare(strict_types=1);
defined('ABSPATH') || exit;

$tabs = [
    'general'       => ['label' => 'Algemeen', 'icon' => 'dashicons-admin-generic'],
    'profile-types' => ['label' => 'Profieltypes', 'icon' => 'dashicons-groups'],
];
$default_tab = 'general';
?>
<div class="wrap stride-settings" x-data="strideSettingsApp()" x-cloak>
    <h1 class="wp-heading-inline"><?php esc_html_e('Stride Instellingen', 'stride'); ?></h1>

    <!-- Status message -->
    <div x-show="message" x-transition
         :class="messageType === 'success' ? 'notice notice-success' : 'notice notice-error'"
         class="is-dismissible" style="margin-top: 12px;">
        <p x-text="message"></p>
    </div>

    <div class="stride-settings__layout">
        <!-- Tab Navigation -->
        <nav class="stride-settings__nav">
            <?php foreach ($tabs as $slug => $tab): ?>
                <button type="button"
                        class="stride-settings__nav-item"
                        :class="{ 'is-active': activeTab === '<?php echo esc_attr($slug); ?>' }"
                        @click="switchTab('<?php echo esc_attr($slug); ?>')">
                    <span class="dashicons <?php echo esc_attr($tab['icon']); ?>"></span>
                    <?php echo esc_html($tab['label']); ?>
                </button>
            <?php endforeach; ?>
        </nav>

        <!-- Tab Content -->
        <div class="stride-settings__content">
            <?php foreach ($tabs as $slug => $tab): ?>
                <div x-show="activeTab === '<?php echo esc_attr($slug); ?>'" x-transition>
                    <?php
                    $tabFile = __DIR__ . '/settings/tab-' . $slug . '.php';
                    if (file_exists($tabFile)) {
                        include $tabFile;
                    }
                    ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
```

- [ ] **Step 3: Create tab-general.php**

```php
<?php
// web/app/mu-plugins/stride-core/templates/admin/settings/tab-general.php
declare(strict_types=1);
defined('ABSPATH') || exit;
?>
<div class="stride-settings__section">
    <h2>URL Slugs</h2>
    <p class="description">Configureer de URL slugs voor trajecten en vormingen.</p>

    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label>Trajecten URL</label></th>
            <td>
                <input type="text" x-model="general.trajectory_slug" class="regular-text">
                <p class="description">
                    URL: <span x-text="general.siteUrl"></span>/<strong x-text="general.trajectory_slug"></strong>/traject-naam/
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label>Vormingen URL</label></th>
            <td>
                <input type="text" x-model="general.edition_slug" class="regular-text">
                <p class="description">
                    URL: <span x-text="general.siteUrl"></span>/<strong x-text="general.edition_slug"></strong>/editie-naam/
                </p>
            </td>
        </tr>
    </table>

    <p class="submit">
        <button type="button" class="button button-primary" @click="saveGeneral()" :disabled="saving">
            <span x-show="!saving">Opslaan</span>
            <span x-show="saving">Opslaan...</span>
        </button>
    </p>

    <hr>
    <h3>Rewrite Rules</h3>
    <p>
        <a href="<?php echo esc_url(admin_url('options-permalink.php')); ?>" class="button">
            Permalinks opnieuw opslaan
        </a>
    </p>
</div>
```

- [ ] **Step 4: Create settings.css**

```css
/* web/app/mu-plugins/stride-core/assets/css/admin/settings.css */

[x-cloak] { display: none !important; }

.stride-settings__layout {
    display: flex;
    gap: 24px;
    margin-top: 20px;
    min-height: 500px;
}

.stride-settings__nav {
    flex: 0 0 200px;
    display: flex;
    flex-direction: column;
    gap: 2px;
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 8px;
    align-self: flex-start;
}

.stride-settings__nav-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border: none;
    background: none;
    cursor: pointer;
    border-radius: 3px;
    font-size: 13px;
    color: #1d2327;
    text-align: left;
    width: 100%;
    transition: background-color 0.15s;
}

.stride-settings__nav-item:hover {
    background-color: #f0f0f1;
}

.stride-settings__nav-item.is-active {
    background-color: #2271b1;
    color: #fff;
}

.stride-settings__nav-item.is-active .dashicons {
    color: #fff;
}

.stride-settings__nav-item .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
    color: #50575e;
}

.stride-settings__content {
    flex: 1;
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 20px 24px;
}

.stride-settings__section h2 {
    margin-top: 0;
    padding-top: 0;
}

/* ─── Profile Types Table ─── */

.stride-profile-types-table {
    width: 100%;
    border-collapse: collapse;
}

.stride-profile-types-table th {
    text-align: left;
    padding: 8px 10px;
    border-bottom: 1px solid #c3c4c7;
    font-size: 13px;
    font-weight: 600;
}

.stride-profile-types-table td {
    padding: 8px 10px;
    border-bottom: 1px solid #f0f0f1;
    font-size: 13px;
    vertical-align: middle;
}

.stride-profile-types-table tr:last-child td {
    border-bottom: none;
}

.stride-type-color {
    display: inline-block;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    vertical-align: middle;
    border: 1px solid rgba(0, 0, 0, 0.1);
}

.stride-type-slug {
    color: #787c82;
    font-family: monospace;
    font-size: 12px;
}

.stride-type-actions {
    display: flex;
    gap: 8px;
}

.stride-type-actions button {
    background: none;
    border: none;
    cursor: pointer;
    color: #50575e;
    padding: 2px;
    font-size: 13px;
}

.stride-type-actions button:hover {
    color: #2271b1;
}

.stride-type-actions button.is-destructive:hover {
    color: #d63638;
}

/* ─── Inline Edit Row ─── */

.stride-type-edit-row td {
    padding: 12px 10px;
    background: #f9f9f9;
}

.stride-type-edit-row input[type="text"],
.stride-type-edit-row select {
    width: 100%;
    max-width: 250px;
}

.stride-type-edit-row input[type="color"] {
    width: 40px;
    height: 30px;
    padding: 2px;
    border: 1px solid #c3c4c7;
    cursor: pointer;
}

/* ─── Empty State ─── */

.stride-empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #787c82;
}

.stride-empty-state p {
    margin-bottom: 12px;
}

/* ─── Delete Confirm ─── */

.stride-confirm-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 100000;
}

.stride-confirm-dialog {
    background: #fff;
    border-radius: 8px;
    padding: 24px;
    max-width: 400px;
    width: 90%;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
}

.stride-confirm-dialog h3 {
    margin-top: 0;
}

.stride-confirm-dialog__actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 16px;
}
```

- [ ] **Step 5: Create settings.js (Alpine.js app)**

```javascript
// web/app/mu-plugins/stride-core/assets/js/admin/settings.js

function strideSettingsApp() {
    const data = window.strideSettings || {};

    return {
        activeTab: 'general',
        saving: false,
        message: '',
        messageType: 'success',

        // General tab
        general: {
            trajectory_slug: data.general?.trajectory_slug || 'trajecten',
            edition_slug: data.general?.edition_slug || 'vormingen',
            siteUrl: data.general?.siteUrl || '',
        },

        // Profile types tab
        types: JSON.parse(JSON.stringify(data.profileTypes?.types || [])),
        availableIcons: data.profileTypes?.availableIcons || [],
        editingIndex: null,
        editForm: { label: '', slug: '', description: '', color: '#6B7280', icon: '' },
        isNew: false,
        confirmDelete: null,

        init() {
            // Read tab from URL hash
            const hash = window.location.hash.replace('#', '');
            if (['general', 'profile-types'].includes(hash)) {
                this.activeTab = hash;
            }
        },

        switchTab(tab) {
            this.activeTab = tab;
            window.location.hash = tab;
            this.message = '';
        },

        showMessage(text, type = 'success') {
            this.message = text;
            this.messageType = type;
            setTimeout(() => { this.message = ''; }, 4000);
        },

        // ─── General tab ───

        async saveGeneral() {
            this.saving = true;
            try {
                const result = await this.apiCall('stride_save_settings', {
                    tab: 'general',
                    trajectory_slug: this.general.trajectory_slug,
                    edition_slug: this.general.edition_slug,
                });
                this.showMessage(result.message || 'Opgeslagen');
            } catch (e) {
                this.showMessage(e.message || 'Opslaan mislukt', 'error');
            }
            this.saving = false;
        },

        // ─── Profile types tab ───

        slugify(text) {
            return text.toLowerCase()
                .replace(/[^a-z0-9\s-]/g, '')
                .replace(/\s+/g, '-')
                .replace(/-+/g, '-')
                .trim();
        },

        startAdd() {
            this.editForm = { label: '', slug: '', description: '', color: '#6B7280', icon: '' };
            this.isNew = true;
            this.editingIndex = -1;
        },

        startEdit(index) {
            const type = this.types[index];
            this.editForm = { ...type };
            this.isNew = false;
            this.editingIndex = index;
        },

        cancelEdit() {
            this.editingIndex = null;
            this.isNew = false;
        },

        saveType() {
            const label = this.editForm.label.trim();
            if (!label) return;

            if (this.isNew) {
                const slug = this.slugify(this.editForm.slug || label);
                // Check uniqueness
                if (this.types.some(t => t.slug === slug)) {
                    alert('Er bestaat al een profieltype met deze slug: ' + slug);
                    return;
                }
                this.types.push({
                    label: label,
                    slug: slug,
                    description: this.editForm.description.trim(),
                    color: this.editForm.color || '#6B7280',
                    icon: this.editForm.icon || '',
                    order: this.types.length,
                    userCount: 0,
                });
            } else {
                // Update existing (slug stays immutable)
                const type = this.types[this.editingIndex];
                type.label = label;
                type.description = this.editForm.description.trim();
                type.color = this.editForm.color || '#6B7280';
                type.icon = this.editForm.icon || '';
            }

            this.editingIndex = null;
            this.isNew = false;
        },

        requestDelete(index) {
            this.confirmDelete = index;
        },

        cancelDelete() {
            this.confirmDelete = null;
        },

        confirmDeleteType() {
            if (this.confirmDelete !== null) {
                this.types.splice(this.confirmDelete, 1);
                this.confirmDelete = null;
            }
        },

        async saveProfileTypes() {
            this.saving = true;
            try {
                const result = await this.apiCall('stride_save_settings', {
                    tab: 'profile-types',
                    types: JSON.stringify(this.types),
                });
                if (result.types) {
                    this.types = result.types;
                }
                this.showMessage(result.message || 'Opgeslagen');
            } catch (e) {
                this.showMessage(e.message || 'Opslaan mislukt', 'error');
            }
            this.saving = false;
        },

        // ─── API helper ───

        async apiCall(action, params) {
            const formData = new FormData();
            formData.append('action', action);

            for (const [key, value] of Object.entries(params)) {
                formData.append(key, value);
            }

            // Use ntdstAPI if available, otherwise direct fetch
            if (typeof ntdstAPI !== 'undefined') {
                return ntdstAPI.call(action, params);
            }

            // Fallback: direct REST call
            const response = await fetch('/wp-json/ntdst/v1/action', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpApiSettings?.nonce || '' },
                body: JSON.stringify({ action, ...params }),
                credentials: 'same-origin',
            });

            const json = await response.json();
            if (!json.success) throw new Error(json.data?.message || json.error || 'Error');
            return json.data;
        },
    };
}
```

- [ ] **Step 6: Verify settings page loads**

```bash
ddev launch /wp/wp-admin/admin.php?page=stride-settings
```

Expected: Tabbed settings page renders with "Algemeen" tab active, URL slug fields populated.

- [ ] **Step 7: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/StrideSettingsService.php web/app/mu-plugins/stride-core/templates/admin/settings.php web/app/mu-plugins/stride-core/templates/admin/settings/tab-general.php web/app/mu-plugins/stride-core/assets/css/admin/settings.css web/app/mu-plugins/stride-core/assets/js/admin/settings.js
git commit -m "feat(settings): rewrite settings page as Alpine.js tabbed hub"
```

---

## Task 3: Profile Types Admin Tab

**Files:**
- Create: `web/app/mu-plugins/stride-core/templates/admin/settings/tab-profile-types.php`

- [ ] **Step 1: Create tab-profile-types.php**

```php
<?php
// web/app/mu-plugins/stride-core/templates/admin/settings/tab-profile-types.php
declare(strict_types=1);
defined('ABSPATH') || exit;
?>
<div class="stride-settings__section">
    <h2>Profieltypes</h2>
    <p class="description">Beheer de profieltypes die gebruikers kunnen kiezen bij registratie en in hun profiel.</p>

    <!-- Types table -->
    <template x-if="types.length > 0">
        <table class="stride-profile-types-table">
            <thead>
                <tr>
                    <th style="width: 30px;"></th>
                    <th>Naam</th>
                    <th>Slug</th>
                    <th>Omschrijving</th>
                    <th>Icoon</th>
                    <th style="width: 60px;">Gebruikers</th>
                    <th style="width: 80px;"></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="(type, index) in types" :key="type.slug || index">
                    <tr x-show="editingIndex !== index">
                        <td><span class="stride-type-color" :style="'background-color:' + type.color"></span></td>
                        <td x-text="type.label"></td>
                        <td><span class="stride-type-slug" x-text="type.slug"></span></td>
                        <td x-text="type.description || '—'"></td>
                        <td x-text="type.icon || '—'"></td>
                        <td x-text="type.userCount || 0"></td>
                        <td>
                            <div class="stride-type-actions">
                                <button type="button" @click="startEdit(index)" title="Bewerken">
                                    <span class="dashicons dashicons-edit"></span>
                                </button>
                                <button type="button" @click="requestDelete(index)" class="is-destructive" title="Verwijderen">
                                    <span class="dashicons dashicons-trash"></span>
                                </button>
                            </div>
                        </td>
                    </tr>
                </template>
                <template x-for="(type, index) in types" :key="'edit-' + (type.slug || index)">
                    <tr x-show="editingIndex === index" class="stride-type-edit-row">
                        <td colspan="7">
                            <div style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
                                <div>
                                    <label class="screen-reader-text">Naam</label>
                                    <input type="text" x-model="editForm.label" placeholder="Naam" style="max-width: 160px;" required>
                                </div>
                                <div>
                                    <label class="screen-reader-text">Slug</label>
                                    <input type="text" :value="type.slug" disabled style="max-width: 120px; background: #f0f0f1; cursor: not-allowed;">
                                </div>
                                <div>
                                    <label class="screen-reader-text">Omschrijving</label>
                                    <input type="text" x-model="editForm.description" placeholder="Omschrijving" style="max-width: 200px;">
                                </div>
                                <div>
                                    <label class="screen-reader-text">Kleur</label>
                                    <input type="color" x-model="editForm.color">
                                </div>
                                <div>
                                    <label class="screen-reader-text">Icoon</label>
                                    <select x-model="editForm.icon" style="max-width: 140px;">
                                        <option value="">Geen icoon</option>
                                        <template x-for="icon in availableIcons" :key="icon">
                                            <option :value="icon" x-text="icon"></option>
                                        </template>
                                    </select>
                                </div>
                                <div style="display: flex; gap: 6px;">
                                    <button type="button" class="button button-primary button-small" @click="saveType()">OK</button>
                                    <button type="button" class="button button-small" @click="cancelEdit()">Annuleren</button>
                                </div>
                            </div>
                        </td>
                    </tr>
                </template>

                <!-- New type row -->
                <template x-if="isNew && editingIndex === -1">
                    <tr class="stride-type-edit-row">
                        <td colspan="7">
                            <div style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
                                <div>
                                    <label class="screen-reader-text">Naam</label>
                                    <input type="text" x-model="editForm.label" placeholder="Naam" style="max-width: 160px;" @input="editForm.slug = slugify(editForm.label)" required>
                                </div>
                                <div>
                                    <label class="screen-reader-text">Slug</label>
                                    <input type="text" x-model="editForm.slug" placeholder="slug" style="max-width: 120px;" @input="editForm.slug = slugify(editForm.slug)">
                                </div>
                                <div>
                                    <label class="screen-reader-text">Omschrijving</label>
                                    <input type="text" x-model="editForm.description" placeholder="Omschrijving" style="max-width: 200px;">
                                </div>
                                <div>
                                    <label class="screen-reader-text">Kleur</label>
                                    <input type="color" x-model="editForm.color">
                                </div>
                                <div>
                                    <label class="screen-reader-text">Icoon</label>
                                    <select x-model="editForm.icon" style="max-width: 140px;">
                                        <option value="">Geen icoon</option>
                                        <template x-for="icon in availableIcons" :key="icon">
                                            <option :value="icon" x-text="icon"></option>
                                        </template>
                                    </select>
                                </div>
                                <div style="display: flex; gap: 6px;">
                                    <button type="button" class="button button-primary button-small" @click="saveType()">Toevoegen</button>
                                    <button type="button" class="button button-small" @click="cancelEdit()">Annuleren</button>
                                </div>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </template>

    <!-- Empty state -->
    <template x-if="types.length === 0 && !isNew">
        <div class="stride-empty-state">
            <p>Nog geen profieltypes aangemaakt.</p>
        </div>
    </template>

    <!-- Actions -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 16px;">
        <button type="button" class="button" @click="startAdd()" x-show="editingIndex === null">
            <span class="dashicons dashicons-plus-alt2" style="vertical-align: text-bottom;"></span>
            Profieltype toevoegen
        </button>
        <button type="button" class="button button-primary" @click="saveProfileTypes()" :disabled="saving" x-show="types.length > 0 || isNew">
            <span x-show="!saving">Opslaan</span>
            <span x-show="saving">Opslaan...</span>
        </button>
    </div>

    <!-- Delete Confirmation Dialog -->
    <template x-if="confirmDelete !== null">
        <div class="stride-confirm-overlay" @click.self="cancelDelete()">
            <div class="stride-confirm-dialog">
                <h3>Profieltype verwijderen</h3>
                <template x-if="types[confirmDelete]?.userCount > 0">
                    <p>
                        <strong x-text="types[confirmDelete]?.userCount + ' gebruikers hebben dit profieltype.'"></strong><br>
                        Weet je zeker dat je dit wilt verwijderen?
                    </p>
                </template>
                <template x-if="!types[confirmDelete]?.userCount">
                    <p>Weet je zeker dat je dit profieltype wilt verwijderen?</p>
                </template>
                <div class="stride-confirm-dialog__actions">
                    <button type="button" class="button" @click="cancelDelete()">Annuleren</button>
                    <button type="button" class="button button-primary" style="background: #d63638; border-color: #d63638;" @click="confirmDeleteType()">Verwijderen</button>
                </div>
            </div>
        </div>
    </template>
</div>
```

- [ ] **Step 2: Verify profile types tab**

Navigate to Settings page → click "Profieltypes" tab → add a type → save → verify it persists after page reload.

```bash
ddev launch "/wp/wp-admin/admin.php?page=stride-settings#profile-types"
```

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/templates/admin/settings/tab-profile-types.php
git commit -m "feat(settings): add profile types CRUD tab"
```

---

## Task 4: Registration Form Integration

**Files:**
- Modify: `web/app/plugins/ntdst-auth/src/Handlers/AuthHandler.php:131-137`
- Modify: `web/app/plugins/ntdst-auth/assets/js/auth.js:96-163`
- Modify: `web/app/plugins/ntdst-auth/templates/pages/register.php:56-57`

- [ ] **Step 1: Add profile_type to AuthHandler $data array**

In `AuthHandler.php`, inside `ajaxRegister()`, add `profile_type` to the `$data` array (line 131-137):

```php
// Replace the $data array in ajaxRegister():
$data = [
    'email' => sanitize_email($_POST['email'] ?? ''),
    'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
    'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
    'consent_terms' => !empty($_POST['consent_terms']),
    'consent_privacy' => !empty($_POST['consent_privacy']),
    'profile_type' => sanitize_text_field($_POST['profile_type'] ?? ''),
];
```

This passes `profile_type` through to `RegistrationService::register()`, which passes `$data` to `do_action('ntdst_auth_registration_complete', $userId, $data)`. `ProfileTypeService::onRegistrationComplete()` picks it up from there.

- [ ] **Step 2: Add profile_type field to register.php**

After the last_name field block (line 56) and before the email field (line 58), add:

```php
<?php
// Profile type selection (only if types are configured)
$profileTypes = [];
if (class_exists(\Stride\Modules\User\ProfileTypeService::class)) {
    $profileTypeService = ntdst_get(\Stride\Modules\User\ProfileTypeService::class);
    $profileTypes = $profileTypeService->getTypes();
}
if (!empty($profileTypes)):
?>
<div class="uk-margin">
    <label class="uk-form-label" for="profile_type"><?php esc_html_e('Ik ben een...', 'ntdst-auth'); ?> *</label>
    <select class="uk-select" id="profile_type" x-model="profileType" required>
        <option value=""><?php esc_html_e('Selecteer je profieltype...', 'ntdst-auth'); ?></option>
        <?php foreach ($profileTypes as $type): ?>
            <option value="<?php echo esc_attr($type['slug']); ?>">
                <?php echo esc_html($type['label']); ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>
```

Insert this block between the `last_name` endif (line 56) and the `email` div (line 58).

- [ ] **Step 3: Update auth.js to send profile_type**

In `authRegister()` function (auth.js), add `profileType` property and include it in the POST data:

Add `profileType: '',` after line 101 (`email: '',`).

In the `register()` method, add `profile_type: this.profileType` to the POST data object (after `consent_privacy` line):

```javascript
const response = await this.post('ntdst_auth_register', {
    first_name: this.firstName,
    last_name: this.lastName,
    email: this.email,
    consent_terms: this.consentTerms ? '1' : '',
    consent_privacy: this.consentPrivacy ? '1' : '',
    profile_type: this.profileType
});
```

Also add client-side validation before the consent check:

```javascript
// In register(), before the consent check:
if (this.profileType === '' && document.getElementById('profile_type')) {
    this.error = true;
    this.message = 'Kies een profieltype.';
    this.loading = false;
    return;
}
```

- [ ] **Step 4: Test registration flow**

1. Add a profile type via admin settings
2. Navigate to registration page
3. Verify the dropdown appears
4. Register a new user with a profile type selected
5. Check usermeta: `ddev exec wp user meta get <user_id> _stride_profile_type`

Expected: `['selected-slug']`

- [ ] **Step 5: Test graceful degradation (no types configured)**

1. Delete all profile types from admin settings
2. Navigate to registration page
3. Verify no dropdown appears
4. Register a new user
5. Verify registration succeeds without profile type

- [ ] **Step 6: Commit**

```bash
git add web/app/plugins/ntdst-auth/src/Handlers/AuthHandler.php web/app/plugins/ntdst-auth/assets/js/auth.js web/app/plugins/ntdst-auth/templates/pages/register.php
git commit -m "feat(auth): add profile type selection to registration form"
```

---

## Task 5: Dashboard Profile Tab Integration

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Handlers/ProfileHandler.php:47-51`
- Modify: `web/app/themes/stridence/templates/dashboard/tab-profiel.php`

- [ ] **Step 1: Add profile_type handler to ProfileHandler**

In `ProfileHandler.php`, add `'profile_type'` case to the match expression (line 47-51):

```php
return match ($formType) {
    'billing' => $this->updateBilling($userId, $params),
    'notifications' => $this->updateNotifications($userId, $params),
    'profile_type' => $this->updateProfileType($userId, $params),
    default => $this->updatePersonal($userId, $params),
};
```

Add the new private method after `updateNotifications()`:

```php
/**
 * Update user's profile type.
 *
 * @param int $userId User ID
 * @param array<string, mixed> $params Request parameters
 * @return array<string, mixed>|WP_Error
 */
private function updateProfileType(int $userId, array $params): array|WP_Error
{
    $slug = sanitize_text_field($params['profile_type'] ?? '');

    if (empty($slug)) {
        return new WP_Error('missing_type', __('Kies een profieltype.', 'stride'));
    }

    $service = ntdst_get(\Stride\Modules\User\ProfileTypeService::class);

    if (!$service->setUserType($userId, $slug)) {
        return new WP_Error('invalid_type', __('Ongeldig profieltype.', 'stride'));
    }

    ntdst_log('profile')->info('Profile type updated', [
        'user_id' => $userId,
        'profile_type' => $slug,
    ]);

    return [
        'success' => true,
        'message' => __('Profieltype bijgewerkt.', 'stride'),
    ];
}
```

- [ ] **Step 2: Add profile type section to tab-profiel.php**

Insert a new section after the Personal Information `</section>` (line 153) and before the Billing section (line 155).

Get profile type data in the PHP header section (after line 27):

```php
// Profile type data
$profileTypeService = ntdst_get(\Stride\Modules\User\ProfileTypeService::class);
$profileTypes = $profileTypeService->getTypes();
$currentProfileType = $profileTypeService->getUserType($user_id);
```

Then insert this section between Personal and Billing:

```php
<?php if (!empty($profileTypes)): ?>
<!-- Profile Type -->
<section x-data="inlineEditSection({
             action: 'stride_update_profile',
             params: { form_type: 'profile_type' },
             fields: <?php echo esc_attr(json_encode([
                 'profile_type' => $currentProfileType['slug'] ?? '',
             ])); ?>
         })">
    <div class="flex items-center justify-between mb-3">
        <h3 class="dash-subheading flex items-center gap-2">
            <?php echo stridence_icon('users', 'w-4 h-4 text-primary'); ?>
            <?php esc_html_e('Profieltype', 'stridence'); ?>
        </h3>
        <template x-if="!editing">
            <button type="button" @click="startEdit()" class="text-sm text-primary hover:underline">
                <?php echo stridence_icon('edit-2', 'w-3.5 h-3.5 inline mr-1'); ?>
                <?php esc_html_e('Bewerken', 'stridence'); ?>
            </button>
        </template>
    </div>

    <div class="bg-surface-card rounded-xl border border-border shadow-sm p-4">
        <!-- Display mode -->
        <dl x-show="!editing" class="grid grid-cols-1 gap-4">
            <div class="flex items-center gap-2">
                <dt class="text-xs text-text-muted mb-0.5"><?php esc_html_e('Profieltype', 'stridence'); ?></dt>
                <dd class="text-sm font-medium text-text">
                    <?php if ($currentProfileType): ?>
                        <span class="inline-block w-2.5 h-2.5 rounded-full mr-1 align-middle"
                              style="background-color: <?php echo esc_attr($currentProfileType['color']); ?>"></span>
                        <?php echo esc_html($currentProfileType['label']); ?>
                    <?php else: ?>
                        <span class="text-text-muted"><?php esc_html_e('Niet ingesteld', 'stridence'); ?></span>
                    <?php endif; ?>
                </dd>
            </div>
        </dl>

        <!-- Edit mode -->
        <div x-show="editing" x-transition class="space-y-4">
            <div>
                <label class="input-label"><?php esc_html_e('Profieltype', 'stridence'); ?></label>
                <select x-model="fields.profile_type" class="input-select">
                    <option value=""><?php esc_html_e('Selecteer je profieltype...', 'stridence'); ?></option>
                    <?php foreach ($profileTypes as $type): ?>
                        <option value="<?php echo esc_attr($type['slug']); ?>">
                            <?php echo esc_html($type['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Error -->
            <div x-show="error" class="p-2 bg-error/10 rounded text-sm text-error" x-text="error"></div>

            <!-- Actions -->
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="cancelEdit()" class="btn-secondary btn-sm">
                    <?php esc_html_e('Annuleren', 'stridence'); ?>
                </button>
                <button type="button" @click="saveEdit()" :disabled="saving" class="btn-primary btn-sm">
                    <span x-show="!saving"><?php esc_html_e('Opslaan', 'stridence'); ?></span>
                    <span x-show="saving" class="flex items-center gap-1">
                        <span class="spinner w-3 h-3"></span>
                        <?php esc_html_e('Opslaan...', 'stridence'); ?>
                    </span>
                </button>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>
```

- [ ] **Step 3: Test dashboard profile type edit**

1. Log in as a test user
2. Navigate to Dashboard → Profiel tab
3. Verify profile type section shows (with types configured)
4. Click "Bewerken", select a type, click "Opslaan"
5. Verify toast success message
6. Refresh page — verify selection persists

- [ ] **Step 4: Test dashboard with no types configured**

1. Delete all profile types from admin
2. Refresh dashboard profile tab
3. Verify profile type section is hidden

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Handlers/ProfileHandler.php web/app/themes/stridence/templates/dashboard/tab-profiel.php
git commit -m "feat(dashboard): add profile type editing to user profile tab"
```

---

## Task 6: Verify and Polish

- [ ] **Step 1: Run all unit tests**

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Expected: ALL PASS

- [ ] **Step 2: End-to-end walkthrough**

Full flow test:
1. Admin: Create 2-3 profile types (Settings → Profieltypes)
2. Admin: Save → verify persistence
3. Admin: Edit a type's label and color → save → verify
4. Admin: Delete a type with 0 users → confirm → save
5. New user: Register → verify dropdown shows → select type → submit
6. Activate account via Mailpit link
7. Login → Dashboard → Profiel → verify profile type shows
8. Change profile type → save → verify

- [ ] **Step 3: Verify graceful degradation**

1. Delete all profile types from admin
2. Visit registration page → verify no dropdown
3. Visit dashboard profile → verify no profile type section
4. Re-add a profile type → verify both reappear

- [ ] **Step 4: Check usermeta storage**

```bash
ddev exec wp user meta get 1 _stride_profile_type
```

Expected: array with selected slug, e.g., `['apotheker']`

- [ ] **Step 5: Final commit (if any polish needed)**

```bash
git add -A && git commit -m "polish(profile-types): final cleanup and fixes"
```
