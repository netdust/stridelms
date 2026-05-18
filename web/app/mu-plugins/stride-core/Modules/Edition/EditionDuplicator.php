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
    }

    public function addDuplicateRowAction(array $actions, WP_Post $post): array
    {
        return $actions;
    }

    public function handleDuplicate(): void
    {
        // implemented in later tasks
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

        return new WP_Error('not_implemented', 'Not implemented yet');
    }
}
