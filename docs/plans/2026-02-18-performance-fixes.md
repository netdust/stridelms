# Stride Core Performance Fixes Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix all critical and moderate N+1 query patterns, consolidate attendance storage, and optimize service loading in stride-core.

**Architecture:** Create batch query helper utilities, consolidate dual attendance storage to use AttendanceRepository exclusively, and implement lazy service loading. All fixes follow existing NTDST patterns.

**Tech Stack:** PHP 8.3, WordPress, Custom Tables (wp_vad_registrations, wp_vad_attendance)

---

## Task 1: Create BatchQueryHelper Utility

**Files:**
- Create: `web/app/mu-plugins/stride-core/Infrastructure/BatchQueryHelper.php`

**Step 1: Write the BatchQueryHelper class**

```php
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
            ...array_merge($postIds, $metaKeys)
        ));

        $meta = [];
        foreach ($postIds as $postId) {
            $meta[$postId] = array_fill_keys($metaKeys, null);
        }

        foreach ($results as $row) {
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
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return array_fill_keys($editionIds, 0);
        }

        $placeholders = implode(',', array_fill(0, count($editionIds), '%d'));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT edition_id, COUNT(*) as count
             FROM {$table}
             WHERE edition_id IN ({$placeholders}) AND status = 'confirmed'
             GROUP BY edition_id",
            ...$editionIds
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
            ...$courseIds
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
        global $wpdb;

        $table = $wpdb->prefix . 'vad_attendance';

        // Check table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return [];
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, session_id, status FROM {$table} WHERE edition_id = %d",
            $editionId
        ));

        $attendance = [];
        foreach ($results as $row) {
            $userId = (int) $row->user_id;
            $sessionId = (int) $row->session_id;

            if (!isset($attendance[$userId])) {
                $attendance[$userId] = [];
            }
            $attendance[$userId][$sessionId] = $row->status;
        }

        return $attendance;
    }
}
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Infrastructure/BatchQueryHelper.php
git commit -m "feat(perf): add BatchQueryHelper for batch query operations

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

## Task 2: Fix N+1 in AdminAPIController::getStats()

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php:239-485`

**Step 1: Add use statement at top of file**

Add after line 17:

```php
use Stride\Infrastructure\BatchQueryHelper;
```

**Step 2: Refactor getStats() to use batch queries**

Replace the entire `getStats()` method (lines 239-485) with:

