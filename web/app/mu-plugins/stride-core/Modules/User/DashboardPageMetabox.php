<?php

declare(strict_types=1);

namespace Stride\Modules\User;

/**
 * T10 — Dashboard "Voor jou" curated-link metabox on the WP `page` CPT
 * (concept C: additive dashboard curation, NOT access control).
 *
 * Adds a "Toon op dashboard voor profieltypes" metabox to `page`: a set of
 * profile-type slug checkboxes sourced from ProfileTypeService::getTypes().
 * On save_post_page the chosen slugs persist to the page meta
 * `_stride_dashboard_profiletypes` (a hand-chosen literal key — the `page` CPT
 * is NOT ntdst_data-registered, so no `_ntdst_` prefix applies; the SAME literal
 * is used on this save side (T10) and the dashboard read side (T11)).
 *
 * SECURITY (threat model M5, flow H): the save is a WP-security surface —
 * nonce (wp_verify_nonce) + cap (current_user_can('edit_page', $id)) +
 * slug-allowlist sanitize (unknown slugs dropped; sanitize_key on each). The
 * meta is registered via register_post_meta('page', ...) so it round-trips in
 * the block editor.
 *
 * Boot: a plain final class (autowired constructor dep: ProfileTypeService),
 * eager-booted in stride-core.php's ntdst/features_ready block alongside the
 * other self-hooking User-module classes (DashboardShortcode, UserDashboard
 * Service). It registers save_post_page + init (registerMeta) + the metabox
 * render in its constructor — it MUST hook at boot, so it is not an on-demand
 * collaborator like ProfileTypePolicy.
 */
final class DashboardPageMetabox
{
    /** Nonce action + field for the page dashboard-profiletypes metabox save. */
    public const NONCE_SAVE = 'stride_save_dashboard_profiletypes';
    public const NONCE_FIELD = 'stride_dashboard_profiletypes_nonce';

    /** The hand-chosen page-meta key. NO `_ntdst_` prefix (page is not ntdst_data). */
    public const META_KEY = '_stride_dashboard_profiletypes';

    /** The checkbox field name posted from the metabox render. */
    private const FIELD = 'dashboard_profiletypes';

    public function __construct(
        private readonly ProfileTypeService $profileTypes,
    ) {
        $this->init();
    }

    private function init(): void
    {
        add_action('init', [$this, 'registerMeta']);

        if (!is_admin()) {
            return;
        }

        add_action('add_meta_boxes_page', [$this, 'registerMetabox']);
        add_action('save_post_page', [$this, 'handleSave'], 10, 2);
    }

    /**
     * register_post_meta('page', '_stride_dashboard_profiletypes', ...) so the
     * key round-trips in the block editor and is protected appropriately.
     */
    public function registerMeta(): void
    {
        register_post_meta('page', self::META_KEY, [
            'type' => 'array',
            'description' => __('Profieltypes waarvoor deze pagina op het dashboard verschijnt.', 'stride'),
            'single' => true,
            'show_in_rest' => [
                'schema' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'sanitize_callback' => fn($value): array => $this->sanitizeSlugs($value),
            'auth_callback' => static fn(bool $allowed, string $metaKey, int $postId): bool
                => current_user_can('edit_page', $postId),
        ]);
    }

    /**
     * Adds the "Toon op dashboard voor profieltypes" metabox to the page editor.
     * Sourced from ProfileTypeService::getTypes(), pre-filled from stored meta.
     * The RED tests drive handleSave directly, but a real admin needs this render;
     * verified at shake-out.
     */
    public function registerMetabox(): void
    {
        add_meta_box(
            'stride_dashboard_profiletypes',
            __('Toon op dashboard voor profieltypes', 'stride'),
            [$this, 'renderMetabox'],
            'page',
            'side',
            'default',
        );
    }

    public function renderMetabox(\WP_Post $post): void
    {
        $types = $this->profileTypes->getTypes();

        $stored = get_post_meta($post->ID, self::META_KEY, true);
        $selected = is_array($stored) ? array_map('strval', $stored) : [];

        wp_nonce_field(self::NONCE_SAVE, self::NONCE_FIELD);
        ?>
        <p class="description">
            <?php esc_html_e('Kies de profieltypes die deze pagina onder "Voor jou" op hun dashboard zien.', 'stride'); ?>
        </p>
        <?php if (empty($types)): ?>
            <p><em><?php esc_html_e('Er zijn nog geen profieltypes gedefinieerd.', 'stride'); ?></em></p>
        <?php else: ?>
            <?php foreach ($types as $type): ?>
                <?php
                $slug = is_array($type) ? (string) ($type['slug'] ?? '') : '';
                if ($slug === '') {
                    continue;
                }
                $label = is_array($type) ? (string) ($type['label'] ?? $slug) : $slug;
                ?>
                <label style="display:block; margin-bottom:4px;">
                    <input type="checkbox"
                        name="<?php echo esc_attr(self::FIELD); ?>[]"
                        value="<?php echo esc_attr($slug); ?>"
                        <?php checked(in_array($slug, $selected, true)); ?> />
                    <?php echo esc_html($label); ?>
                </label>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php
    }

    /**
     * save_post_page handler — persist the allowlisted profile-type slugs to
     * `_stride_dashboard_profiletypes`. Nonce + edit_page cap + slug allowlist.
     *
     * @param int      $postId The page being saved.
     * @param \WP_Post $post   The page post object.
     */
    public function handleSave(int $postId, \WP_Post $post): void
    {
        // Only the page CPT.
        if ($post->post_type !== 'page') {
            return;
        }

        // Skip autosave/revisions — nothing to persist from the editor UI.
        if (wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Nonce (M5).
        if (!isset($_POST[self::NONCE_FIELD])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD])), self::NONCE_SAVE)) {
            return;
        }

        // Capability (M5) — the page's edit cap, not just the nonce.
        if (!current_user_can('edit_page', $postId)) {
            return;
        }

        $posted = $_POST[self::FIELD] ?? [];
        $clean = $this->sanitizeSlugs($posted);

        // Always store an array (empty when cleared) so the read side gets a
        // consistent array type and clearing reads back as an empty array
        // rather than the '' that delete_post_meta would leave.
        update_post_meta($postId, self::META_KEY, $clean);
    }

    /**
     * Sanitize a raw posted value into a clean, de-duplicated array of allowlisted
     * profile-type slugs: sanitize_key each, drop anything not in
     * ProfileTypeService::getTypes() (M5 allowlist).
     *
     * @return array<int, string>
     */
    private function sanitizeSlugs(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $allowed = $this->allowedSlugs();
        $clean = [];

        foreach ($raw as $value) {
            if (!is_string($value)) {
                continue;
            }

            $slug = sanitize_key($value);

            if ($slug === '' || !in_array($slug, $allowed, true) || in_array($slug, $clean, true)) {
                continue;
            }

            $clean[] = $slug;
        }

        return $clean;
    }

    /** @return array<int, string> the known profile-type slugs (allowlist source) */
    private function allowedSlugs(): array
    {
        return array_values(array_filter(array_map(
            static fn($type): string => is_array($type) ? (string) ($type['slug'] ?? '') : '',
            $this->profileTypes->getTypes(),
        )));
    }
}
