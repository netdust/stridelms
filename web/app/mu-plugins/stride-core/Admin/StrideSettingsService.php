<?php

declare(strict_types=1);

namespace Stride\Admin;

use Stride\Modules\User\ProfileTypeService;
use WP_Error;

/**
 * Stride Settings Hub
 *
 * Alpine.js tabbed settings page with AJAX save per tab.
 * Tabs: "Algemeen" (URL slugs), "Profieltypes" (profile type CRUD).
 *
 * Plain class — owned by EditionService (slugs affect CPT registration).
 * Static methods remain available for CPT slug lookups.
 */
class StrideSettingsService
{
    /** Option name for URL slugs */
    private const OPTION_URL_SLUGS = 'stride_url_slugs';

    /** Menu slug for settings page */
    private const SETTINGS_SLUG = 'stride-settings';

    /** Capability required */
    private const CAPABILITY = 'manage_options';

    /** Default URL slugs */
    private const DEFAULT_SLUGS = [
        'trajectory' => 'trajecten',
        'edition' => 'vormingen',
    ];

    /** Option name for company details */
    private const OPTION_COMPANY = 'stride_company_details';

    /** Default company details */
    private const DEFAULT_COMPANY = [
        'name' => '',
        'address' => '',
        'postal_code' => '',
        'city' => '',
        'country' => 'België',
        'vat' => '',
        'email' => '',
        'phone' => '',
        'bank_account' => '',
        'logo' => '',
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

    // =========================================================================
    // STATIC SLUG ACCESSORS (unchanged — used by CPT registration)
    // =========================================================================

    /**
     * Get the trajectory URL slug.
     *
     * Static method for use during CPT registration.
     */
    public static function getTrajectorySlug(): string
    {
        $slugs = get_option(self::OPTION_URL_SLUGS, self::DEFAULT_SLUGS);

        return $slugs['trajectory'] ?? self::DEFAULT_SLUGS['trajectory'];
    }

    /**
     * Get the edition/course URL slug.
     *
     * Static method for use during CPT registration.
     */
    public static function getEditionSlug(): string
    {
        $slugs = get_option(self::OPTION_URL_SLUGS, self::DEFAULT_SLUGS);

        return $slugs['edition'] ?? self::DEFAULT_SLUGS['edition'];
    }

    /**
     * Get all URL slugs.
     */
    public static function getAllSlugs(): array
    {
        return get_option(self::OPTION_URL_SLUGS, self::DEFAULT_SLUGS);
    }

    /**
     * Get company details.
     *
     * @return array{name: string, address: string, postal_code: string, city: string, country: string, vat: string, email: string, phone: string, bank_account: string}
     */
    public static function getCompanyDetails(): array
    {
        $details = get_option(self::OPTION_COMPANY, self::DEFAULT_COMPANY);

        return array_merge(self::DEFAULT_COMPANY, is_array($details) ? $details : []);
    }

    // =========================================================================
    // ADMIN PAGE
    // =========================================================================

    /**
     * Register settings submenu page under Stride menu.
     */
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

    /**
     * Enqueue Alpine.js, settings CSS and JS on settings page only.
     */
    public function enqueueAssets(string $hook): void
    {
        if (!str_contains($hook, self::SETTINGS_SLUG)) {
            return;
        }

        // WP Media Library (for logo upload)
        wp_enqueue_media();

        // Alpine.js from CDN (deferred)
        wp_enqueue_script(
            'alpinejs',
            'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js',
            [],
            '3',
            ['strategy' => 'defer']
        );

        $basePath = dirname(__DIR__);
        $cssFile = $basePath . '/assets/css/admin/settings.css';
        $jsFile = $basePath . '/assets/js/admin/settings.js';

        if (file_exists($cssFile)) {
            wp_enqueue_style(
                'stride-settings',
                plugins_url('assets/css/admin/settings.css', $basePath . '/stride-core.php'),
                [],
                (string) filemtime($cssFile)
            );
        }

        if (file_exists($jsFile)) {
            // Load settings.js BEFORE Alpine so the component function is defined
            wp_enqueue_script(
                'stride-settings',
                plugins_url('assets/js/admin/settings.js', $basePath . '/stride-core.php'),
                [],
                (string) filemtime($jsFile),
                false // in <head>, not footer
            );
        }

        wp_localize_script('stride-settings', 'strideSettings', $this->getLocalizedData());
    }

    /**
     * Render settings page shell (capability check + template include).
     */
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

    // =========================================================================
    // AJAX SAVE HANDLER
    // =========================================================================

    /**
     * Handle AJAX settings save via ntdst/api_data filter.
     *
     * @param mixed $data Existing filter data (unused)
     * @param array<string, mixed> $params Request parameters (includes 'tab')
     * @return array<string, mixed>|WP_Error
     */
    public function handleSaveSettings(mixed $data, array $params): array|WP_Error
    {
        if (!current_user_can(self::CAPABILITY)) {
            return new WP_Error('forbidden', __('Onvoldoende rechten.', 'stride'));
        }

        $tab = sanitize_text_field($params['tab'] ?? '');

        return match ($tab) {
            'general' => $this->saveGeneralSettings($params),
            'company' => $this->saveCompanySettings($params),
            'profile-types' => $this->saveProfileTypes($params),
            default => new WP_Error('invalid_tab', __('Onbekend tabblad.', 'stride')),
        };
    }

    /**
     * Save general (URL slug) settings.
     *
     * @return array{message: string}
     */
    private function saveGeneralSettings(array $params): array
    {
        $slugs = [
            'trajectory' => !empty($params['trajectory_slug'])
                ? sanitize_title($params['trajectory_slug'])
                : self::DEFAULT_SLUGS['trajectory'],
            'edition' => !empty($params['edition_slug'])
                ? sanitize_title($params['edition_slug'])
                : self::DEFAULT_SLUGS['edition'],
        ];

        update_option(self::OPTION_URL_SLUGS, $slugs);

        // Flush rewrite rules so new slugs take effect
        delete_option('rewrite_rules');

        return ['message' => 'Instellingen opgeslagen.'];
    }

    /**
     * Save company details.
     *
     * @return array{message: string}
     */
    private function saveCompanySettings(array $params): array
    {
        $details = [
            'name'         => sanitize_text_field($params['name'] ?? ''),
            'address'      => sanitize_text_field($params['address'] ?? ''),
            'postal_code'  => sanitize_text_field($params['postal_code'] ?? ''),
            'city'         => sanitize_text_field($params['city'] ?? ''),
            'country'      => sanitize_text_field($params['country'] ?? self::DEFAULT_COMPANY['country']),
            'vat'          => sanitize_text_field($params['vat'] ?? ''),
            'email'        => sanitize_email($params['email'] ?? ''),
            'phone'        => sanitize_text_field($params['phone'] ?? ''),
            'bank_account' => sanitize_text_field($params['bank_account'] ?? ''),
            'logo'         => esc_url_raw($params['logo'] ?? ''),
        ];

        update_option(self::OPTION_COMPANY, $details);

        return ['message' => 'Bedrijfsgegevens opgeslagen.'];
    }

    /**
     * Save profile types.
     *
     * @return array{message: string, types: array}|WP_Error
     */
    private function saveProfileTypes(array $params): array|WP_Error
    {
        $rawTypes = $params['types'] ?? '[]';

        if (is_string($rawTypes)) {
            $decoded = json_decode($rawTypes, true);
            if (!is_array($decoded)) {
                return new WP_Error('invalid_json', __('Ongeldige JSON-data.', 'stride'));
            }
            $rawTypes = $decoded;
        }

        if (!is_array($rawTypes)) {
            return new WP_Error('invalid_data', __('Ongeldige data.', 'stride'));
        }

        $types = $this->sanitizeProfileTypes($rawTypes);

        update_option('stride_profile_types', $types);

        // Return types with fresh user counts
        $profileService = ntdst_get(ProfileTypeService::class);
        $typesWithCounts = array_map(function (array $type) use ($profileService): array {
            $type['userCount'] = $profileService->countUsersWithType($type['slug']);
            return $type;
        }, $types);

        return [
            'message' => 'Profieltypes opgeslagen.',
            'types' => $typesWithCounts,
        ];
    }

    /**
     * Sanitize profile types array.
     *
     * Deduplicates slugs, sanitizes fields, skips entries without label.
     *
     * @param array<int, array<string, mixed>> $types
     * @return array<int, array{slug: string, label: string, description: string, color: string, icon: string, order: int}>
     */
    private function sanitizeProfileTypes(array $types): array
    {
        $sanitized = [];
        $seenSlugs = [];

        foreach ($types as $index => $type) {
            $label = trim(sanitize_text_field($type['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $slug = !empty($type['slug'])
                ? sanitize_title($type['slug'])
                : sanitize_title($label);

            // Deduplicate slugs
            $baseSlug = $slug;
            $counter = 2;
            while (in_array($slug, $seenSlugs, true)) {
                $slug = $baseSlug . '-' . $counter;
                $counter++;
            }
            $seenSlugs[] = $slug;

            $sanitized[] = [
                'slug' => $slug,
                'label' => $label,
                'description' => sanitize_text_field($type['description'] ?? ''),
                'color' => sanitize_hex_color($type['color'] ?? '#3B82F6') ?: '#3B82F6',
                'icon' => sanitize_text_field($type['icon'] ?? 'users'),
                'order' => (int) ($type['order'] ?? $index),
            ];
        }

        // Sort by order
        usort($sanitized, fn(array $a, array $b) => $a['order'] <=> $b['order']);

        return $sanitized;
    }

    // =========================================================================
    // LOCALIZED DATA
    // =========================================================================

    /**
     * Build data passed to JS via wp_localize_script.
     *
     * @return array{general: array, profileTypes: array}
     */
    private function getLocalizedData(): array
    {
        $slugs = self::getAllSlugs();

        // Profile types with user counts
        $profileService = ntdst_get(ProfileTypeService::class);
        $types = $profileService->getTypes();

        $typesWithCounts = array_map(function (array $type) use ($profileService): array {
            $type['userCount'] = $profileService->countUsersWithType($type['slug']);
            return $type;
        }, $types);

        // Available icons from theme
        $iconDir = get_theme_root() . '/' . get_stylesheet() . '/icons';
        $availableIcons = [];
        if (is_dir($iconDir)) {
            $files = glob($iconDir . '/*.svg');
            if ($files) {
                $availableIcons = array_map(
                    fn(string $file) => basename($file, '.svg'),
                    $files
                );
                sort($availableIcons);
            }
        }

        return [
            'general' => [
                'trajectory_slug' => $slugs['trajectory'] ?? self::DEFAULT_SLUGS['trajectory'],
                'edition_slug' => $slugs['edition'] ?? self::DEFAULT_SLUGS['edition'],
                'siteUrl' => home_url(),
            ],
            'company' => self::getCompanyDetails(),
            'profileTypes' => [
                'types' => array_values($typesWithCounts),
                'availableIcons' => $availableIcons,
            ],
        ];
    }
}