```php
/**
 * GET /admin/stats
 *
 * Dashboard statistics.
 */
public function getStats(WP_REST_Request $request): WP_REST_Response
{
    global $wpdb;

    $today = current_time('Y-m-d');

    // Upcoming editions count
    $upcomingEditions = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
         WHERE p.post_type = %s AND p.post_status = 'publish'
         AND pm.meta_value >= %s",
        'start_date',
        EditionCPT::POST_TYPE,
        $today
    ));

    // Total active registrations
    $registrationTable = RegistrationTable::getTableName();
    $totalRegistrations = 0;
    if (RegistrationTable::exists()) {
        $totalRegistrations = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$registrationTable} WHERE status = 'confirmed'"
        );
    }

    // Pending quotes (draft status)
    $pendingQuotes = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
         WHERE p.post_type = %s AND p.post_status = 'publish'
         AND pm.meta_value = %s",
        'status',
        QuoteCPT::POST_TYPE,
        QuoteStatus::Draft->value
    ));

    // Sessions today
    $todaySessions = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
         WHERE p.post_type = %s AND p.post_status = 'publish'
         AND pm.meta_value = %s",
        'date',
        SessionCPT::POST_TYPE,
        $today
    ));

    // Open trajectories (status = 'open')
    $openTrajectories = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
         WHERE p.post_type = %s AND p.post_status = 'publish'
         AND pm.meta_value = %s",
        'status',
        TrajectoryCPT::POST_TYPE,
        'open'
    ));

    // Today's sessions with details (single query with JOINs)
    $sessions = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title, pm_time.meta_value as start_time, pm_end.meta_value as end_time,
                pm_edition.meta_value as edition_id
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = 'date'
         LEFT JOIN {$wpdb->postmeta} pm_time ON p.ID = pm_time.post_id AND pm_time.meta_key = 'start_time'
         LEFT JOIN {$wpdb->postmeta} pm_end ON p.ID = pm_end.post_id AND pm_end.meta_key = 'end_time'
         LEFT JOIN {$wpdb->postmeta} pm_edition ON p.ID = pm_edition.post_id AND pm_edition.meta_key = 'edition_id'
         WHERE p.post_type = %s AND p.post_status = 'publish'
         AND pm_date.meta_value = %s
         ORDER BY pm_time.meta_value ASC",
        SessionCPT::POST_TYPE,
        $today
    ));

    // Batch fetch edition data for today's sessions
    $sessionEditionIds = array_filter(array_map(fn($s) => (int) $s->edition_id, $sessions));
    $editionPosts = BatchQueryHelper::batchGetPosts($sessionEditionIds, EditionCPT::POST_TYPE);
    $sessionRegCounts = BatchQueryHelper::batchGetRegistrationCounts($sessionEditionIds);

    $todaySessionDetails = [];
    foreach ($sessions as $session) {
        $editionId = (int) $session->edition_id;
        $edition = $editionPosts[$editionId] ?? null;
        $todaySessionDetails[] = [
            'id' => $session->ID,
            'title' => $session->post_title,
            'editionTitle' => $edition ? $edition->post_title : '',
            'startTime' => $session->start_time ?: '',
            'endTime' => $session->end_time ?: '',
            'registeredCount' => $sessionRegCounts[$editionId] ?? 0,
        ];
    }

    // Upcoming editions (next 5) - single query with all data
    $upcomingList = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title, pm_date.meta_value as start_date,
                pm_capacity.meta_value as capacity, pm_status.meta_value as status
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = 'start_date'
         LEFT JOIN {$wpdb->postmeta} pm_capacity ON p.ID = pm_capacity.post_id AND pm_capacity.meta_key = 'capacity'
         LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = 'status'
         WHERE p.post_type = %s AND p.post_status = 'publish'
         AND pm_date.meta_value >= %s
         ORDER BY pm_date.meta_value ASC
         LIMIT 5",
        EditionCPT::POST_TYPE,
        $today
    ));

    // Batch fetch registration counts for upcoming editions
    $upcomingIds = array_map(fn($e) => (int) $e->ID, $upcomingList);
    $upcomingRegCounts = BatchQueryHelper::batchGetRegistrationCounts($upcomingIds);

    $upcomingEditionDetails = [];
    foreach ($upcomingList as $ed) {
        $editionId = (int) $ed->ID;
        $capacity = (int) $ed->capacity;
        $registeredCount = $upcomingRegCounts[$editionId] ?? 0;
        $upcomingEditionDetails[] = [
            'id' => $editionId,
            'title' => $ed->post_title,
            'startDate' => $ed->start_date,
            'status' => $ed->status ?: 'open',
            'capacity' => $capacity,
            'registeredCount' => $registeredCount,
            'spotsLeft' => $capacity > 0 ? max(0, $capacity - $registeredCount) : null,
        ];
    }

    // Recent registrations (last 7 days)
    $recentRegistrations = [];
    if (RegistrationTable::exists()) {
        $weekAgo = date('Y-m-d H:i:s', strtotime('-7 days'));
        $recentRegs = $wpdb->get_results($wpdb->prepare(
            "SELECT r.id, r.user_id, r.edition_id, r.status, r.created_at
             FROM {$registrationTable} r
             WHERE r.created_at >= %s
             ORDER BY r.created_at DESC
             LIMIT 10",
            $weekAgo
        ));

        // Batch fetch users and editions
        $recentUserIds = array_map(fn($r) => (int) $r->user_id, $recentRegs);
        $recentEditionIds = array_map(fn($r) => (int) $r->edition_id, $recentRegs);
        $recentUsers = BatchQueryHelper::batchGetUsers($recentUserIds);
        $recentEditions = BatchQueryHelper::batchGetPosts($recentEditionIds, EditionCPT::POST_TYPE);

        foreach ($recentRegs as $reg) {
            $user = $recentUsers[(int) $reg->user_id] ?? null;
            $edition = $recentEditions[(int) $reg->edition_id] ?? null;
            $recentRegistrations[] = [
                'id' => (int) $reg->id,
                'userName' => $user ? $user->display_name : 'Unknown',
                'userEmail' => $user ? $user->user_email : '',
                'editionTitle' => $edition ? $edition->post_title : 'Unknown',
                'status' => $reg->status,
                'createdAt' => $reg->created_at,
            ];
        }
    }

    // Registrations this week vs last week
    $thisWeekStart = date('Y-m-d', strtotime('monday this week'));
    $lastWeekStart = date('Y-m-d', strtotime('monday last week'));
    $registrationsThisWeek = 0;
    $registrationsLastWeek = 0;
    if (RegistrationTable::exists()) {
        $registrationsThisWeek = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$registrationTable} WHERE created_at >= %s",
            $thisWeekStart
        ));
        $registrationsLastWeek = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$registrationTable} WHERE created_at >= %s AND created_at < %s",
            $lastWeekStart,
            $thisWeekStart
        ));
    }

    // Alerts: editions almost full (>80%) or low registration (<30%) starting within 14 days
    $alerts = [];
    $twoWeeksFromNow = date('Y-m-d', strtotime('+14 days'));
    $alertEditions = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title, pm_date.meta_value as start_date,
                pm_capacity.meta_value as capacity
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = 'start_date'
         LEFT JOIN {$wpdb->postmeta} pm_capacity ON p.ID = pm_capacity.post_id AND pm_capacity.meta_key = 'capacity'
         WHERE p.post_type = %s AND p.post_status = 'publish'
         AND pm_date.meta_value >= %s AND pm_date.meta_value <= %s
         ORDER BY pm_date.meta_value ASC",
        EditionCPT::POST_TYPE,
        $today,
        $twoWeeksFromNow
    ));

    // Batch fetch registration counts for alert editions
    $alertIds = array_map(fn($e) => (int) $e->ID, $alertEditions);
    $alertRegCounts = BatchQueryHelper::batchGetRegistrationCounts($alertIds);

    foreach ($alertEditions as $ed) {
        $editionId = (int) $ed->ID;
        $capacity = (int) $ed->capacity;
        if ($capacity <= 0) {
            continue; // Skip unlimited capacity editions
        }
        $registeredCount = $alertRegCounts[$editionId] ?? 0;
        $fillRate = ($registeredCount / $capacity) * 100;

        if ($fillRate >= 80) {
            $alerts[] = [
                'type' => 'almost_full',
                'editionId' => $editionId,
                'editionTitle' => $ed->post_title,
                'startDate' => $ed->start_date,
                'message' => sprintf('%d/%d plaatsen bezet', $registeredCount, $capacity),
                'fillRate' => round($fillRate),
            ];
        } elseif ($fillRate < 30) {
            $alerts[] = [
                'type' => 'low_registration',
                'editionId' => $editionId,
                'editionTitle' => $ed->post_title,
                'startDate' => $ed->start_date,
                'message' => sprintf('Slechts %d/%d inschrijvingen', $registeredCount, $capacity),
                'fillRate' => round($fillRate),
            ];
        }
    }

    return new WP_REST_Response([
        'upcomingEditions' => $upcomingEditions,
        'totalRegistrations' => $totalRegistrations,
        'pendingQuotes' => $pendingQuotes,
        'todaySessions' => $todaySessions,
        'openTrajectories' => $openTrajectories,
        // New dashboard data
        'todaySessionDetails' => $todaySessionDetails,
        'upcomingEditionDetails' => $upcomingEditionDetails,
        'recentRegistrations' => $recentRegistrations,
        'registrationsThisWeek' => $registrationsThisWeek,
        'registrationsLastWeek' => $registrationsLastWeek,
        'alerts' => $alerts,
    ]);
}
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/AdminAPIController.php
git commit -m "perf(admin-api): optimize getStats() with batch queries

Reduces ~40+ queries to ~10 queries per dashboard load.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

## Task 3: Fix N+1 in AdminAPIController::getEditions() List View

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php:588-656`

