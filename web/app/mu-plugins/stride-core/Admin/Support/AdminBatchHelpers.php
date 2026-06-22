<?php

declare(strict_types=1);

namespace Stride\Admin\Support;

/**
 * Shared batch read-helpers for the admin read surfaces.
 *
 * Strangled out of AdminAPIController (§12.4 / S2) behavior-preserving. These
 * two helpers are cross-domain: enrichAuditContexts hydrates audit-entry
 * contexts with post titles and is consumed by AdminAPIController::getActivityFeed,
 * AdminAPIController::getNotifications AND AdminUserService::getUserDetail.
 * fetchPostTitles is its private dependency. Because the consumers span the
 * controller AND the extracted user-detail service, the helpers live in a
 * shared trait both `use`, rather than privately inside either one (the §1
 * hazard: a helper privately owned by one consumer while another also calls it).
 *
 * Stateless — moved verbatim from the controller, no behavior change.
 */
trait AdminBatchHelpers
{
    /**
     * Hydrate audit-entry context JSON with post titles (edition/course/quote)
     * so the activity mapper can render human-readable labels without N+1 lookups.
     *
     * @param array<int,object> $entries
     * @return array<int,object>
     */
    private function enrichAuditContexts(array $entries): array
    {
        if (empty($entries)) {
            return $entries;
        }

        $postIds = [];
        $decoded = [];

        foreach ($entries as $i => $entry) {
            $ctx = json_decode($entry->context ?? '{}', true) ?: [];
            $decoded[$i] = $ctx;
            if (!empty($ctx['edition_id'])) {
                $postIds[] = (int) $ctx['edition_id'];
            }
            if (!empty($ctx['course_id']) && empty($ctx['course_title'])) {
                $postIds[] = (int) $ctx['course_id'];
            }
            if (!empty($ctx['quote_id'])) {
                $postIds[] = (int) $ctx['quote_id'];
            }
        }

        $titles = $this->fetchPostTitles(array_values(array_unique(array_filter($postIds))));

        foreach ($entries as $i => $entry) {
            $ctx = $decoded[$i];
            $editionId = (int) ($ctx['edition_id'] ?? 0);
            $courseId = (int) ($ctx['course_id'] ?? 0);

            if ($editionId > 0 && empty($ctx['edition_title']) && isset($titles[$editionId])) {
                $ctx['edition_title'] = $titles[$editionId];
            }
            if ($courseId > 0 && empty($ctx['course_title']) && isset($titles[$courseId])) {
                $ctx['course_title'] = $titles[$courseId];
            }

            $entry->context = wp_json_encode($ctx);
        }

        return $entries;
    }

    /**
     * Batch fetch post_title for a set of IDs.
     *
     * @param array<int> $postIds
     * @return array<int, string>
     */
    private function fetchPostTitles(array $postIds): array
    {
        if (empty($postIds)) {
            return [];
        }
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($postIds), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ({$placeholders})",
            ...$postIds,
        ));
        $titles = [];
        foreach ($rows as $row) {
            $titles[(int) $row->ID] = (string) $row->post_title;
        }
        return $titles;
    }
}
