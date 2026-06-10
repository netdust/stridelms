<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

use NTDST_Service_Meta;
use WP_Error;
use WP_Post;

/**
 * Duplicates a vad_edition: copies registered fields via the Edition /
 * Session repositories, applies an explicit reset list for fields that
 * shouldn't carry over, then clones child sessions with dates reset to today.
 *
 * Registrations, attendance, notifications, audit-log entries are never
 * touched. Edition-level `documents` are dropped (course-level documents
 * survive via the preserved course_id).
 *
 * Only registered fields are copied — unregistered meta (LearnDash course-grid
 * bleed, WP `_edit_lock`, seed markers, v3 orphans) is intentionally NOT
 * carried over. If a new field needs to survive duplication, register it on
 * EditionCPT / SessionCPT.
 */
final class EditionDuplicator implements NTDST_Service_Meta
{
    /**
     * Registered fields whose values are reset on the duplicate.
     * All other registered fields carry over verbatim.
     */
    private const FIELD_RESETS = [
        'notes'          => [],
        'documents'      => [],
        'selection_open' => false,
    ];

    public static function metadata(): array
    {
        return [
            'name'        => 'Edition Duplicator',
            'description' => 'Duplicates an edition (post + meta + sessions, with safe resets)',
            'priority'    => 50,
        ];
    }

    public function __construct(
        private readonly EditionRepository $editions,
        private readonly SessionRepository $sessions,
    ) {
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
            'stride_duplicate_edition_' . $post->ID,
        );

        $actions['stride_duplicate'] = sprintf(
            '<a href="%s" aria-label="%s">%s</a>',
            esc_url($url),
            esc_attr__('Dupliceer deze editie', 'stride'),
            esc_html__('Dupliceren', 'stride'),
        );

        return $actions;
    }

    public function handleDuplicate(): void
    {
        $sourceId = isset($_GET['edition_id']) ? absint(wp_unslash($_GET['edition_id'])) : 0;

        if ($sourceId <= 0) {
            $this->redirectToList('missing_id');
        }

        // Verify the target is actually an edition BEFORE the capability check —
        // edit_post can legitimately resolve true for other post types the user owns.
        $source = get_post($sourceId);
        if (!$source instanceof WP_Post || $source->post_type !== EditionCPT::POST_TYPE) {
            $this->redirectToList('not_found');
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
            admin_url('edit.php'),
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
            'not_found'        => __('Editie niet gevonden.', 'stride'),
            'invalid_nonce'    => __('Beveiligingscontrole mislukt. Probeer opnieuw.', 'stride'),
            'forbidden'        => __('Geen toestemming om deze editie te dupliceren.', 'stride'),
            'duplicate_failed' => __('Dupliceren mislukt. Controleer de bron-editie.', 'stride'),
        ];

        if (!isset($messages[$notice])) {
            return;
        }

        printf(
            '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
            esc_html($messages[$notice]),
        );
    }

    /**
     * @return int|WP_Error new edition ID on success
     */
    public function duplicate(int $sourceEditionId): int|WP_Error
    {
        $source = $this->editions->find($sourceEditionId);

        if (is_wp_error($source)) {
            return new WP_Error(
                'not_found',
                __('Bron-editie niet gevonden.', 'stride'),
            );
        }

        $fields = $this->editions->findFields($sourceEditionId);
        $fields = array_merge($fields, self::FIELD_RESETS);

        $newPost = $this->editions->create($fields + [
            'title'       => $source->post_title . ' (kopie)',
            'content'     => $source->post_content,
            'excerpt'     => $source->post_excerpt,
            'post_status' => 'draft',
            'post_author' => get_current_user_id() ?: (int) $source->post_author,
        ]);

        if (is_wp_error($newPost)) {
            return $newPost;
        }

        $newId = (int) $newPost->ID;

        foreach (get_object_taxonomies(EditionCPT::POST_TYPE) as $taxonomy) {
            $terms = wp_get_object_terms($sourceEditionId, $taxonomy, ['fields' => 'ids']);
            if (!is_wp_error($terms) && !empty($terms)) {
                wp_set_object_terms($newId, $terms, $taxonomy);
            }
        }

        $this->copySessions($sourceEditionId, $newId);

        return $newId;
    }

    private function copySessions(int $sourceEditionId, int $newEditionId): void
    {
        $today = date('Y-m-d');

        foreach ($this->sessions->findByEdition($sourceEditionId) as $session) {
            $sessionId = (int) ($session['ID'] ?? $session['id'] ?? 0);
            if ($sessionId <= 0) {
                continue;
            }

            $sessionPost = $this->sessions->find($sessionId);
            if (is_wp_error($sessionPost)) {
                continue;
            }

            $sessionFields = $this->sessions->findFields($sessionId);
            $sessionFields['edition_id'] = $newEditionId;
            $sessionFields['date']       = $today;

            $result = $this->sessions->create($sessionFields + [
                'title'       => $sessionPost->post_title,
                'content'     => $sessionPost->post_content,
                'excerpt'     => $sessionPost->post_excerpt,
                'post_status' => $sessionPost->post_status,
            ]);

            if (is_wp_error($result)) {
                ntdst_log('edition')->warning('Session copy failed during edition duplicate', [
                    'source_session' => $sessionId,
                    'target_edition' => $newEditionId,
                    'error'          => $result->get_error_code() . ': ' . $result->get_error_message(),
                ]);
            }
        }
    }
}