**Step 1: Refactor the list view loop (lines 584-656)**

Replace lines 584-656 with:

```php
// Format editions with meta
$items = [];
$registrationTable = RegistrationTable::getTableName();

// Batch fetch all data upfront
$editionIds = array_map(fn($e) => (int) $e->ID, $editions);

// Batch fetch meta for all editions
$editionMeta = BatchQueryHelper::batchGetPostMeta($editionIds, [
    'start_date', 'end_date', 'venue', 'capacity', 'status', 'course_id'
]);

// Batch fetch registration counts
$regCounts = BatchQueryHelper::batchGetRegistrationCounts($editionIds);

// Batch fetch course data
$courseIds = array_filter(array_map(fn($id) => (int) ($editionMeta[$id]['course_id'] ?? 0), $editionIds));
$courses = BatchQueryHelper::batchGetPosts($courseIds, 'sfwd-courses');
$courseTags = BatchQueryHelper::batchGetCourseTags($courseIds);

foreach ($editions as $edition) {
    $editionId = (int) $edition->ID;
    $meta = $editionMeta[$editionId] ?? [];

    // Get meta values from batch
    $startDate = $meta['start_date'] ?? '';
    $endDate = $meta['end_date'] ?? '';
    $venue = $meta['venue'] ?? '';
    $capacity = (int) ($meta['capacity'] ?? 0);
    $editionStatus = $meta['status'] ?? '';
    $courseId = (int) ($meta['course_id'] ?? 0);

    // Get course data from batch
    $courseTitle = '';
    $courseTagList = [];
    if ($courseId > 0) {
        $course = $courses[$courseId] ?? null;
        if ($course) {
            $courseTitle = $course->post_title;
        }
        $courseTagList = $courseTags[$courseId] ?? [];
    }

    // Get registration count from batch
    $registeredCount = $regCounts[$editionId] ?? 0;

    // Check if edition is today
    $isToday = $startDate === $today || ($startDate <= $today && $endDate >= $today);
    $isPast = !empty($endDate) ? $endDate < $today : $startDate < $today;

    $items[] = [
        'id' => $editionId,
        'title' => $edition->post_title,
        'course' => [
            'id' => $courseId,
            'title' => $courseTitle,
            'tags' => $courseTagList,
        ],
        'startDate' => $startDate ?: null,
        'endDate' => $endDate ?: null,
        'venue' => $venue ?: null,
        'capacity' => $capacity,
        'registeredCount' => $registeredCount,
        'status' => $editionStatus ?: 'open',
        'isToday' => $isToday,
        'isPast' => $isPast,
        'editUrl' => admin_url("post.php?post={$editionId}&action=edit"),
    ];
}
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/AdminAPIController.php
git commit -m "perf(admin-api): optimize getEditions() list view with batch queries

Reduces ~180 queries to ~6 queries per page with 20 editions.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

## Task 4: Fix N+1 in AdminAPIController::getEditionsAgendaView()

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php:759-817`

