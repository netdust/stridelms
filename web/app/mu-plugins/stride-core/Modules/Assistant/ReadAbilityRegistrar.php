<?php

declare(strict_types=1);

namespace Stride\Modules\Assistant;

use Stride\Infrastructure\AbstractService;

/**
 * Registers Stride read-only abilities for the AI assistant.
 *
 * Owns:
 * - 9 read abilities: search-users, get-edition, get-editions, get-enrollments, get-stats, get-attendance,
 *   export-editions, export-enrollments, export-attendance
 * - Category registration (stride)
 * - System prompt injection (domain + formatting prompts)
 */
final class ReadAbilityRegistrar extends AbstractService
{
    public static function metadata(): array
    {
        return [
            'name' => 'Assistant Read Ability Registrar',
            'description' => 'Registers Stride read-only abilities for the AI assistant',
            'priority' => 90, // Late — after all domain services
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'assistant';
    }

    protected function init(): void
    {
        add_action('wp_abilities_api_categories_init', [$this, 'registerCategories']);
        add_action('wp_abilities_api_init', [$this, 'registerAbilities']);
        add_filter('ntdst_assistant/system_prompt', [$this, 'injectDomainPrompts'], 10, 2);
    }

    // ---------------------------------------------------------------
    // Category registration
    // ---------------------------------------------------------------

    public function registerCategories(): void
    {
        wp_register_ability_category('stride', [
            'label'       => 'Stride LMS',
            'description' => 'Abilities for managing Stride LMS editions, enrollments, and users.',
        ]);
    }

    // ---------------------------------------------------------------
    // Ability registration
    // ---------------------------------------------------------------

    public function registerAbilities(): void
    {
        // Search users
        wp_register_ability('stride/search-users', [
            'label' => 'Gebruikers zoeken',
            'description' => 'Search WordPress users by name, email, or login. Returns id, display_name, and email.',
            'category' => 'stride',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Search term (name, email, or login)',
                    ],
                    'per_page' => [
                        'type' => 'integer',
                        'description' => 'Max results (default 10, max 50)',
                    ],
                ],
                'required' => ['query'],
            ],
            'permission_callback' => fn() => current_user_can('stride_view'),
            'execute_callback' => [$this, 'searchUsers'],
            'meta' => [
                'show_in_rest' => true,
                'annotations' => ['readonly' => true],
                'readonly' => true,
            ],
        ]);

        // Get single edition
        wp_register_ability('stride/get-edition', [
            'label' => 'Editie ophalen',
            'description' => 'Get a single edition by ID with its metadata (course, dates, price, capacity, status).',
            'category' => 'stride',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'edition_id' => [
                        'type' => 'integer',
                        'description' => 'Edition post ID',
                    ],
                ],
                'required' => ['edition_id'],
            ],
            'permission_callback' => fn() => current_user_can('stride_view'),
            'execute_callback' => [$this, 'getEdition'],
            'meta' => [
                'show_in_rest' => true,
                'annotations' => ['readonly' => true],
                'readonly' => true,
            ],
        ]);

        // Get editions
        wp_register_ability('stride/get-editions', [
            'label' => 'Edities oplijsten',
            'description' => 'List editions with optional filters (course_id, status, date range, upcoming). Returns title, dates, status, and capacity.',
            'category' => 'stride',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'course_id' => [
                        'type' => 'integer',
                        'description' => 'Filter by course ID',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Filter by status: open, full, cancelled',
                    ],
                    'start_date_from' => [
                        'type' => 'string',
                        'description' => 'Start date range begin (YYYY-MM-DD). Example: 2026-03-01 for "from March"',
                    ],
                    'start_date_to' => [
                        'type' => 'string',
                        'description' => 'Start date range end (YYYY-MM-DD). Example: 2026-03-31 for "until end of March"',
                    ],
                    'upcoming' => [
                        'type' => 'boolean',
                        'description' => 'Only show upcoming editions (start_date >= today)',
                    ],
                    'per_page' => [
                        'type' => 'integer',
                        'description' => 'Max results (default 20, max 50)',
                    ],
                ],
            ],
            'permission_callback' => fn() => current_user_can('stride_view'),
            'execute_callback' => [$this, 'getEditions'],
            'meta' => [
                'show_in_rest' => true,
                'annotations' => ['readonly' => true],
                'readonly' => true,
            ],
        ]);

        // Get enrollments
        wp_register_ability('stride/get-enrollments', [
            'label' => 'Inschrijvingen oplijsten',
            'description' => 'List enrollments filtered by edition_id or user_id. Returns registration details with status.',
            'category' => 'stride',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'edition_id' => [
                        'type' => 'integer',
                        'description' => 'Filter by edition ID',
                    ],
                    'user_id' => [
                        'type' => 'integer',
                        'description' => 'Filter by user ID',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Filter by status: pending, confirmed, completed, cancelled',
                    ],
                ],
            ],
            'permission_callback' => fn() => current_user_can('stride_view'),
            'execute_callback' => [$this, 'getEnrollments'],
            'meta' => [
                'show_in_rest' => true,
                'annotations' => ['readonly' => true],
                'readonly' => true,
            ],
        ]);

        // Get statistics
        wp_register_ability('stride/get-stats', [
            'label' => 'Statistieken ophalen',
            'description' => 'Get aggregated statistics for an edition, course, or globally. Returns enrollment counts, fill rate, attendance rate, and completion rate (edition scope only).',
            'category' => 'stride',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'edition_id' => [
                        'type' => 'integer',
                        'description' => 'Specifieke editie (optioneel)',
                    ],
                    'course_id' => [
                        'type' => 'integer',
                        'description' => 'Cursus-ID om edities te filteren (optioneel)',
                    ],
                ],
            ],
            'permission_callback' => fn() => current_user_can('stride_view'),
            'execute_callback' => [$this, 'getStats'],
            'meta' => [
                'show_in_rest' => true,
                'annotations' => ['readonly' => true],
                'readonly' => true,
            ],
        ]);

        // Export editions
        wp_register_ability('stride/export-editions', [
            'label' => 'Edities exporteren als CSV',
            'description' => 'Export editions to a CSV file. Returns a signed download link. Optional filters: course_id, status, date range.',
            'category' => 'stride',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'course_id' => ['type' => 'integer', 'description' => 'Filter op cursus-ID (optioneel)'],
                    'status' => ['type' => 'string', 'description' => 'Filter op status: open, full, cancelled (optioneel)'],
                    'start_date_from' => ['type' => 'string', 'description' => 'Startdatum bereik begin (YYYY-MM-DD), bijv. 2026-03-01'],
                    'start_date_to' => ['type' => 'string', 'description' => 'Startdatum bereik einde (YYYY-MM-DD), bijv. 2026-03-31'],
                ],
            ],
            'permission_callback' => fn() => current_user_can('stride_view'),
            'execute_callback' => [$this, 'exportEditions'],
            'meta' => [
                'show_in_rest' => true,
                'annotations' => ['readonly' => true],
                'readonly' => true,
            ],
        ]);

        // Export enrollments
        wp_register_ability('stride/export-enrollments', [
            'label' => 'Inschrijvingen exporteren als CSV',
            'description' => 'Export enrollments to a CSV file. Returns a signed download link. Filter by edition_id or user_id.',
            'category' => 'stride',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'edition_id' => ['type' => 'integer', 'description' => 'Filter op editie-ID (optioneel)'],
                    'user_id' => ['type' => 'integer', 'description' => 'Filter op gebruiker-ID (optioneel)'],
                    'status' => ['type' => 'string', 'description' => 'Filter op status (optioneel)'],
                ],
            ],
            'permission_callback' => fn() => current_user_can('stride_view'),
            'execute_callback' => [$this, 'exportEnrollments'],
            'meta' => [
                'show_in_rest' => true,
                'annotations' => ['readonly' => true],
                'readonly' => true,
            ],
        ]);

        // Export attendance
        wp_register_ability('stride/export-attendance', [
            'label' => 'Aanwezigheid exporteren als CSV',
            'description' => 'Export attendance records to a CSV file. Returns a signed download link. Requires edition_id or session_id.',
            'category' => 'stride',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'edition_id' => ['type' => 'integer', 'description' => 'Editie-ID (optioneel, maar minstens 1 filter vereist)'],
                    'session_id' => ['type' => 'integer', 'description' => 'Sessie-ID (optioneel)'],
                ],
            ],
            'permission_callback' => fn() => current_user_can('stride_view'),
            'execute_callback' => [$this, 'exportAttendance'],
            'meta' => [
                'show_in_rest' => true,
                'annotations' => ['readonly' => true],
                'readonly' => true,
            ],
        ]);

        // Get attendance
        wp_register_ability('stride/get-attendance', [
            'label' => 'Aanwezigheid ophalen',
            'description' => 'Get attendance records for a session, user-in-edition, edition matrix, or all attendance for a user. Returns records with status, summary, and attendance rate.',
            'category' => 'stride',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => ['type' => 'integer', 'description' => 'Sessie-ID (optioneel)'],
                    'edition_id' => ['type' => 'integer', 'description' => 'Editie-ID (optioneel)'],
                    'user_id' => ['type' => 'integer', 'description' => 'Gebruiker-ID (optioneel)'],
                ],
            ],
            'permission_callback' => fn() => current_user_can('stride_view'),
            'execute_callback' => [$this, 'getAttendance'],
            'meta' => [
                'show_in_rest' => true,
                'annotations' => ['readonly' => true],
                'readonly' => true,
            ],
        ]);
    }

    // ---------------------------------------------------------------
    // Execute callbacks — READ
    // ---------------------------------------------------------------

    /**
     * Search WordPress users by name, email, or login.
     *
     * @param array{query: string, per_page?: int} $input
     * @return array{users: array<array{id: int, display_name: string, email: string}>}
     */
    public function searchUsers(array $input): array
    {
        $query = sanitize_text_field($input['query'] ?? '');
        $perPage = min(50, max(1, (int) ($input['per_page'] ?? 10)));

        if ($query === '') {
            return ['users' => []];
        }

        $userQuery = new \WP_User_Query([
            'search' => '*' . $query . '*',
            'search_columns' => ['user_login', 'user_email', 'user_nicename', 'display_name'],
            'number' => $perPage,
            'orderby' => 'display_name',
            'order' => 'ASC',
            'fields' => ['ID', 'display_name', 'user_email'],
        ]);

        $users = [];
        foreach ($userQuery->get_results() as $user) {
            $users[] = [
                'id' => (int) $user->ID,
                'display_name' => $user->display_name,
                'email' => $user->user_email,
                '_links' => [
                    'user_edit' => admin_url("user-edit.php?user_id={$user->ID}"),
                ],
            ];
        }

        return ['users' => $users];
    }

    /**
     * Get a single edition by ID with metadata.
     *
     * @param array{edition_id: int} $input
     * @return array<string, mixed>|\WP_Error
     */
    public function getEdition(array $input): array|\WP_Error
    {
        $editionId = (int) ($input['edition_id'] ?? 0);

        if ($editionId <= 0) {
            return new \WP_Error('invalid_id', 'Edition ID is required.');
        }

        $editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);
        $repository = ntdst_get(\Stride\Modules\Edition\EditionRepository::class);
        $edition = $repository->find($editionId);

        if (is_wp_error($edition)) {
            return $edition;
        }

        return [
            'id' => $edition->ID,
            'title' => $edition->post_title,
            'status' => $editionService->getStatus($editionId)->value,
            'course_id' => $editionService->getCourseId($editionId),
            'price' => $editionService->getPrice($editionId)->format(),
            'capacity' => $editionService->getCapacity($editionId),
            'registered_count' => $editionService->getRegisteredCount($editionId),
            'can_enroll' => $editionService->canEnroll($editionId),
            'start_date' => $repository->getField($editionId, 'start_date'),
            'end_date' => $repository->getField($editionId, 'end_date'),
            'venue' => $repository->getField($editionId, 'venue'),
            '_links' => [
                'edition_edit' => admin_url("post.php?post={$edition->ID}&action=edit"),
            ],
        ];
    }

    /**
     * List editions with optional filters.
     *
     * @param array{course_id?: int, status?: string, upcoming?: bool, per_page?: int} $input
     * @return array{editions: array<array<string, mixed>>}
     */
    public function getEditions(array $input): array
    {
        $editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);
        $editionRepo = ntdst_get(\Stride\Modules\Edition\EditionRepository::class);
        $perPage = min(50, max(1, (int) ($input['per_page'] ?? 20)));

        $filters = $this->buildEditionFilters($input);

        if (!empty($filters)) {
            $raw = $editionRepo->findByFilters($filters, $perPage);
        } else {
            $raw = $editionRepo->findUpcoming($perPage);
        }

        $items = array_slice($raw, 0, $perPage);

        // Collect all edition IDs for batch operations
        $editionIds = [];
        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? $item['ID'] ?? 0);
            if ($id > 0) {
                $editionIds[] = $id;
            }
        }

        if (empty($editionIds)) {
            return ['editions' => []];
        }

        // Prime post meta cache in a single query (for getStatus/getCapacity calls)
        update_postmeta_cache($editionIds);

        // Batch registration counts in a single grouped query
        $registeredCounts = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class)
            ->countByEditions($editionIds);

        $editions = [];
        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? $item['ID'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            // Use meta from the query result when available (findUpcoming uses withMeta)
            $meta = $item['meta'] ?? [];
            $startDate = $meta['start_date'] ?? $editionRepo->getField($id, 'start_date');
            $endDate = $meta['end_date'] ?? $editionRepo->getField($id, 'end_date');
            $venue = $meta['venue'] ?? $editionRepo->getField($id, 'venue');

            $capacity = (int) ($meta['capacity'] ?? $editionService->getCapacity($id));
            $registered = $registeredCounts[$id] ?? 0;
            $status = $editionService->getStatus($id);

            $editions[] = [
                'id' => $id,
                'title' => $item['title'] ?? $item['post_title'] ?? '',
                'status' => $status->value,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'venue' => $venue,
                'capacity' => $capacity,
                'registered' => $registered,
                'can_enroll' => $status->allowsEnrollment() && ($capacity === 0 || $registered < $capacity),
                '_links' => [
                    'edition_edit' => admin_url("post.php?post={$id}&action=edit"),
                ],
            ];
        }

        return ['editions' => $editions];
    }

    /**
     * List enrollments by edition or user.
     *
     * @param array{edition_id?: int, user_id?: int, status?: string} $input
     * @return array{enrollments: array<array<string, mixed>>}
     */
    public function getEnrollments(array $input): array
    {
        $repo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);

        $editionId = (int) ($input['edition_id'] ?? 0);
        $userId = (int) ($input['user_id'] ?? 0);
        $status = $input['status'] ?? null;

        if ($editionId > 0) {
            $raw = $repo->findByEdition($editionId, $status);
        } elseif ($userId > 0) {
            $raw = $repo->findByUser($userId, $status);
        } else {
            return ['enrollments' => [], 'message' => 'Provide edition_id or user_id to filter enrollments.'];
        }

        // Batch-prime user and post caches to avoid N+1
        $userIds = array_unique(array_map(fn($r) => (int) $r->user_id, $raw));
        $editionIds = array_unique(array_filter(array_map(fn($r) => (int) ($r->edition_id ?? 0), $raw)));

        if (!empty($userIds)) {
            // WP_User_Query with 'include' primes the user cache
            new \WP_User_Query(['include' => $userIds, 'fields' => 'all_with_meta']);
        }
        if (!empty($editionIds)) {
            _prime_post_caches($editionIds, true, true);
        }

        $enrollments = [];
        foreach ($raw as $reg) {
            $user = get_userdata((int) $reg->user_id);
            $edition = get_post((int) ($reg->edition_id ?? 0));

            $enrollments[] = [
                'id' => (int) $reg->id,
                'user_id' => (int) $reg->user_id,
                'user_name' => $user ? $user->display_name : '(onbekend)',
                'edition_id' => (int) ($reg->edition_id ?? 0),
                'edition_title' => $edition ? $edition->post_title : '(onbekend)',
                'status' => $reg->status,
                'registered_at' => $reg->registered_at ?? null,
                'enrollment_path' => $reg->enrollment_path ?? 'individual',
                '_links' => [
                    'user_edit' => admin_url("user-edit.php?user_id={$reg->user_id}"),
                    'edition_edit' => admin_url("post.php?post=" . ($reg->edition_id ?? 0) . "&action=edit"),
                ],
            ];
        }

        return ['enrollments' => $enrollments];
    }

    /**
     * Get aggregated statistics for an edition, course, or globally.
     *
     * @param array{edition_id?: int, course_id?: int} $input
     * @return array<string, mixed>
     */
    public function getStats(array $input): array
    {
        $editionId = (int) ($input['edition_id'] ?? 0);
        $courseId = (int) ($input['course_id'] ?? 0);

        if ($editionId > 0) {
            return $this->getEditionStats($editionId);
        }
        if ($courseId > 0) {
            return $this->getCourseStats($courseId);
        }
        return $this->getGlobalStats();
    }

    /**
     * Get attendance records for a session, user-in-edition, edition matrix, or all attendance for a user.
     *
     * @param array{session_id?: int, edition_id?: int, user_id?: int} $input
     * @return array<string, mixed>|\WP_Error
     */
    public function getAttendance(array $input): array|\WP_Error
    {
        $sessionId = (int) ($input['session_id'] ?? 0);
        $editionId = (int) ($input['edition_id'] ?? 0);
        $userId = (int) ($input['user_id'] ?? 0);

        if ($sessionId <= 0 && $editionId <= 0 && $userId <= 0) {
            return new \WP_Error('missing_filter', 'Geef session_id, edition_id, of user_id mee.');
        }

        $attendance = ntdst_get(\Stride\Modules\Attendance\AttendanceService::class);
        $sessions = ntdst_get(\Stride\Modules\Edition\SessionService::class);

        if ($sessionId > 0) {
            return $this->getSessionAttendance($attendance, $sessions, $sessionId);
        }
        if ($userId > 0 && $editionId > 0) {
            return $this->getUserEditionAttendance($attendance, $sessions, $userId, $editionId);
        }
        if ($editionId > 0) {
            return $this->getEditionAttendanceMatrix($attendance, $sessions, $editionId);
        }

        return $this->getUserAttendanceAll($attendance, $sessions, $userId);
    }

    /**
     * Attendance records for a single session.
     */
    private function getSessionAttendance(
        \Stride\Modules\Attendance\AttendanceService $attendance,
        \Stride\Modules\Edition\SessionService $sessions,
        int $sessionId,
    ): array {
        $records = $attendance->getSessionAttendance($sessionId);
        $session = $sessions->getSession($sessionId);

        // Batch-hydrate users
        $userIds = array_unique(array_column($records, 'user_id'));
        $usersMap = $this->batchLoadUsers($userIds);

        $sessionDate = $session['date'] ?? '';
        $sessionTime = '';
        if (!empty($session['start_time']) && !empty($session['end_time'])) {
            $sessionTime = $session['start_time'] . '-' . $session['end_time'];
        }

        $formatted = [];
        $summary = ['present' => 0, 'absent' => 0, 'excused' => 0, 'total' => 0];

        foreach ($records as $record) {
            $uid = (int) $record['user_id'];
            $user = $usersMap[$uid] ?? null;
            $status = $record['status'];

            $formatted[] = [
                'user_id' => $uid,
                'user_name' => $user ? $user->display_name : '(onbekend)',
                'user_email' => $user ? $user->user_email : '',
                'session_id' => $sessionId,
                'session_date' => $sessionDate,
                'session_time' => $sessionTime,
                'status' => $status,
                'marked_by' => $record['marked_by'],
                'marked_at' => $record['marked_at'],
                '_links' => ['user_edit' => admin_url("user-edit.php?user_id={$uid}")],
            ];

            $summary['total']++;
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }

        $attendanceRate = $summary['total'] > 0
            ? round(($summary['present'] + $summary['excused']) / $summary['total'] * 100, 1)
            : 0.0;

        return [
            'records' => $formatted,
            'summary' => $summary,
            'attendance_rate' => $attendanceRate,
        ];
    }

    /**
     * Attendance records for a single user in a single edition.
     */
    private function getUserEditionAttendance(
        \Stride\Modules\Attendance\AttendanceService $attendance,
        \Stride\Modules\Edition\SessionService $sessions,
        int $userId,
        int $editionId,
    ): array {
        $records = $attendance->getUserEditionAttendance($userId, $editionId);
        $user = get_userdata($userId);

        $formatted = [];
        $summary = ['present' => 0, 'absent' => 0, 'excused' => 0, 'total' => 0];

        foreach ($records as $record) {
            $session = $sessions->getSession((int) $record['session_id']);
            $sessionDate = $session['date'] ?? '';
            $sessionTime = '';
            if (!empty($session['start_time']) && !empty($session['end_time'])) {
                $sessionTime = $session['start_time'] . '-' . $session['end_time'];
            }

            $status = $record['status'];

            $formatted[] = [
                'user_id' => $userId,
                'user_name' => $user ? $user->display_name : '(onbekend)',
                'user_email' => $user ? $user->user_email : '',
                'session_id' => (int) $record['session_id'],
                'session_date' => $sessionDate,
                'session_time' => $sessionTime,
                'status' => $status,
                'marked_by' => $record['marked_by'],
                'marked_at' => $record['marked_at'],
                '_links' => ['user_edit' => admin_url("user-edit.php?user_id={$userId}")],
            ];

            $summary['total']++;
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }

        $attendanceRate = $summary['total'] > 0
            ? round(($summary['present'] + $summary['excused']) / $summary['total'] * 100, 1)
            : 0.0;

        return [
            'records' => $formatted,
            'summary' => $summary,
            'attendance_rate' => $attendanceRate,
        ];
    }

    /**
     * Attendance matrix for an entire edition (all sessions, all users). Capped at 500 records.
     */
    private function getEditionAttendanceMatrix(
        \Stride\Modules\Attendance\AttendanceService $attendance,
        \Stride\Modules\Edition\SessionService $sessions,
        int $editionId,
    ): array {
        $editionSessions = $sessions->getSessionsForEdition($editionId);

        $allRecords = [];
        $truncated = false;

        foreach ($editionSessions as $session) {
            $sid = (int) $session['id'];
            $sessionRecords = $attendance->getSessionAttendance($sid);

            foreach ($sessionRecords as $record) {
                $record['session_id'] = $sid;
                $record['session_date'] = $session['date'] ?? '';
                $record['session_time'] = '';
                if (!empty($session['start_time']) && !empty($session['end_time'])) {
                    $record['session_time'] = $session['start_time'] . '-' . $session['end_time'];
                }
                $allRecords[] = $record;

                if (count($allRecords) >= 500) {
                    $truncated = true;
                    break 2;
                }
            }
        }

        // Batch-hydrate users
        $userIds = array_unique(array_column($allRecords, 'user_id'));
        $usersMap = $this->batchLoadUsers($userIds);

        $formatted = [];
        $summary = ['present' => 0, 'absent' => 0, 'excused' => 0, 'total' => 0];

        foreach ($allRecords as $record) {
            $uid = (int) $record['user_id'];
            $user = $usersMap[$uid] ?? null;
            $status = $record['status'];

            $formatted[] = [
                'user_id' => $uid,
                'user_name' => $user ? $user->display_name : '(onbekend)',
                'user_email' => $user ? $user->user_email : '',
                'session_id' => (int) $record['session_id'],
                'session_date' => $record['session_date'],
                'session_time' => $record['session_time'],
                'status' => $status,
                'marked_by' => $record['marked_by'],
                'marked_at' => $record['marked_at'],
                '_links' => ['user_edit' => admin_url("user-edit.php?user_id={$uid}")],
            ];

            $summary['total']++;
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }

        $attendanceRate = $summary['total'] > 0
            ? round(($summary['present'] + $summary['excused']) / $summary['total'] * 100, 1)
            : 0.0;

        $result = [
            'records' => $formatted,
            'summary' => $summary,
            'attendance_rate' => $attendanceRate,
        ];

        if ($truncated) {
            $result['truncated'] = true;
            $result['message'] = 'Resultaat afgekapt op 500 rijen.';
        }

        return $result;
    }

    /**
     * All attendance for a user across all enrollments. Capped at 200 records.
     */
    private function getUserAttendanceAll(
        \Stride\Modules\Attendance\AttendanceService $attendance,
        \Stride\Modules\Edition\SessionService $sessions,
        int $userId,
    ): array {
        $regRepo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
        $registrations = $regRepo->findByUser($userId);
        $user = get_userdata($userId);

        $allRecords = [];
        $truncated = false;

        foreach ($registrations as $reg) {
            $editionId = (int) ($reg->edition_id ?? 0);
            if ($editionId <= 0) {
                continue;
            }

            $records = $attendance->getUserEditionAttendance($userId, $editionId);

            foreach ($records as $record) {
                $session = $sessions->getSession((int) $record['session_id']);
                $record['session_date'] = $session['date'] ?? '';
                $record['session_time'] = '';
                if (!empty($session['start_time']) && !empty($session['end_time'])) {
                    $record['session_time'] = $session['start_time'] . '-' . $session['end_time'];
                }
                $allRecords[] = $record;

                if (count($allRecords) >= 200) {
                    $truncated = true;
                    break 2;
                }
            }
        }

        $formatted = [];
        $summary = ['present' => 0, 'absent' => 0, 'excused' => 0, 'total' => 0];

        foreach ($allRecords as $record) {
            $status = $record['status'];

            $formatted[] = [
                'user_id' => $userId,
                'user_name' => $user ? $user->display_name : '(onbekend)',
                'user_email' => $user ? $user->user_email : '',
                'session_id' => (int) $record['session_id'],
                'session_date' => $record['session_date'],
                'session_time' => $record['session_time'],
                'status' => $status,
                'marked_by' => $record['marked_by'],
                'marked_at' => $record['marked_at'],
                '_links' => ['user_edit' => admin_url("user-edit.php?user_id={$userId}")],
            ];

            $summary['total']++;
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }

        $attendanceRate = $summary['total'] > 0
            ? round(($summary['present'] + $summary['excused']) / $summary['total'] * 100, 1)
            : 0.0;

        $result = [
            'records' => $formatted,
            'summary' => $summary,
            'attendance_rate' => $attendanceRate,
        ];

        if ($truncated) {
            $result['truncated'] = true;
            $result['message'] = 'Resultaat afgekapt op 200 rijen.';
        }

        return $result;
    }

    /**
     * Batch-load WP users by ID into an associative map.
     *
     * @param array<int> $userIds
     * @return array<int, \WP_User> Map of user_id => WP_User
     */
    private function batchLoadUsers(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $query = new \WP_User_Query([
            'include' => $userIds,
            'fields' => 'all',
        ]);

        $map = [];
        foreach ($query->get_results() as $user) {
            $map[(int) $user->ID] = $user;
        }

        return $map;
    }

    /**
     * Statistics for a single edition.
     */
    private function getEditionStats(int $editionId): array
    {
        $editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);
        $editionRepo = ntdst_get(\Stride\Modules\Edition\EditionRepository::class);
        $regRepo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
        $attendance = ntdst_get(\Stride\Modules\Attendance\AttendanceService::class);

        $edition = $editionRepo->find($editionId);
        if (is_wp_error($edition)) {
            return [
                'scope' => 'edition',
                'edition_count' => 0,
                'total_enrolled' => 0,
                'total_capacity' => 0,
                'fill_rate' => null,
                'status_breakdown' => [],
                'average_attendance_rate' => null,
                'completion_rate' => null,
                'message' => 'Editie niet gevonden.',
            ];
        }

        $capacity = $editionService->getCapacity($editionId);
        $registrations = $regRepo->findByEdition($editionId);

        // Status breakdown
        $statusBreakdown = [];
        foreach ($registrations as $reg) {
            $status = $reg->status;
            $statusBreakdown[$status] = ($statusBreakdown[$status] ?? 0) + 1;
        }

        $enrolled = $editionService->getRegisteredCount($editionId);
        $fillRate = $capacity > 0 ? round(($enrolled / $capacity) * 100, 1) : null;

        // Attendance rate — average across confirmed users
        $confirmed = $regRepo->findByEdition($editionId, 'confirmed');
        $attendanceRate = null;
        if (!empty($confirmed)) {
            $sessionCount = ntdst_get(\Stride\Modules\Edition\SessionService::class)->getSessionCount($editionId);
            if ($sessionCount > 0) {
                $totalRate = 0.0;
                foreach ($confirmed as $reg) {
                    $totalRate += $attendance->getAttendanceRate((int) $reg->user_id, $editionId);
                }
                $attendanceRate = round($totalRate / count($confirmed), 1);
            }
        }

        // Completion rate — edition scope only
        $completionRate = null;
        $courseId = $editionService->getCourseId($editionId);
        if ($courseId && !empty($confirmed)) {
            $complete = 0;
            foreach ($confirmed as $reg) {
                if (\Stride\Integrations\LearnDash\LearnDashHelper::isComplete($courseId, (int) $reg->user_id)) {
                    $complete++;
                }
            }
            $completionRate = round(($complete / count($confirmed)) * 100, 1);
        }

        return [
            'scope' => 'edition',
            'edition_count' => 1,
            'total_enrolled' => $enrolled,
            'total_capacity' => $capacity,
            'fill_rate' => $fillRate,
            'status_breakdown' => $statusBreakdown,
            'average_attendance_rate' => $attendanceRate,
            'completion_rate' => $completionRate,
            '_links' => [
                'edition_edit' => admin_url("post.php?post={$editionId}&action=edit"),
            ],
        ];
    }

    /**
     * Statistics aggregated across all editions of a course.
     */
    private function getCourseStats(int $courseId): array
    {
        $editionRepo = ntdst_get(\Stride\Modules\Edition\EditionRepository::class);
        $editions = $editionRepo->findByCourse($courseId);

        if (empty($editions)) {
            return [
                'scope' => 'course',
                'edition_count' => 0,
                'total_enrolled' => 0,
                'total_capacity' => 0,
                'fill_rate' => null,
                'status_breakdown' => [],
                'average_attendance_rate' => null,
                'message' => 'Geen edities gevonden.',
            ];
        }

        return $this->aggregateEditionStats($editions, 'course');
    }

    /**
     * Global statistics across recent editions.
     */
    private function getGlobalStats(): array
    {
        $editionRepo = ntdst_get(\Stride\Modules\Edition\EditionRepository::class);
        $editions = $editionRepo->findUpcoming(100);

        if (empty($editions)) {
            return [
                'scope' => 'global',
                'edition_count' => 0,
                'total_enrolled' => 0,
                'total_capacity' => 0,
                'fill_rate' => null,
                'status_breakdown' => [],
                'average_attendance_rate' => null,
                'message' => 'Geen edities gevonden.',
            ];
        }

        return $this->aggregateEditionStats($editions, 'global');
    }

    /**
     * Aggregate statistics across a set of editions.
     *
     * @param array<array<string, mixed>> $editions Raw edition rows from repository
     * @param string $scope 'course' or 'global'
     * @return array<string, mixed>
     */
    private function aggregateEditionStats(array $editions, string $scope): array
    {
        $editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);
        $attendance = ntdst_get(\Stride\Modules\Attendance\AttendanceService::class);
        $regRepo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);

        $editionIds = [];
        $totalCapacity = 0;
        $hasCapacity = false;

        foreach ($editions as $item) {
            $id = (int) ($item['id'] ?? $item['ID'] ?? 0);
            if ($id > 0) {
                $editionIds[] = $id;
                $cap = (int) ($item['meta']['capacity'] ?? $editionService->getCapacity($id));
                $totalCapacity += $cap;
                if ($cap > 0) {
                    $hasCapacity = true;
                }
            }
        }

        if (empty($editionIds)) {
            return [
                'scope' => $scope,
                'edition_count' => 0,
                'total_enrolled' => 0,
                'total_capacity' => 0,
                'fill_rate' => null,
                'status_breakdown' => [],
                'average_attendance_rate' => null,
                'message' => 'Geen edities gevonden.',
            ];
        }

        // Prime post meta cache
        update_postmeta_cache($editionIds);

        // Batch registration counts
        $registeredCounts = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class)
            ->countByEditions($editionIds);
        $totalEnrolled = array_sum($registeredCounts);

        // Status breakdown across all editions — single batch query
        $statusBreakdown = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class)
            ->statusBreakdownByEditions($editionIds);

        // Fill rate
        $fillRate = ($hasCapacity && $totalCapacity > 0)
            ? round(($totalEnrolled / $totalCapacity) * 100, 1)
            : null;

        // Attendance rate — average per edition, then average across editions
        $sessionService = ntdst_get(\Stride\Modules\Edition\SessionService::class);
        $attendanceRates = [];

        foreach ($editionIds as $id) {
            $sessionCount = $sessionService->getSessionCount($id);
            if ($sessionCount === 0) {
                continue;
            }

            $confirmed = $regRepo->findByEdition($id, 'confirmed');
            if (empty($confirmed)) {
                continue;
            }

            $editionRate = 0.0;
            foreach ($confirmed as $reg) {
                $editionRate += $attendance->getAttendanceRate((int) $reg->user_id, $id);
            }
            $attendanceRates[] = $editionRate / count($confirmed);
        }

        $averageAttendanceRate = !empty($attendanceRates)
            ? round(array_sum($attendanceRates) / count($attendanceRates), 1)
            : null;

        return [
            'scope' => $scope,
            'edition_count' => count($editionIds),
            'total_enrolled' => $totalEnrolled,
            'total_capacity' => $totalCapacity,
            'fill_rate' => $fillRate,
            'status_breakdown' => $statusBreakdown,
            'average_attendance_rate' => $averageAttendanceRate,
        ];
    }

    // ---------------------------------------------------------------
    // Filter helpers
    // ---------------------------------------------------------------

    /**
     * Build edition query filters from ability input.
     *
     * @return array<string, mixed> Filters for EditionRepository::findByFilters()
     */
    private function buildEditionFilters(array $input): array
    {
        $filters = [];

        $courseId = (int) ($input['course_id'] ?? 0);
        if ($courseId > 0) {
            $filters['course_id'] = $courseId;
        }

        $status = $input['status'] ?? '';
        if ($status !== '') {
            $filters['status'] = sanitize_text_field($status);
        }

        $dateFrom = $input['start_date_from'] ?? '';
        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $filters['start_date_from'] = $dateFrom;
        }

        $dateTo = $input['start_date_to'] ?? '';
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $filters['start_date_to'] = $dateTo;
        }

        $upcoming = (bool) ($input['upcoming'] ?? false);
        if ($upcoming && !isset($filters['start_date_from'])) {
            $filters['start_date_from'] = date('Y-m-d');
        }

        return $filters;
    }

    // ---------------------------------------------------------------
    // Execute callbacks — EXPORT
    // ---------------------------------------------------------------

    /**
     * Export editions to CSV.
     *
     * @param array{course_id?: int, status?: string, upcoming?: bool} $input
     * @return array<string, mixed>
     */
    public function exportEditions(array $input): array
    {
        $export = ntdst_get(\NtdstAssistant\ExportService::class);
        $editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);
        $editionRepo = ntdst_get(\Stride\Modules\Edition\EditionRepository::class);

        $filters = $this->buildEditionFilters($input);

        if (!empty($filters)) {
            $raw = $editionRepo->findByFilters($filters, $export->getMaxRows());
        } else {
            $raw = $editionRepo->findUpcoming($export->getMaxRows());
        }

        $editionIds = [];
        foreach ($raw as $item) {
            $id = (int) ($item['id'] ?? $item['ID'] ?? 0);
            if ($id > 0) {
                $editionIds[] = $id;
            }
        }

        if (!empty($editionIds)) {
            update_postmeta_cache($editionIds);
        }

        $registeredCounts = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class)
            ->countByEditions($editionIds);

        $rows = [];
        foreach ($raw as $item) {
            $id = (int) ($item['id'] ?? $item['ID'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $meta = $item['meta'] ?? [];
            $courseName = '';
            $cid = $editionService->getCourseId($id);
            if ($cid) {
                $course = get_post($cid);
                $courseName = $course ? $course->post_title : '';
            }

            $rows[] = [
                $id,
                $item['title'] ?? $item['post_title'] ?? '',
                $courseName,
                $meta['start_date'] ?? $editionRepo->getField($id, 'start_date'),
                $meta['end_date'] ?? $editionRepo->getField($id, 'end_date'),
                $editionService->getPrice($id)->format(),
                $editionService->getCapacity($id),
                $registeredCounts[$id] ?? 0,
                $editionService->getStatus($id)->value,
            ];
        }

        $result = $export->generateCsv('edities', [
            'ID', 'Titel', 'Cursus', 'Startdatum', 'Einddatum', 'Prijs', 'Capaciteit', 'Ingeschreven', 'Status',
        ], $rows);

        $response = [
            'download_url' => $export->getSignedUrl($result['filename'], get_current_user_id()),
            'filename' => $result['filename'],
            'row_count' => $result['row_count'],
        ];

        if ($result['truncated']) {
            $response['truncated'] = true;
            $response['message'] = 'Export afgekapt op 5000 rijen. Gebruik filters om het resultaat te verfijnen.';
        }

        return $response;
    }

    /**
     * Export enrollments to CSV.
     *
     * @param array{edition_id?: int, user_id?: int, status?: string} $input
     * @return array<string, mixed>|\WP_Error
     */
    public function exportEnrollments(array $input): array|\WP_Error
    {
        $export = ntdst_get(\NtdstAssistant\ExportService::class);
        $repo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);

        $editionId = (int) ($input['edition_id'] ?? 0);
        $userId = (int) ($input['user_id'] ?? 0);
        $status = $input['status'] ?? null;

        if ($editionId <= 0 && $userId <= 0) {
            return new \WP_Error('missing_filter', 'Geef edition_id of user_id mee om te filteren.');
        }

        if ($editionId > 0) {
            $raw = $repo->findByEdition($editionId, $status);
        } else {
            $raw = $repo->findByUser($userId, $status);
        }

        // Batch-prime user and post caches
        $userIds = array_unique(array_map(fn($r) => (int) $r->user_id, $raw));
        $editionIds = array_unique(array_filter(array_map(fn($r) => (int) ($r->edition_id ?? 0), $raw)));

        if (!empty($userIds)) {
            new \WP_User_Query(['include' => $userIds, 'fields' => 'all_with_meta']);
        }
        if (!empty($editionIds)) {
            _prime_post_caches($editionIds, true, true);
        }

        $rows = [];
        foreach ($raw as $reg) {
            $user = get_userdata((int) $reg->user_id);
            $edition = get_post((int) ($reg->edition_id ?? 0));

            $rows[] = [
                (int) $reg->id,
                $user ? $user->display_name : '(onbekend)',
                $user ? $user->user_email : '',
                $edition ? $edition->post_title : '(onbekend)',
                $reg->status,
                $reg->registered_at ?? '',
                $reg->enrollment_path ?? 'individual',
            ];
        }

        $result = $export->generateCsv('inschrijvingen', [
            'ID', 'Gebruiker', 'Email', 'Editie', 'Status', 'Inschrijfdatum', 'Pad',
        ], $rows);

        $response = [
            'download_url' => $export->getSignedUrl($result['filename'], get_current_user_id()),
            'filename' => $result['filename'],
            'row_count' => $result['row_count'],
        ];

        if ($result['truncated']) {
            $response['truncated'] = true;
            $response['message'] = 'Export afgekapt op 5000 rijen. Gebruik filters om het resultaat te verfijnen.';
        }

        return $response;
    }

    /**
     * Export attendance records to CSV.
     *
     * @param array{edition_id?: int, session_id?: int} $input
     * @return array<string, mixed>|\WP_Error
     */
    public function exportAttendance(array $input): array|\WP_Error
    {
        $export = ntdst_get(\NtdstAssistant\ExportService::class);
        $attendance = ntdst_get(\Stride\Modules\Attendance\AttendanceService::class);
        $sessions = ntdst_get(\Stride\Modules\Edition\SessionService::class);

        $editionId = (int) ($input['edition_id'] ?? 0);
        $sessionId = (int) ($input['session_id'] ?? 0);

        if ($editionId <= 0 && $sessionId <= 0) {
            return new \WP_Error('missing_filter', 'Geef edition_id of session_id mee.');
        }

        $allRecords = [];

        if ($sessionId > 0) {
            $records = $attendance->getSessionAttendance($sessionId);
            $session = $sessions->getSession($sessionId);
            foreach ($records as $record) {
                $record['session_date'] = $session['date'] ?? '';
                $record['session_time'] = '';
                if (!empty($session['start_time']) && !empty($session['end_time'])) {
                    $record['session_time'] = $session['start_time'] . '-' . $session['end_time'];
                }
                $allRecords[] = $record;
            }
        } else {
            $editionSessions = $sessions->getSessionsForEdition($editionId);
            foreach ($editionSessions as $session) {
                $sid = (int) $session['id'];
                $records = $attendance->getSessionAttendance($sid);
                foreach ($records as $record) {
                    $record['session_date'] = $session['date'] ?? '';
                    $record['session_time'] = '';
                    if (!empty($session['start_time']) && !empty($session['end_time'])) {
                        $record['session_time'] = $session['start_time'] . '-' . $session['end_time'];
                    }
                    $allRecords[] = $record;
                }
            }
        }

        // Batch-hydrate users
        $userIds = array_unique(array_column($allRecords, 'user_id'));
        $usersMap = $this->batchLoadUsers($userIds);

        // Resolve marked_by display names
        $markedByIds = array_unique(array_filter(array_column($allRecords, 'marked_by')));
        $markedByMap = $this->batchLoadUsers(array_map('intval', $markedByIds));

        $rows = [];
        foreach ($allRecords as $record) {
            $uid = (int) $record['user_id'];
            $user = $usersMap[$uid] ?? null;
            $markedById = (int) ($record['marked_by'] ?? 0);
            $markedByUser = $markedByMap[$markedById] ?? null;

            $rows[] = [
                $user ? $user->display_name : '(onbekend)',
                $user ? $user->user_email : '',
                (int) ($record['session_id'] ?? 0),
                $record['session_date'] ?? '',
                $record['status'],
                $markedByUser ? $markedByUser->display_name : '',
                $record['marked_at'] ?? '',
            ];
        }

        $result = $export->generateCsv('aanwezigheid', [
            'Gebruiker', 'Email', 'Sessie', 'Datum', 'Status', 'Gemarkeerd door', 'Tijdstip',
        ], $rows);

        $response = [
            'download_url' => $export->getSignedUrl($result['filename'], get_current_user_id()),
            'filename' => $result['filename'],
            'row_count' => $result['row_count'],
        ];

        if ($result['truncated']) {
            $response['truncated'] = true;
            $response['message'] = 'Export afgekapt op 5000 rijen. Gebruik filters om het resultaat te verfijnen.';
        }

        return $response;
    }

    // ---------------------------------------------------------------
    // System prompt injection
    // ---------------------------------------------------------------

    /**
     * Append domain and formatting prompts to the system prompt.
     */
    public function injectDomainPrompts(string $prompt, array $context): string
    {
        $promptsDir = __DIR__ . '/prompts';

        foreach (['domain', 'formatting'] as $file) {
            $path = $promptsDir . '/' . $file . '.md';
            if (file_exists($path)) {
                $prompt .= "\n\n" . file_get_contents($path);
            }
        }

        return $prompt;
    }
}
