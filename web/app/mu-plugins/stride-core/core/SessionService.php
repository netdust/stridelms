<?php

namespace ntdst\Stride\core;

defined('ABSPATH') || exit;

use ntdst\Stride\FieldRegistry;
use WP_Error;

/**
 * Session Service
 *
 * Manages individual meeting days within editions for attendance tracking.
 *
 * Each session represents a single day/time slot in an edition:
 * - Edition with 3 days = 3 sessions
 * - Attendance tracked per session via attendees array
 * - Hours calculated from start/end times
 *
 * Available hooks:
 * - stride/session/attendance_marked (action) - After attendance change
 *
 * @package stride\services\core
 */
class SessionService implements \NTDST_Service_Meta
{
    public const POST_TYPE = 'vad_session';

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Session Service',
            'description' => 'Individual meeting days and attendance tracking',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 5,
        ];
    }

    /**
     * Constructor
     */
    public function __construct()
    {
        // Register CPT via DataManager
        add_action('init', [$this, 'registerModel'], 5);
    }

    // ========================================
    // CPT REGISTRATION
    // ========================================

    /**
     * Register vad_session model via NTDST DataManager
     */
    public function registerModel(): void
    {
        if (!function_exists('ntdst_data')) {
            $this->registerPostTypeFallback();
            return;
        }

        ntdst_data()->register(self::POST_TYPE, [
            'label' => __('Sessies', 'stride'),
            'labels' => [
                'name' => __('Sessies', 'stride'),
                'singular_name' => __('Sessie', 'stride'),
                'menu_name' => __('Sessies', 'stride'),
                'add_new' => __('Nieuwe sessie', 'stride'),
                'add_new_item' => __('Nieuwe sessie toevoegen', 'stride'),
                'edit_item' => __('Sessie bewerken', 'stride'),
                'view_item' => __('Sessie bekijken', 'stride'),
                'all_items' => __('Alle sessies', 'stride'),
                'search_items' => __('Sessies zoeken', 'stride'),
                'not_found' => __('Geen sessies gevonden', 'stride'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'stride-admin',
            'show_in_rest' => false,
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'has_archive' => false,
            'menu_icon' => 'dashicons-clock',

            // Field schema for ORM
            'fields' => [
                FieldRegistry::SESSION_EDITION_ID => ['type' => 'integer', 'required' => true],
                FieldRegistry::SESSION_DATE => ['type' => 'text', 'required' => true],
                FieldRegistry::SESSION_START_TIME => ['type' => 'text'],
                FieldRegistry::SESSION_END_TIME => ['type' => 'text'],
                FieldRegistry::SESSION_LOCATION => ['type' => 'text'],
                FieldRegistry::SESSION_ATTENDEES => ['type' => 'json', 'default' => []],
            ],
            'auto_metabox' => true,
        ]);
    }

    /**
     * Fallback CPT registration if DataManager not available
     */
    private function registerPostTypeFallback(): void
    {
        register_post_type(self::POST_TYPE, [
            'label' => __('Sessies', 'stride'),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'stride-admin',
            'supports' => ['title'],
        ]);
    }

    /**
     * Get the DataManager model
     */
    private function getModel(): ?\NTDST_Data_Model
    {
        if (!function_exists('ntdst_data')) {
            return null;
        }
        return ntdst_data()->get(self::POST_TYPE);
    }

    // ========================================
    // SESSION QUERIES
    // ========================================

    /**
     * Get session by ID
     *
     * @param int $sessionId Session post ID
     * @return array|null Session data or null if not found
     */
    public function getSession(int $sessionId): ?array
    {
        $model = $this->getModel();
        if (!$model) {
            return null;
        }

        $post = $model->find($sessionId);
        if (is_wp_error($post)) {
            return null;
        }

        return $this->formatSession($post);
    }

    /**
     * Get all sessions for an edition
     *
     * @param int $editionId Edition post ID
     * @return array Array of session data sorted by date
     */
    public function getSessionsForEdition(int $editionId): array
    {
        $model = $this->getModel();
        if (!$model) {
            return [];
        }

        $posts = $model
            ->where(FieldRegistry::SESSION_EDITION_ID, $editionId)
            ->orderBy(FieldRegistry::SESSION_DATE, 'ASC')
            ->withMeta()
            ->get();

        return array_map([$this, 'formatSessionFromArray'], $posts);
    }

    /**
     * Get session count for an edition
     *
     * @param int $editionId Edition post ID
     * @return int Number of sessions
     */
    public function getSessionCount(int $editionId): int
    {
        $model = $this->getModel();
        if (!$model) {
            return 0;
        }

        return $model
            ->where(FieldRegistry::SESSION_EDITION_ID, $editionId)
            ->count();
    }

    /**
     * Get number of unique days in an edition
     * (same as session count unless there are multiple sessions per day)
     *
     * @param int $editionId Edition post ID
     * @return int Number of unique days
     */
    public function getDayCount(int $editionId): int
    {
        $sessions = $this->getSessionsForEdition($editionId);
        $dates = array_unique(array_column($sessions, 'date'));
        return count($dates);
    }

    // ========================================
    // ATTENDANCE METHODS
    // ========================================

    /**
     * Mark a user as present for a session
     *
     * @param int $sessionId Session post ID
     * @param int $userId WordPress user ID
     * @return true|WP_Error True on success, WP_Error on failure
     */
    public function markPresent(int $sessionId, int $userId): true|WP_Error
    {
        if (!$this->canManageAttendance()) {
            return new WP_Error('unauthorized', __('Je hebt geen rechten om aanwezigheid te beheren.', 'stride'));
        }

        $attendees = $this->getAttendees($sessionId);

        // Add if not already present
        if (!in_array($userId, $attendees, true)) {
            $attendees[] = $userId;
            $this->saveAttendees($sessionId, $attendees);

            do_action('stride/session/attendance_marked', $sessionId, $userId, true);
        }

        return true;
    }

    /**
     * Mark a user as absent for a session
     *
     * @param int $sessionId Session post ID
     * @param int $userId WordPress user ID
     * @return true|WP_Error True on success, WP_Error on failure
     */
    public function markAbsent(int $sessionId, int $userId): true|WP_Error
    {
        if (!$this->canManageAttendance()) {
            return new WP_Error('unauthorized', __('Je hebt geen rechten om aanwezigheid te beheren.', 'stride'));
        }

        $attendees = $this->getAttendees($sessionId);

        // Remove if present
        $key = array_search($userId, $attendees, true);
        if ($key !== false) {
            unset($attendees[$key]);
            $this->saveAttendees($sessionId, array_values($attendees));

            do_action('stride/session/attendance_marked', $sessionId, $userId, false);
        }

        return true;
    }

    /**
     * Check if current user can manage attendance
     *
     * @return bool
     */
    private function canManageAttendance(): bool
    {
        // Admins and editors can manage attendance
        if (current_user_can('manage_options') || current_user_can('edit_others_posts')) {
            return true;
        }

        // Allow filter for group leaders or custom roles
        return (bool) apply_filters('stride/session/can_manage_attendance', false, get_current_user_id());
    }

    /**
     * Check if a user is marked present for a session
     *
     * @param int $sessionId Session post ID
     * @param int $userId WordPress user ID
     * @return bool
     */
    public function isPresent(int $sessionId, int $userId): bool
    {
        $attendees = $this->getAttendees($sessionId);
        return in_array($userId, $attendees, true);
    }

    /**
     * Get all attendees for a session
     *
     * @param int $sessionId Session post ID
     * @return array Array of user IDs
     */
    public function getAttendees(int $sessionId): array
    {
        $model = $this->getModel();
        if (!$model) {
            return [];
        }

        $attendees = $model->getMeta($sessionId, FieldRegistry::SESSION_ATTENDEES, []);

        // Handle JSON string (legacy or direct DB query)
        if (is_string($attendees)) {
            $attendees = json_decode($attendees, true) ?: [];
        }

        // Ensure all values are integers
        return array_map('intval', array_filter((array) $attendees));
    }

    /**
     * Save attendees array to session
     */
    private function saveAttendees(int $sessionId, array $attendees): void
    {
        $model = $this->getModel();
        if (!$model) {
            return;
        }

        $model->update($sessionId, [
            FieldRegistry::SESSION_ATTENDEES => $attendees,
        ]);
    }

    // ========================================
    // HOURS CALCULATION
    // ========================================

    /**
     * Get session duration in hours
     *
     * @param int $sessionId Session post ID
     * @return float Duration in hours (0 if times not set)
     */
    public function getSessionDuration(int $sessionId): float
    {
        $model = $this->getModel();
        if (!$model) {
            return 0.0;
        }

        $startTime = $model->getMeta($sessionId, FieldRegistry::SESSION_START_TIME);
        $endTime = $model->getMeta($sessionId, FieldRegistry::SESSION_END_TIME);

        if (!$startTime || !$endTime) {
            return 0.0;
        }

        // Parse times (HH:MM format)
        $start = strtotime($startTime);
        $end = strtotime($endTime);

        if ($start === false || $end === false || $end <= $start) {
            return 0.0;
        }

        return ($end - $start) / 3600; // Convert seconds to hours
    }

    /**
     * Get total scheduled hours for an edition (sum of all session durations)
     *
     * @param int $editionId Edition post ID
     * @return float Total hours
     */
    public function getTotalHours(int $editionId): float
    {
        $sessions = $this->getSessionsForEdition($editionId);
        $totalHours = 0.0;

        foreach ($sessions as $session) {
            $totalHours += $this->calculateDurationFromSession($session);
        }

        return $totalHours;
    }

    /**
     * Get total hours attended by a user for an edition
     *
     * Uses fresh attendee data via batch query to ensure recently marked
     * attendance is counted without N+1 query issues.
     *
     * @param int $userId WordPress user ID
     * @param int $editionId Edition post ID
     * @return float Total hours attended
     */
    public function getHoursAttended(int $userId, int $editionId): float
    {
        $sessions = $this->getSessionsForEdition($editionId);
        if (empty($sessions)) {
            return 0.0;
        }

        // Batch fetch fresh attendees for all sessions
        $sessionIds = array_column($sessions, 'id');
        $attendeesMap = $this->batchGetAttendees($sessionIds);

        $totalHours = 0.0;
        foreach ($sessions as $session) {
            $attendees = $attendeesMap[$session['id']] ?? [];
            if (in_array($userId, $attendees, true)) {
                $totalHours += $this->calculateDurationFromSession($session);
            }
        }

        return $totalHours;
    }

    /**
     * Get attendance rate for a user in an edition
     *
     * Uses fresh attendee data via batch query to ensure recently marked
     * attendance is counted without N+1 query issues.
     *
     * @param int $userId WordPress user ID
     * @param int $editionId Edition post ID
     * @return float Attendance rate (0.0 to 1.0)
     */
    public function getAttendanceRate(int $userId, int $editionId): float
    {
        $sessions = $this->getSessionsForEdition($editionId);
        $totalSessions = count($sessions);

        if ($totalSessions === 0) {
            return 0.0;
        }

        // Batch fetch fresh attendees for all sessions
        $sessionIds = array_column($sessions, 'id');
        $attendeesMap = $this->batchGetAttendees($sessionIds);

        $attendedCount = 0;
        foreach ($sessions as $session) {
            $attendees = $attendeesMap[$session['id']] ?? [];
            if (in_array($userId, $attendees, true)) {
                $attendedCount++;
            }
        }

        return $attendedCount / $totalSessions;
    }

    /**
     * Batch fetch attendees for multiple sessions
     *
     * Efficiently fetches attendees meta for multiple session IDs in a single query.
     *
     * @param array $sessionIds Array of session post IDs
     * @return array Map of session_id => attendees array
     */
    private function batchGetAttendees(array $sessionIds): array
    {
        if (empty($sessionIds)) {
            return [];
        }

        global $wpdb;

        // Batch fetch all attendees meta in a single query
        $placeholders = implode(',', array_fill(0, count($sessionIds), '%d'));
        $metaKey = FieldRegistry::SESSION_ATTENDEES;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = %s AND post_id IN ({$placeholders})",
            array_merge([$metaKey], $sessionIds)
        ), ARRAY_A);

        // Build map of session_id => attendees
        $attendeesMap = [];
        foreach ($results as $row) {
            $postId = (int) $row['post_id'];
            $value = $row['meta_value'];

            // Parse JSON or serialized array
            if (is_string($value)) {
                $parsed = json_decode($value, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $parsed = maybe_unserialize($value);
                }
                $attendees = is_array($parsed) ? $parsed : [];
            } else {
                $attendees = [];
            }

            $attendeesMap[$postId] = array_map('intval', $attendees);
        }

        // Ensure all session IDs have entries (empty array for sessions without attendees)
        foreach ($sessionIds as $id) {
            if (!isset($attendeesMap[$id])) {
                $attendeesMap[$id] = [];
            }
        }

        return $attendeesMap;
    }

    /**
     * Calculate duration from pre-loaded session data
     *
     * @param array $session Session data array with start_time and end_time
     * @return float Duration in hours
     */
    private function calculateDurationFromSession(array $session): float
    {
        $startTime = $session['start_time'] ?? '';
        $endTime = $session['end_time'] ?? '';

        if (!$startTime || !$endTime) {
            return 0.0;
        }

        $start = strtotime($startTime);
        $end = strtotime($endTime);

        if ($start === false || $end === false || $end <= $start) {
            return 0.0;
        }

        return ($end - $start) / 3600;
    }

    // ========================================
    // CRUD OPERATIONS
    // ========================================

    /**
     * Validate session data against business rules
     *
     * @param array $data Session data
     * @return true|WP_Error
     */
    private function validateSessionData(array $data): true|WP_Error
    {
        // Validate time range
        $startTime = $data[FieldRegistry::SESSION_START_TIME] ?? $data['start_time'] ?? '';
        $endTime = $data[FieldRegistry::SESSION_END_TIME] ?? $data['end_time'] ?? '';

        if ($startTime && $endTime) {
            $start = strtotime($startTime);
            $end = strtotime($endTime);

            if ($start !== false && $end !== false && $end <= $start) {
                return new WP_Error(
                    'invalid_time_range',
                    __('Eindtijd moet na starttijd liggen.', 'stride')
                );
            }
        }

        // Validate edition_id exists
        $editionId = $data[FieldRegistry::SESSION_EDITION_ID] ?? $data['edition_id'] ?? null;
        if ($editionId && !get_post($editionId)) {
            return new WP_Error(
                'invalid_edition',
                __('Gekoppelde editie niet gevonden.', 'stride')
            );
        }

        // Validate date format
        $date = $data[FieldRegistry::SESSION_DATE] ?? $data['date'] ?? '';
        if ($date && strtotime($date) === false) {
            return new WP_Error(
                'invalid_date',
                __('Ongeldige datumnotatie.', 'stride')
            );
        }

        return true;
    }

    /**
     * Create a new session
     *
     * @param array $data Session data
     * @return int|WP_Error Session ID or error
     */
    public function createSession(array $data): int|WP_Error
    {
        $model = $this->getModel();
        if (!$model) {
            return new WP_Error('no_model', 'DataManager not available');
        }

        // Validate business rules
        $validation = $this->validateSessionData($data);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Generate title from edition + date
        $editionTitle = '';
        if (!empty($data[FieldRegistry::SESSION_EDITION_ID])) {
            $editionTitle = get_the_title($data[FieldRegistry::SESSION_EDITION_ID]) ?: '';
        }
        $sessionDate = $data[FieldRegistry::SESSION_DATE] ?? '';
        $title = trim($editionTitle . ' - ' . $sessionDate);

        // Ensure attendees is an array
        if (!isset($data[FieldRegistry::SESSION_ATTENDEES])) {
            $data[FieldRegistry::SESSION_ATTENDEES] = [];
        }

        $createData = array_merge($data, ['title' => $title]);
        $result = $model->create($createData);

        if (is_wp_error($result)) {
            return $result;
        }

        return $result->ID;
    }

    /**
     * Update a session
     *
     * @param int $sessionId Session ID
     * @param array $data Data to update
     * @return true|WP_Error
     */
    public function updateSession(int $sessionId, array $data): true|WP_Error
    {
        $model = $this->getModel();
        if (!$model) {
            return new WP_Error('no_model', 'DataManager not available');
        }

        // Validate business rules
        $validation = $this->validateSessionData($data);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $result = $model->update($sessionId, $data);
        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    // ========================================
    // FORMATTING
    // ========================================

    /**
     * Format session post to array
     */
    private function formatSession(\WP_Post $post): array
    {
        $attendees = $post->fields[FieldRegistry::SESSION_ATTENDEES] ?? [];
        if (is_string($attendees)) {
            $attendees = json_decode($attendees, true) ?: [];
        }

        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'edition_id' => (int) ($post->fields[FieldRegistry::SESSION_EDITION_ID] ?? 0),
            'date' => $post->fields[FieldRegistry::SESSION_DATE] ?? '',
            'start_time' => $post->fields[FieldRegistry::SESSION_START_TIME] ?? '',
            'end_time' => $post->fields[FieldRegistry::SESSION_END_TIME] ?? '',
            'location' => $post->fields[FieldRegistry::SESSION_LOCATION] ?? '',
            'attendees' => array_map('intval', (array) $attendees),
        ];
    }

    /**
     * Format session from array (from DataManager query)
     */
    private function formatSessionFromArray(array $data): array
    {
        $meta = $data['meta'] ?? [];
        $attendees = $meta[FieldRegistry::SESSION_ATTENDEES] ?? [];

        if (is_string($attendees)) {
            $attendees = json_decode($attendees, true) ?: [];
        }

        return [
            'id' => $data['id'] ?? 0,
            'title' => $data['title'] ?? '',
            'edition_id' => (int) ($meta[FieldRegistry::SESSION_EDITION_ID] ?? 0),
            'date' => $meta[FieldRegistry::SESSION_DATE] ?? '',
            'start_time' => $meta[FieldRegistry::SESSION_START_TIME] ?? '',
            'end_time' => $meta[FieldRegistry::SESSION_END_TIME] ?? '',
            'location' => $meta[FieldRegistry::SESSION_LOCATION] ?? '',
            'attendees' => array_map('intval', (array) $attendees),
        ];
    }
}