**Step 1: Refactor the agenda view loop (lines 755-817)**

Replace lines 755-817 with:

```php
// Format items
$items = [];
$registrationTable = RegistrationTable::getTableName();

// Batch fetch all data upfront
$sessionIds = array_map(fn($s) => (int) $s->session_id, $sessions);
$editionIds = array_unique(array_map(fn($s) => (int) $s->edition_id, $sessions));

// Batch fetch session meta
$sessionMeta = BatchQueryHelper::batchGetPostMeta($sessionIds, [
    'start_time', 'end_time', 'location'
]);

// Batch fetch edition meta
$editionMeta = BatchQueryHelper::batchGetPostMeta($editionIds, [
    'venue', 'capacity', 'status', 'course_id'
]);

// Batch fetch registration counts
$regCounts = BatchQueryHelper::batchGetRegistrationCounts($editionIds);

// Batch fetch course data
$courseIds = array_filter(array_map(fn($id) => (int) ($editionMeta[$id]['course_id'] ?? 0), $editionIds));
$courses = BatchQueryHelper::batchGetPosts($courseIds, 'sfwd-courses');

foreach ($sessions as $session) {
    $sessionId = (int) $session->session_id;
    $editionId = (int) $session->edition_id;
    $sessionDate = $session->session_date;

    // Get session meta from batch
    $sMeta = $sessionMeta[$sessionId] ?? [];
    $startTime = $sMeta['start_time'] ?? '';
    $endTime = $sMeta['end_time'] ?? '';
    $location = $sMeta['location'] ?? '';

    // Get edition meta from batch
    $eMeta = $editionMeta[$editionId] ?? [];
    $venue = $eMeta['venue'] ?? '';
    $capacity = (int) ($eMeta['capacity'] ?? 0);
    $editionStatus = $eMeta['status'] ?? '';
    $courseId = (int) ($eMeta['course_id'] ?? 0);

    // Get course title from batch
    $courseTitle = '';
    if ($courseId > 0) {
        $course = $courses[$courseId] ?? null;
        if ($course) {
            $courseTitle = $course->post_title;
        }
    }

    // Get registration count from batch
    $registeredCount = $regCounts[$editionId] ?? 0;

    // Check if session is today/past
    $isToday = $sessionDate === $today;
    $isPast = $sessionDate < $today;

    $items[] = [
        'id' => $editionId,
        'sessionId' => $sessionId,
        'title' => $session->edition_title,
        'sessionTitle' => $session->session_title,
        'course' => [
            'id' => $courseId,
            'title' => $courseTitle,
        ],
        'date' => $sessionDate,
        'startTime' => $startTime ?: null,
        'endTime' => $endTime ?: null,
        'venue' => $location ?: $venue ?: null,
        'capacity' => $capacity,
        'registeredCount' => $registeredCount,
        'status' => $editionStatus ?: 'open',
        'isToday' => $isToday,
        'isPast' => $isPast,
        'editUrl' => admin_url("post.php?post={$editionId}&action=edit"),
    ];
}
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/AdminAPIController.php
git commit -m "perf(admin-api): optimize getEditionsAgendaView() with batch queries

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

## Task 5: Fix N+1 in AdminAPIController::getTrajectories()

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php:1327-1397`

**Step 1: Refactor trajectory enrollments loop (lines 1368-1397)**

After line 1377 (getting enrollments), add batch user fetch and replace the loop:

