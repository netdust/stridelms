<?php

declare(strict_types=1);

namespace Stride\Admin;

use Stride\Infrastructure\AbstractService;

/**
 * Stride Settings Service
 *
 * Manages configurable URL slugs and other plugin settings.
 * Settings page under Stride admin menu.
 */
class StrideSettingsService extends AbstractService
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

    /**
     * {@inheritDoc}
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Stride Settings',
            'description' => 'Configurable URL slugs and plugin settings',
            'admin_only' => true,
            'enabled' => true,
            'priority' => 3, // Load early, before CPTs
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function getConfigSlug(): string
    {
        return 'settings';
    }

    /**
     * {@inheritDoc}
     */
    protected function init(): void
    {
        add_action('admin_menu', [$this, 'registerSettingsPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('update_option_' . self::OPTION_URL_SLUGS, [$this, 'onSlugsUpdated'], 10, 2);
    }

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
     * Register settings fields.
     */
    public function registerSettings(): void
    {
        register_setting(
            'stride_settings_group',
            self::OPTION_URL_SLUGS,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitizeSlugs'],
                'default' => self::DEFAULT_SLUGS,
            ]
        );

        add_settings_section(
            'stride_url_slugs_section',
            'URL Slugs',
            [$this, 'renderUrlSlugsSection'],
            self::SETTINGS_SLUG
        );

        add_settings_field(
            'trajectory_slug',
            'Trajecten URL',
            [$this, 'renderTrajectorySlugField'],
            self::SETTINGS_SLUG,
            'stride_url_slugs_section'
        );

        add_settings_field(
            'edition_slug',
            'Vormingen URL',
            [$this, 'renderEditionSlugField'],
            self::SETTINGS_SLUG,
            'stride_url_slugs_section'
        );
    }

    /**
     * Sanitize slug values.
     */
    public function sanitizeSlugs(array $input): array
    {
        $sanitized = [];

        $sanitized['trajectory'] = isset($input['trajectory'])
            ? sanitize_title($input['trajectory'])
            : self::DEFAULT_SLUGS['trajectory'];

        $sanitized['edition'] = isset($input['edition'])
            ? sanitize_title($input['edition'])
            : self::DEFAULT_SLUGS['edition'];

        return $sanitized;
    }

    /**
     * Flush rewrite rules when slugs are updated.
     */
    public function onSlugsUpdated($old_value, $new_value): void
    {
        // Schedule rewrite rules flush on next page load
        delete_option('rewrite_rules');
    }

    /**
     * Render URL slugs section description.
     */
    public function renderUrlSlugsSection(): void
    {
        echo '<p>Configureer de URL slugs voor trajecten en vormingen. Wijzigingen worden direct toegepast.</p>';
        echo '<p><strong>Let op:</strong> Na wijzigen van URL slugs kan het nodig zijn om de permalinks opnieuw op te slaan (Instellingen → Permalinks → Opslaan).</p>';
    }

    /**
     * Render trajectory slug field.
     */
    public function renderTrajectorySlugField(): void
    {
        $slug = self::getTrajectorySlug();
        printf(
            '<input type="text" name="%s[trajectory]" value="%s" class="regular-text" />',
            esc_attr(self::OPTION_URL_SLUGS),
            esc_attr($slug)
        );
        printf(
            '<p class="description">URL: %s/<strong>%s</strong>/traject-naam/</p>',
            esc_url(home_url()),
            esc_html($slug)
        );
    }

    /**
     * Render edition slug field.
     */
    public function renderEditionSlugField(): void
    {
        $slug = self::getEditionSlug();
        printf(
            '<input type="text" name="%s[edition]" value="%s" class="regular-text" />',
            esc_attr(self::OPTION_URL_SLUGS),
            esc_attr($slug)
        );
        printf(
            '<p class="description">URL: %s/<strong>%s</strong>/editie-naam/</p>',
            esc_url(home_url()),
            esc_html($slug)
        );
    }

    /**
     * Render settings page.
     */
    public function renderSettingsPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        // Check if settings were saved
        if (isset($_GET['settings-updated'])) {
            add_settings_error(
                'stride_messages',
                'stride_message',
                'Instellingen opgeslagen. Vergeet niet de permalinks opnieuw op te slaan als je URL slugs hebt gewijzigd.',
                'updated'
            );
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php settings_errors('stride_messages'); ?>

            <form action="options.php" method="post">
                <?php
                settings_fields('stride_settings_group');
                do_settings_sections(self::SETTINGS_SLUG);
                submit_button('Opslaan');
                ?>
            </form>

            <hr />

            <h2>Rewrite Rules</h2>
            <p>
                <a href="<?php echo esc_url(admin_url('options-permalink.php')); ?>" class="button">
                    Permalinks opnieuw opslaan
                </a>
            </p>
        </div>
        <?php
    }
}
