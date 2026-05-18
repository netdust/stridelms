<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

use NTDST_Service_Meta;
use WP_Error;
use WP_Post;

/**
 * Duplicates a vad_edition: copies all meta (Rule A) with a small explicit
 * reset list (Rule B), then clones child sessions with dates reset to today.
 *
 * Registrations, attendance, notifications, audit-log entries are never
 * touched. Edition-level `documents` meta is dropped (course-level documents
 * survive via the preserved course link).
 */
final class EditionDuplicator implements NTDST_Service_Meta
{
    /**
     * Meta keys overwritten on the copy. Keep this list short and explicit.
     * Any meta key NOT in this list is preserved verbatim — this is what
     * protects future enrollment-form fields from being silently lost.
     */
    private const META_RESET = [
        'notes'          => [],
        'documents'      => [],
        'selection_open' => false,
    ];

    /**
     * Meta keys removed from the copy entirely (stale caches, etc.).
     */
    private const META_UNSET = [
        '_enrollment_count',
    ];

    public static function metadata(): array
    {
        return [
            'name'        => 'Edition Duplicator',
            'description' => 'Duplicates an edition (post + meta + sessions, with safe resets)',
            'priority'    => 50,
        ];
    }

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_filter('post_row_actions', [$this, 'addDuplicateRowAction'], 10, 2);
        add_action('admin_action_stride_duplicate_edition', [$this, 'handleDuplicate']);
        add_action('admin_notices', [$this, 'renderDuplicateNotice']);
    }

    public function addDuplicateRowAction(array $actions, WP_Post $post): array
    {
        if ($post->post_type !== EditionCPT::POST_TYPE) {
            return $actions;
        }

        if (!current_user_can('edit_post', $post->ID)) {
            return $actions;
        }

        $url = wp_nonce_url(
            admin_url('admin.php?action=stride_duplicate_edition&edition_id=' . $post->ID),
            'stride_duplicate_edition_' . $post->ID
        );

        $actions['stride_duplicate'] = sprintf(
            '<a href="%s" aria-label="%s">%s</a>',
            esc_url($url),
            esc_attr__('Dupliceer deze editie', 'stride'),
            esc_html__('Dupliceren', 'stride')
        );

        return $actions;
    }

    public function handleDuplicate(): void
    {
        $sourceId = isset($_GET['edition_id']) ? (int) $_GET['edition_id'] : 0;

        if ($sourceId <= 0) {
            $this->redirectToList('missing_id');
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'stride_duplicate_edition_' . $sourceId)) {
            $this->redirectToList('invalid_nonce');
        }

        if (!current_user_can('edit_post', $sourceId)) {
            $this->redirectToList('forbidden');
        }

        $newId = $this->duplicate($sourceId);

        if (is_wp_error($newId)) {
            $this->redirectToList('duplicate_failed');
        }

        $editUrl = get_edit_post_link($newId, 'raw');
        wp_safe_redirect($editUrl ?: admin_url('edit.php?post_type=vad_edition'));
        exit;
    }

    private function redirectToList(string $notice): never
    {
        $url = add_query_arg(
            ['post_type' => 'vad_edition', 'stride_notice' => $notice],
            admin_url('edit.php')
        );
        wp_safe_redirect($url);
        exit;
    }

    public function renderDuplicateNotice(): void
    {
        if (empty($_GET['stride_notice'])) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== EditionCPT::POST_TYPE) {
            return;
        }

        $notice = sanitize_key((string) $_GET['stride_notice']);
        $messages = [
            'missing_id'       => __('Geen editie geselecteerd om te dupliceren.', 'stride'),
            'invalid_nonce'    => __('Beveiligingscontrole mislukt. Probeer opnieuw.', 'stride'),
            'forbidden'        => __('Geen toestemming om deze editie te dupliceren.', 'stride'),
            'duplicate_failed' => __('Dupliceren mislukt. Controleer de bron-editie.', 'stride'),
        ];

        if (!isset($messages[$notice])) {
            return;
        }

        printf(
            '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
            esc_html($messages[$notice])
        );
    }

    /**
     * @return int|WP_Error new edition ID on success
     */
    public function duplicate(int $sourceEditionId): int|WP_Error
    {
        $source = get_post($sourceEditionId);

        if (!$source instanceof WP_Post || $source->post_type !== EditionCPT::POST_TYPE) {
            return new WP_Error(
                'not_found',
                __('Bron-editie niet gevonden.', 'stride')
            );
        }

        // Insert the copy as a draft. WP auto-generates a unique slug.
        $newId = wp_insert_post([
            'post_type'    => EditionCPT::POST_TYPE,
            'post_status'  => 'draft',
            'post_title'   => $source->post_title . ' (kopie)',
            'post_content' => $source->post_content,
            'post_excerpt' => $source->post_excerpt,
            'post_author'  => get_current_user_id() ?: $source->post_author,
        ], true);

        if (is_wp_error($newId)) {
            return $newId;
        }

        // Rule A — copy ALL meta from source verbatim.
        $allMeta = get_post_meta($sourceEditionId);
        foreach ($allMeta as $key => $values) {
            foreach ($values as $value) {
                add_post_meta($newId, $key, $value);
            }
        }

        // Rule B — overwrite reset keys with their reset value (_ntdst_ prefixed).
        foreach (self::META_RESET as $field => $resetValue) {
            update_post_meta($newId, '_ntdst_' . $field, $resetValue);
        }

        // Rule B — remove unset keys entirely (already prefixed in the const).
        foreach (self::META_UNSET as $key) {
            delete_post_meta($newId, $key);
        }

        // Copy taxonomies (stride_format etc.) so the copy keeps its category facets.
        $taxonomies = get_object_taxonomies($source->post_type);
        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_object_terms($sourceEditionId, $taxonomy, ['fields' => 'ids']);
            if (!is_wp_error($terms) && !empty($terms)) {
                wp_set_object_terms($newId, $terms, $taxonomy);
            }
        }

        // Sessions — one copy per source session, date reset to today.
        $this->copySessions($sourceEditionId, $newId);

        return (int) $newId;
    }

    private function copySessions(int $sourceEditionId, int $newEditionId): void
    {
        $today = date('Y-m-d');

        $sourceSessions = get_posts([
            'post_type'   => SessionCPT::POST_TYPE,
            'post_status' => 'any',
            'meta_key'    => '_ntdst_edition_id',
            'meta_value'  => $sourceEditionId,
            'numberposts' => -1,
        ]);

        foreach ($sourceSessions as $session) {
            $newSessionId = wp_insert_post([
                'post_type'    => SessionCPT::POST_TYPE,
                'post_status'  => $session->post_status,
                'post_title'   => $session->post_title,
                'post_content' => $session->post_content,
                'post_excerpt' => $session->post_excerpt,
                'post_parent'  => $newEditionId,
            ], true);

            if (is_wp_error($newSessionId)) {
                continue;
            }

            // Copy every meta key from the source session, then override edition link + date.
            $sessionMeta = get_post_meta($session->ID);
            foreach ($sessionMeta as $key => $values) {
                foreach ($values as $value) {
                    add_post_meta($newSessionId, $key, $value);
                }
            }
            update_post_meta($newSessionId, '_ntdst_edition_id', $newEditionId);
            update_post_meta($newSessionId, '_ntdst_date', $today);
        }
    }
}