```php
// Get enrolled users list
$enrollments = $wpdb->get_results($wpdb->prepare(
    "SELECT user_id, status, enrolled_at FROM {$enrollmentTable} WHERE trajectory_id = %d ORDER BY enrolled_at DESC LIMIT 50",
    $trajectoryId
));

// Batch fetch users for enrollments
$enrollmentUserIds = array_map(fn($e) => (int) $e->user_id, $enrollments);
$enrollmentUsers = BatchQueryHelper::batchGetUsers($enrollmentUserIds);

foreach ($enrollments as $enrollment) {
    $user = $enrollmentUsers[(int) $enrollment->user_id] ?? null;
    if ($user) {
        $enrolledUsers[] = [
            'id' => (int) $enrollment->user_id,
            'name' => $user->display_name,
            'email' => $user->user_email,
            'status' => $enrollment->status,
            'enrolledAt' => $enrollment->enrolled_at,
        ];
    }
}
```

Also batch fetch edition titles for courses. After line 1349 (parsing courseList), add:

```php
// Batch fetch edition posts for course details
$courseEditionIds = array_filter(array_map(fn($c) => (int) ($c['edition_id'] ?? 0), $courseList));
$courseEditions = BatchQueryHelper::batchGetPosts($courseEditionIds, EditionCPT::POST_TYPE);

// Enrich courses with edition titles
$coursesWithDetails = [];
foreach ($courseList as $course) {
    $editionId = (int) ($course['edition_id'] ?? 0);
    $courseData = [
        'editionId' => $editionId,
        'type' => $course['type'] ?? 'required',
        'title' => '',
    ];
    if ($editionId > 0) {
        $edition = $courseEditions[$editionId] ?? null;
        if ($edition) {
            $courseData['title'] = $edition->post_title;
        }
    }
    $coursesWithDetails[] = $courseData;
}
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/AdminAPIController.php
git commit -m "perf(admin-api): optimize getTrajectories() with batch queries

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

## Task 6: Consolidate Attendance Storage - Remove user_meta Usage

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAdminController.php:543-605`
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAttendanceMetabox.php:97-189`

**Step 1: Inject AttendanceRepository into EditionAdminController**

Add import at top of EditionAdminController.php after line 13:

```php
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Attendance\AttendanceTable;
use Stride\Domain\AttendanceStatus;
use Stride\Infrastructure\BatchQueryHelper;
```

Modify constructor (lines 32-39):

```php
public function __construct(
    private readonly EditionService $editionService,
    private readonly EditionRepository $editionRepository,
    private readonly SessionService $sessionService,
    private readonly SessionRepository $sessionRepository,
    private readonly AttendanceRepository $attendanceRepository,
) {
    parent::__construct();
}
```

**Step 2: Refactor ajaxMarkAttendance() to use AttendanceRepository**

Replace ajaxMarkAttendance() method (lines 544-575):

```php
public function ajaxMarkAttendance(): void
{
    if (!$this->verifyAjaxNonce()) {
        return;
    }

    $sessionId = absint($_POST['session_id'] ?? 0);
    $userId = absint($_POST['user_id'] ?? 0);
    $statusValue = sanitize_text_field($_POST['status'] ?? 'present');

    if (!$sessionId || !$userId) {
        wp_send_json_error(['message' => __('Ongeldige gegevens.', 'stride')], 400);
    }

    // Validate status
    $validStatuses = ['unmarked', 'present', 'absent', 'excused'];
    if (!in_array($statusValue, $validStatuses, true)) {
        $statusValue = 'unmarked';
    }

    // Get session to find edition_id
    $session = $this->sessionService->getSession($sessionId);
    if (!$session) {
        wp_send_json_error(['message' => __('Sessie niet gevonden.', 'stride')], 404);
    }

    $editionId = (int) $session['edition_id'];

    if ($statusValue === 'unmarked') {
        // Delete attendance record
        $existing = $this->attendanceRepository->findBySessionAndUser($sessionId, $userId);
        if ($existing) {
            $this->attendanceRepository->delete((int) $existing->id);
        }
    } else {
        // Record attendance using repository
        $status = AttendanceStatus::tryFrom($statusValue);
        if ($status) {
            $this->attendanceRepository->record(
                $sessionId,
                $userId,
                $status,
                $editionId,
                get_current_user_id()
            );
        }
    }

    // Get totals for the session
    $totals = $this->getAttendanceTotals($sessionId);

    wp_send_json_success($totals);
}
```

**Step 3: Refactor ajaxBulkAttendance() to use AttendanceRepository**

Replace ajaxBulkAttendance() method (lines 577-605):

```php
public function ajaxBulkAttendance(): void
{
    if (!$this->verifyAjaxNonce()) {
        return;
    }

    $sessionId = absint($_POST['session_id'] ?? 0);
    if (!$sessionId) {
        wp_send_json_error(['message' => __('Ongeldige sessie.', 'stride')], 400);
    }

    $session = $this->sessionService->getSession($sessionId);
    if (!$session) {
        wp_send_json_error(['message' => __('Sessie niet gevonden.', 'stride')], 404);
    }

    $editionId = (int) $session['edition_id'];

    // Get all registrations for this edition
    $registrations = $this->getEditionRegistrations($editionId);

    // Mark all as present using repository
    $currentUserId = get_current_user_id();
    foreach ($registrations as $registration) {
        $this->attendanceRepository->record(
            $sessionId,
            (int) $registration['user_id'],
            AttendanceStatus::Present,
            $editionId,
            $currentUserId
        );
    }

    $totals = $this->getAttendanceTotals($sessionId);

    wp_send_json_success($totals);
}
```

**Step 4: Refactor getAttendanceTotals() to use custom table**

Replace getAttendanceTotals() method (lines 771-793):

```php
private function getAttendanceTotals(int $sessionId): array
{
    $session = $this->sessionService->getSession($sessionId);
    if (!$session) {
        return ['presentCount' => 0, 'totalCount' => 0];
    }

    $registrations = $this->getEditionRegistrations($session['edition_id']);
    $totalCount = count($registrations);

    // Count present from attendance table
    $presentUserIds = $this->attendanceRepository->getPresentUserIds($sessionId);
    $presentCount = count($presentUserIds);

    return [
        'presentCount' => $presentCount,
        'totalCount' => $totalCount,
    ];
}
```

**Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAdminController.php
git commit -m "perf(attendance): consolidate to AttendanceRepository, remove user_meta

Eliminates dual storage of attendance data.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

## Task 7: Fix EditionAttendanceMetabox N+1 Queries

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAttendanceMetabox.php`

