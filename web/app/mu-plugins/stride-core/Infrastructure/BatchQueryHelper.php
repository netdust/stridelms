<?php

declare(strict_types=1);

namespace Stride\Infrastructure;

/**
 * Batch query utilities for performance optimization.
 */
final class BatchQueryHelper
{
    /**
     * Batch fetch post meta for multiple post IDs.
     *
     * @param array<int> $postIds
     * @param array<string> $metaKeys
     * @return array<int, array<string, mixed>> Map of postId => [metaKey => value]
     */
    public static function batchGetPostMeta(array $postIds, array $metaKeys): array
    {
        if (empty($postIds) || empty($metaKeys)) {
            return [];
        }

        global $wpdb;

        $postIds = array_map('intval', array_unique($postIds));
        $metaKeys = array_map('sanitize_key', $metaKeys);

        $postPlaceholders = implode(',', array_fill(0, count($postIds), '%d'));
        $keyPlaceholders = implode(',', array_fill(0, count($metaKeys), '%s'));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_key, meta_value
             FROM {$wpdb->postmeta}
             WHERE post_id IN ({$postPlaceholders})
             AND meta_key IN ({$keyPlaceholders})",
            ...array_merge($postIds, $metaKeys),
        ));

        $meta = [];
        foreach ($postIds as $postId) {
            $meta[$postId] = array_fill_keys($metaKeys, null);
        }

        foreach ($results as $row) {
            // Note: maybe_unserialize follows WordPress core pattern for post meta.
            // Data comes from database, not user input. Risk is controlled by DB write capabilities.
            $meta[(int) $row->post_id][$row->meta_key] = maybe_unserialize($row->meta_value);
        }

        return $meta;
    }

    /**
     * Batch fetch registration counts for multiple edition IDs.
     *
     * @param array<int> $editionIds
     * @return array<int, int> Map of editionId => count
     */
    public static function batchGetRegistrationCounts(array $editionIds): array
    {
        if (empty($editionIds)) {
            return [];
        }

        global $wpdb;

        $editionIds = array_map('intval', array_unique($editionIds));
        $table = $wpdb->prefix . 'vad_registrations';

        // Check table exists
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return array_fill_keys($editionIds, 0);
        }

        $placeholders = implode(',', array_fill(0, count($editionIds), '%d'));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT edition_id, COUNT(*) as count
             FROM {$table}
             WHERE edition_id IN ({$placeholders}) AND status = 'confirmed'
             GROUP BY edition_id",
            ...$editionIds,
        ));

        $counts = array_fill_keys($editionIds, 0);
        foreach ($results as $row) {
            $counts[(int) $row->edition_id] = (int) $row->count;
        }

        return $counts;
    }

    /**
     * Batch fetch user data for multiple user IDs.
     *
     * @param array<int> $userIds
     * @return array<int, \WP_User|null> Map of userId => WP_User|null
     */
    public static function batchGetUsers(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $userIds = array_map('intval', array_unique($userIds));

        $users = get_users([
            'include' => $userIds,
            'fields' => 'all',
        ]);

        $userMap = array_fill_keys($userIds, null);
        foreach ($users as $user) {
            $userMap[$user->ID] = $user;
        }

        return $userMap;
    }

    /**
     * Batch resolve e-mail addresses to existing WP accounts (one query).
     *
     * Consumed by the grid's lead rows: under the safer identity variant
     * (form-identity plan 2026-07-14) a lead whose e-mail belongs to an
     * existing account stays a lead until promotion — this lookup lets the
     * admin SEE that ("Account gevonden") instead of discovering it at
     * promote time. Keys are lower-cased for case-insensitive matching
     * (e-mail case is not significant).
     *
     * @param  array<string> $emails
     * @return array<string, \WP_User> Map of lower-cased email => WP_User (misses absent).
     */
    public static function batchGetUsersByEmail(array $emails): array
    {
        $emails = array_values(array_unique(array_filter(array_map(
            static fn($email): string => strtolower(trim((string) $email)),
            $emails,
        ))));

        if (empty($emails)) {
            return [];
        }

        // get_users has no email__in arg — resolve via the indexed user_email
        // column in ONE prepared IN() query, then hydrate the matched ids.
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($emails), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, user_email FROM {$wpdb->users} WHERE LOWER(user_email) IN ({$placeholders})",
            ...$emails,
        ));

        if (empty($rows)) {
            return [];
        }

        $byEmail = [];
        $userMap = self::batchGetUsers(array_map(static fn($r): int => (int) $r->ID, $rows));
        foreach ($rows as $row) {
            $user = $userMap[(int) $row->ID] ?? null;
            if ($user !== null) {
                $byEmail[strtolower((string) $row->user_email)] = $user;
            }
        }

        return $byEmail;
    }

    /**
     * Batch fetch posts by IDs.
     *
     * @param array<int> $postIds
     * @param string $postType
     * @return array<int, \WP_Post|null> Map of postId => WP_Post|null
     */
    public static function batchGetPosts(array $postIds, string $postType = ''): array
    {
        if (empty($postIds)) {
            return [];
        }

        $postIds = array_map('intval', array_unique($postIds));

        $args = [
            'post__in' => $postIds,
            'posts_per_page' => count($postIds),
            'post_status' => 'any',
            'orderby' => 'post__in',
        ];

        if ($postType) {
            $args['post_type'] = $postType;
        } else {
            $args['post_type'] = 'any';
        }

        $posts = get_posts($args);

        $postMap = array_fill_keys($postIds, null);
        foreach ($posts as $post) {
            $postMap[$post->ID] = $post;
        }

        return $postMap;
    }

    /**
     * Batch fetch course tags for multiple course IDs.
     *
     * @param array<int> $courseIds
     * @return array<int, array<array{id: int, name: string}>> Map of courseId => tags
     */
    public static function batchGetCourseTags(array $courseIds): array
    {
        if (empty($courseIds)) {
            return [];
        }

        global $wpdb;

        $courseIds = array_map('intval', array_unique($courseIds));
        $placeholders = implode(',', array_fill(0, count($courseIds), '%d'));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT tr.object_id as course_id, t.term_id, t.name
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
             WHERE tr.object_id IN ({$placeholders})
             AND tt.taxonomy = 'ld_course_tag'",
            ...$courseIds,
        ));

        $tags = array_fill_keys($courseIds, []);
        foreach ($results as $row) {
            $tags[(int) $row->course_id][] = [
                'id' => (int) $row->term_id,
                'name' => $row->name,
            ];
        }

        return $tags;
    }

    /**
     * Batch fetch attendance for an edition.
     *
     * @param int $editionId
     * @return array<int, array<int, string>> Map of userId => [sessionId => status]
     */
    public static function batchGetAttendance(int $editionId): array
    {
        return self::batchGetAttendanceForEditions([$editionId])[$editionId] ?? [];
    }

    /**
     * Batch fetch attendance for MANY editions in one query.
     *
     * Perf audit 4B.2: the admin registrations grid previously looped
     * batchGetAttendance() per distinct edition — each iteration ran its own
     * SHOW TABLES LIKE existence probe + a per-edition SELECT (worst case
     * 2 × N editions queries per grid page). This hoists the existence check
     * out of the loop (one probe) and fetches every edition's rows in a single
     * `edition_id IN (...)` SELECT, then partitions the rows per edition.
     *
     * INV-3: BatchQueryHelper is the sanctioned $wpdb reader for these batch
     * shapes (no per-edition repository finder exists); every dynamic value is
     * a $wpdb->prepare() placeholder.
     *
     * @param array<int> $editionIds
     * @return array<int, array<int, array<int, string>>> Map of editionId => (userId => [sessionId => status])
     */
    public static function batchGetAttendanceForEditions(array $editionIds): array
    {
        $editionIds = array_values(array_unique(array_map('intval', $editionIds)));
        if (empty($editionIds)) {
            return [];
        }

        // Every requested edition gets an entry, even with no attendance rows —
        // callers index by editionId and expect a present (possibly empty) key.
        $attendance = array_fill_keys($editionIds, []);

        global $wpdb;

        $table = $wpdb->prefix . 'vad_attendance';

        // Existence probe ONCE, not per edition (was the per-call overhead).
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return $attendance;
        }

        $placeholders = implode(',', array_fill(0, count($editionIds), '%d'));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT edition_id, user_id, session_id, status
             FROM {$table}
             WHERE edition_id IN ({$placeholders})",
            ...$editionIds,
        ));

        foreach ($results as $row) {
            $editionId = (int) $row->edition_id;
            $userId    = (int) $row->user_id;
            $sessionId = (int) $row->session_id;

            $attendance[$editionId][$userId][$sessionId] = $row->status;
        }

        return $attendance;
    }
}
