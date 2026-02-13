<?php

namespace ntdst\Stride\core;

defined('ABSPATH') || exit;

use ntdst\Stride\FieldRegistry;
use ntdst\Stride\core\AttendanceRepository;
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

    private ?AttendanceRepository $attendanceRepo = null;

    /** @var array Request-level cache for sessions by edition */
    private static array $sessionCache = [];

    /**
     * Invalidate session cache for an edition
     */
    public static function invalidateCache(?int $editionId = null): void
    {
        if ($editionId === null) {
            self::$sessionCache = [];
        } else {
            unset(self::$sessionCache[$editionId]);
        }
    }

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
    public function __construct(?AttendanceRepository $attendanceRepo = null)
    {
        $this->attendanceRepo = $attendanceRepo;

        // Register CPT via DataManager
        add_action('init', [$this, 'registerModel'], 5);

        // Create attendance table on activation
        add_action('init', [$this, 'ensureAttendanceTable'], 6);
    }

    /**
     * Get AttendanceRepository (lazy loaded)
     */
    private function getAttendanceRepo(): AttendanceRepository
    {
        if ($this->attendanceRepo === null) {
            $this->attendanceRepo = new AttendanceRepository();
        }
        return $this->attendanceRepo;
    }

    /**
     * Ensure attendance table exists
     */
    public function ensureAttendanceTable(): void
    {
        $repo = $this->getAttendanceRepo();
        if (!$repo->tableExists()) {
            $repo->createTable();
        }
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
        // Check request-level cache
        if (isset(self::$sessionCache[$editionId])) {
            return self::$sessionCache[$editionId];
        }

        $model = $this->getModel();
        if (!$model) {
            return [];
        }

        $posts = $model
            ->where(FieldRegistry::SESSION_EDITION_ID, $editionId)
            ->orderBy(FieldRegistry::SESSION_DATE, 'ASC')
            ->withMeta()
            ->get();

        $result = array_map([$this, 'formatSessionFromArray'], $posts);

        // Cache for this request
        self::$sessionCache[$editionId] = $result;

        return $result;
    }

    /**
     * Get session count for an edition
     *
     * @param int $editionId Edition post ID
     * @return int Number of sessions
     */
    public function getSessionCount(int $editionId): int
    {
        // Use cached sessions if available
        if (isset(self::$sessionCache[$editionId])) {
            return count(self::$sessionCache[$editionId]);
        }

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

        $wasPresent = $this->isPresent($sessionId, $userId);
        $result = $this->getAttendanceRepo()->mark(
            $sessionId,
            $userId,
            AttendanceRepository::STATUS_PRESENT,
            get_current_user_id()
        );

        if ($result && !$wasPresent) {
            do_action('stride/session/attendance_marked', $sessionId, $userId, true);
        }

        return $result ? true : new WP_Error('mark_failed', __('Kon aanwezigheid niet markeren.', 'stride'));
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

        $wasPresent = $this->isPresent($sessionId, $userId);
        $result = $this->getAttendanceRepo()->mark(
            $sessionId,
            $userId,
            AttendanceRepository::STATUS_ABSENT,
            get_current_user_id()
        );

        if ($result && $wasPresent) {
            do_action('stride/session/attendance_marked', $sessionId, $userId, false);
        }

        return $result ? true : new WP_Error('mark_failed', __('Kon afwezigheid niet markeren.', 'stride'));
    }

    /**
     * Mark a user as excused for a session
     *
     * @param int $sessionId Session post ID
     * @param int $userId WordPress user ID
     * @return true|WP_Error True on success, WP_Error on failure
     */
    public function markExcused(int $sessionId, int $userId): true|WP_Error
    {
        if (!$this->canManageAttendance()) {
            return new WP_Error('unauthorized', __('Je hebt geen rechten om aanwezigheid te beheren.', 'stride'));
        }

        $result = $this->getAttendanceRepo()->mark(
            $sessionId,
            $userId,
            AttendanceRepository::STATUS_EXCUSED,
            get_current_user_id()
        );

        if ($result) {
            do_action('stride/session/attendance_marked', $sessionId, $userId, 'excused');
        }

        return $result ? true : new WP_Error('mark_failed', __('Kon verontschuldiging niet markeren.', 'stride'));
    }

    /**
     * Batch mark attendance for multiple users
     *
     * @param int $sessionId Session post ID
     * @param array $userStatuses Array of [user_id => status]
     * @return int Number of records updated
     */
    public function batchMarkAttendance(int $sessionId, array $userStatuses): int
    {
        if (!$this->canManageAttendance()) {
            return 0;
        }

        $count = $this->getAttendanceRepo()->batchMark($sessionId, $userStatuses, get_current_user_id());

        if ($count > 0) {
            do_action('stride/session/batch_attendance_marked', $sessionId, $userStatuses);
        }

        return $count;
    }

    /**
     * Check if current user can manage attendance
     *
     * @return bool
     */
    public function canManageAttendance(): bool
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
        return $this->getAttendanceRepo()->isPresent($sessionId, $userId);
    }

    /**
     * Get attendance status for a user in a session
     *
     * @param int $sessionId Session post ID
     * @param int $userId WordPress user ID
     * @return string|null Status or null if not recorded
     */
    public function getAttendanceStatus(int $sessionId, int $userId): ?string
    {
        return $this->getAttendanceRepo()->getStatus($sessionId, $userId);
    }

    /**
     * Get all attendees for a session (present users)
     *
     * @param int $sessionId Session post ID
     * @return array Array of user IDs
     */
    public function getAttendees(int $sessionId): array
    {
        return $this->getAttendanceRepo()->getAttendeesForSession($sessionId, AttendanceRepository::STATUS_PRESENT);
    }

    /**
     * Get attendance records with full details for a session
     *
     * @param int $sessionId Session post ID
     * @return array Array of attendance records
     */
    public function getSessionAttendance(int $sessionId): array
    {
        return $this->getAttendanceRepo()->getSessionAttendance($sessionId);
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
     * Uses AttendanceRepository for efficient batch queries.
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

        // Batch fetch attendance using repository
        $sessionIds = array_column($sessions, 'id');
        $attendeesMap = $this->getAttendanceRepo()->batchGetAttendees($sessionIds, AttendanceRepository::STATUS_PRESENT);

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
     * Uses AttendanceRepository for efficient counting.
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

        // Use repository for efficient count
        $attendedCount = $this->getAttendanceRepo()->countAttendedSessions($userId, $editionId);

        return $attendedCount / $totalSessions;
    }

    /**
     * Count attended sessions for a user in an edition
     *
     * @param int $userId WordPress user ID
     * @param int $editionId Edition post ID
     * @return int Number of sessions attended
     */
    public function countAttendedSessions(int $userId, int $editionId): int
    {
        return $this->getAttendanceRepo()->countAttendedSessions($userId, $editionId);
    }

    /**
     * Batch fetch attendees for multiple sessions
     *
     * @param array $sessionIds Array of session post IDs
     * @return array Map of session_id => attendees array
     */
    public function batchGetAttendees(array $sessionIds): array
    {
        return $this->getAttendanceRepo()->batchGetAttendees($sessionIds, AttendanceRepository::STATUS_PRESENT);
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

        // Invalidate cache for this edition
        $editionId = $data[FieldRegistry::SESSION_EDITION_ID] ?? 0;
        if ($editionId) {
            self::invalidateCache((int) $editionId);
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

        // Invalidate cache for this edition
        $editionId = $data[FieldRegistry::SESSION_EDITION_ID]
            ?? get_post_meta($sessionId, FieldRegistry::SESSION_EDITION_ID, true);
        if ($editionId) {
            self::invalidateCache((int) $editionId);
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