**Step 1: Add imports at top of file after line 9**

```php
use Stride\Infrastructure\BatchQueryHelper;
use Stride\Modules\Attendance\AttendanceTable;
```

**Step 2: Refactor render() method to use batch queries**

Replace lines 57-158 (the main rendering loop):

```php
// Get registered users
$registrations = $this->getEditionRegistrations($post->ID);

if (empty($registrations)) {
    ?>
    <div class="stride-sessions-notice">
        <span class="dashicons dashicons-info"></span>
        <span><?php esc_html_e('Er zijn nog geen inschrijvingen voor deze editie.', 'stride'); ?></span>
    </div>
    <?php
    return;
}

// Re-index sessions array for consistent iteration
$sessions = array_values($sessions);

// Batch fetch user data and attendance
$userIds = array_column($registrations, 'user_id');
$users = BatchQueryHelper::batchGetUsers($userIds);

// Batch fetch user meta (organisation)
global $wpdb;
$userPlaceholders = implode(',', array_fill(0, count($userIds), '%d'));
$userOrgs = [];
$orgResults = $wpdb->get_results($wpdb->prepare(
    "SELECT user_id, meta_value FROM {$wpdb->usermeta}
     WHERE user_id IN ({$userPlaceholders}) AND meta_key = 'organisation'",
    ...$userIds
));
foreach ($orgResults as $row) {
    $userOrgs[(int) $row->user_id] = $row->meta_value;
}

// Batch fetch all attendance for this edition
$attendanceByUser = BatchQueryHelper::batchGetAttendance($post->ID);
?>
<div class="stride-attendance-admin">
<div class="stride-attendance-table-wrapper">
        <table class="stride-attendance-table">
            <thead>
                <tr>
                    <th class="column-name"><?php esc_html_e('Naam', 'stride'); ?></th>
                    <th class="column-email"><?php esc_html_e('E-mail', 'stride'); ?></th>
                    <th class="column-org"><?php esc_html_e('Organisatie', 'stride'); ?></th>
                    <?php foreach ($sessions as $session): ?>
                        <th class="column-session" data-session-id="<?php echo esc_attr($session['id']); ?>">
                            <div class="session-header">
                                <span class="session-date"><?php echo esc_html(date_i18n('d M', strtotime($session['date']))); ?></span>
                                <?php if (!empty($session['start_time'])): ?>
                                    <span class="session-time"><?php echo esc_html($session['start_time']); ?></span>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="stride-mark-all-present" title="<?php esc_attr_e('Allen aanwezig', 'stride'); ?>">
                                <span class="dashicons dashicons-yes-alt"></span>
                            </button>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($registrations as $registration): ?>
                    <?php
                    $userId = (int) $registration['user_id'];
                    $user = $users[$userId] ?? null;
                    if (!$user) continue;

                    $organisation = $userOrgs[$userId] ?? '';
                    $userAttendance = $attendanceByUser[$userId] ?? [];
                    ?>
                    <tr data-user-id="<?php echo esc_attr($userId); ?>">
                        <td class="column-name"><?php echo esc_html($user->display_name); ?></td>
                        <td class="column-email"><?php echo esc_html($user->user_email); ?></td>
                        <td class="column-org"><?php echo esc_html($organisation); ?></td>
                        <?php foreach ($sessions as $session): ?>
                            <?php
                            $status = $userAttendance[(int) $session['id']] ?? 'unmarked';
                            ?>
                            <td class="column-session">
                                <button type="button"
                                        class="stride-attendance-toggle <?php echo esc_attr($status); ?>"
                                        data-session-id="<?php echo esc_attr($session['id']); ?>"
                                        data-user-id="<?php echo esc_attr($userId); ?>"
                                        title="<?php echo esc_attr($this->getStatusLabel($status)); ?>">
                                    <span class="status-icon"></span>
                                </button>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="attendance-totals">
                    <td colspan="3" class="totals-label"><?php esc_html_e('Aanwezig', 'stride'); ?></td>
                    <?php foreach ($sessions as $session): ?>
                        <?php
                        $totals = $this->getSessionAttendanceTotalsFromBatch($session['id'], $registrations, $attendanceByUser);
                        ?>
                        <td class="totals-cell" data-session-id="<?php echo esc_attr($session['id']); ?>">
                            <span class="attendance-count"><?php echo esc_html($totals['present'] . '/' . $totals['total']); ?></span>
                        </td>
                    <?php endforeach; ?>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Legend -->
    <div class="stride-attendance-legend">
        <div class="legend-item present">
            <span class="status-icon"></span>
            <span><?php esc_html_e('Aanwezig', 'stride'); ?></span>
        </div>
        <div class="legend-item absent">
            <span class="status-icon"></span>
            <span><?php esc_html_e('Afwezig', 'stride'); ?></span>
        </div>
        <div class="legend-item excused">
            <span class="status-icon"></span>
            <span><?php esc_html_e('Verontschuldigd', 'stride'); ?></span>
        </div>
    </div>
</div>
<?php
```

**Step 3: Replace getSessionAttendanceTotals() with batch version**

Replace the existing method (lines 173-189):

```php
private function getSessionAttendanceTotalsFromBatch(int $sessionId, array $registrations, array $attendanceByUser): array
{
    $present = 0;
    $total = count($registrations);

    foreach ($registrations as $registration) {
        $userId = (int) $registration['user_id'];
        $status = $attendanceByUser[$userId][$sessionId] ?? null;
        if ($status === 'present') {
            $present++;
        }
    }

    return [
        'present' => $present,
        'total' => $total,
    ];
}

// Keep old method for backwards compatibility but mark deprecated
/** @deprecated Use getSessionAttendanceTotalsFromBatch() */
private function getSessionAttendanceTotals(int $sessionId, array $registrations): array
{
    return $this->getSessionAttendanceTotalsFromBatch($sessionId, $registrations, BatchQueryHelper::batchGetAttendance(0));
}
```

**Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAttendanceMetabox.php
git commit -m "perf(attendance-metabox): batch fetch users and attendance

Reduces ~180 queries to ~4 queries for 30 users × 5 sessions.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

## Task 8: Add Composite Index to Registration Table

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationTable.php`

**Step 1: Add composite index to table creation**

Modify the CREATE TABLE statement (lines 27-47) to add composite index:

```php
$sql = "CREATE TABLE IF NOT EXISTS {$table} (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    edition_id BIGINT UNSIGNED NOT NULL,
    status ENUM('confirmed','cancelled','waitlist','interest') DEFAULT 'confirmed',
    enrollment_path ENUM('individual','colleague','trajectory','interest') DEFAULT 'individual',
    enrolled_by BIGINT UNSIGNED NULL,
    voucher_code VARCHAR(50) NULL,
    quote_id BIGINT UNSIGNED NULL,
    registered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    cancelled_at DATETIME NULL,
    notes TEXT NULL,
    INDEX idx_user (user_id),
    INDEX idx_edition (edition_id),
    INDEX idx_status (status),
    INDEX idx_edition_status (edition_id, status),
    UNIQUE KEY unique_user_edition (user_id, edition_id)
) {$charset};";
```

**Step 2: Create migration to add index to existing tables**

Add a new method to add index if missing:

```php
public static function addCompositeIndexIfMissing(): void
{
    global $wpdb;

    $table = self::getTableName();

    // Check if index exists
    $indexExists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE()
         AND table_name = %s
         AND index_name = 'idx_edition_status'",
        $table
    ));

    if (!$indexExists) {
        $wpdb->query("ALTER TABLE {$table} ADD INDEX idx_edition_status (edition_id, status)");
    }
}
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationTable.php
git commit -m "perf(db): add composite index idx_edition_status to registrations

Optimizes COUNT(*) WHERE edition_id = X AND status = 'confirmed' queries.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

## Task 9: Add Cache to QuoteRepository::generateQuoteNumber()

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteRepository.php:84-109`

**Step 1: Refactor generateQuoteNumber() to use transient cache**

Replace the method (lines 84-109):

```php
/**
 * Generate next quote number.
 */
public function generateQuoteNumber(): string
{
    $year = date('Y');
    $prefix = "OFF-{$year}-";
    $cacheKey = 'stride_last_quote_number_' . $year;

    // Try to get from cache first
    $lastNumber = get_transient($cacheKey);

    if ($lastNumber === false) {
        // Find highest number for this year
        global $wpdb;
        $table = $wpdb->prefix . 'postmeta';
        $postsTable = $wpdb->prefix . 'posts';

        $lastNumber = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(CAST(SUBSTRING(meta_value, %d) AS UNSIGNED))
             FROM {$table} pm
             JOIN {$postsTable} p ON pm.post_id = p.ID
             WHERE pm.meta_key = 'quote_number'
             AND pm.meta_value LIKE %s
             AND p.post_type = %s",
            strlen($prefix) + 1,
            $prefix . '%',
            QuoteCPT::POST_TYPE
        ));

        $lastNumber = (int) $lastNumber;
    }

    $nextNumber = $lastNumber + 1;

    // Update cache with new number (1 hour TTL)
    set_transient($cacheKey, $nextNumber, HOUR_IN_SECONDS);

    return $prefix . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
}
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteRepository.php
git commit -m "perf(quotes): cache last quote number with transient

Avoids expensive MAX+SUBSTRING query on every quote creation.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

## Task 10: Optimize AttendanceService::getHoursAttended()

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Attendance/AttendanceService.php:230-243`

**Step 1: Refactor to batch fetch session durations**

Replace getHoursAttended() method (lines 230-243):

```php
/**
 * Get hours attended by user in edition.
 */
public function getHoursAttended(int $userId, int $editionId): float
{
    $attendance = $this->repository->getByUserAndEdition($userId, $editionId);

    if (empty($attendance)) {
        return 0.0;
    }

    // Get all session IDs that count as attended
    $sessionIds = [];
    foreach ($attendance as $record) {
        $status = AttendanceStatus::tryFrom($record->status);
        if ($status?->countsAsAttended()) {
            $sessionIds[] = (int) $record->session_id;
        }
    }

    if (empty($sessionIds)) {
        return 0.0;
    }

    // Batch fetch session durations using a single query
    return $this->sessionService->getTotalDurationForSessions($sessionIds);
}
```

**Step 2: Add getTotalDurationForSessions() to SessionService**

Add to SessionService:

```php
/**
 * Get total duration for multiple sessions in hours.
 *
 * @param array<int> $sessionIds
 */
public function getTotalDurationForSessions(array $sessionIds): float
{
    if (empty($sessionIds)) {
        return 0.0;
    }

    global $wpdb;

    $placeholders = implode(',', array_fill(0, count($sessionIds), '%d'));

    // Fetch start_time and end_time for all sessions in single query
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT pm_start.post_id, pm_start.meta_value as start_time, pm_end.meta_value as end_time
         FROM {$wpdb->postmeta} pm_start
         LEFT JOIN {$wpdb->postmeta} pm_end ON pm_start.post_id = pm_end.post_id AND pm_end.meta_key = 'end_time'
         WHERE pm_start.post_id IN ({$placeholders})
         AND pm_start.meta_key = 'start_time'",
        ...$sessionIds
    ));

    $totalHours = 0.0;
    foreach ($results as $row) {
        if (!empty($row->start_time) && !empty($row->end_time)) {
            $start = strtotime($row->start_time);
            $end = strtotime($row->end_time);
            if ($end > $start) {
                $totalHours += ($end - $start) / 3600;
            }
        }
    }

    return $totalHours;
}
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Attendance/AttendanceService.php
git add web/app/mu-plugins/stride-core/Modules/Edition/SessionService.php
git commit -m "perf(attendance): batch fetch session durations for hours calculation

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

## Summary

| Task | Issue | Estimated Query Reduction |
|------|-------|--------------------------|
| 1 | BatchQueryHelper utility | Foundation for all fixes |
| 2 | getStats() N+1 | 40+ → ~10 queries |
| 3 | getEditions() list N+1 | 180+ → ~6 queries |
| 4 | getEditionsAgendaView() N+1 | 100+ → ~6 queries |
| 5 | getTrajectories() N+1 | 50+ → ~8 queries |
| 6 | Dual attendance storage | Eliminates data inconsistency |
| 7 | EditionAttendanceMetabox N+1 | 180+ → ~4 queries |
| 8 | Composite index | Faster COUNT queries |
| 9 | Quote number cache | Eliminates MAX query per quote |
| 10 | getHoursAttended() N+1 | N → 1 query |

**Total estimated improvement:** From 600+ queries on admin dashboard to ~40 queries.
