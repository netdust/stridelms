<?php

declare(strict_types=1);

namespace Stride\Modules\Assistant;

use Stride\Infrastructure\AbstractService;

/**
 * Registers Stride read-only abilities for the AI assistant.
 *
 * Owns:
 * - 5 read abilities: search-users, get-edition, get-editions, get-enrollments, get-stats
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
            'description' => 'List editions with optional filters (course_id, status, upcoming). Returns title, dates, status, and capacity.',
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
        $edition = $editionService->getEdition($editionId);

        if (is_wp_error($edition)) {
            return $edition;
        }

        $repository = ntdst_get(\Stride\Modules\Edition\EditionRepository::class);

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

        $courseId = (int) ($input['course_id'] ?? 0);
        $upcoming = (bool) ($input['upcoming'] ?? false);

        if ($courseId > 0) {
            $raw = $editionService->getEditionsForCourse($courseId);
        } elseif ($upcoming) {
            $raw = $editionRepo->findUpcoming($perPage);
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
        $registeredCounts = $this->batchRegisteredCounts($editionIds);

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
     * Batch count registrations for multiple editions in a single query.
     *
     * @param array<int> $editionIds
     * @return array<int, int> Map of edition_id => count
     */
    private function batchRegisteredCounts(array $editionIds): array
    {
        if (empty($editionIds)) {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'vad_registrations';
        $ids = implode(',', array_map('intval', $editionIds));

        $results = $wpdb->get_results(
            "SELECT edition_id, COUNT(*) as cnt
             FROM {$table}
             WHERE edition_id IN ({$ids})
             AND status IN ('confirmed', 'completed', 'pending')
             GROUP BY edition_id",
            ARRAY_A
        );

        $counts = [];
        foreach ($results as $row) {
            $counts[(int) $row['edition_id']] = (int) $row['cnt'];
        }

        return $counts;
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
     * Statistics for a single edition.
     */
    private function getEditionStats(int $editionId): array
    {
        $editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);
        $regRepo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
        $attendance = ntdst_get(\Stride\Modules\Attendance\AttendanceService::class);

        $edition = $editionService->getEdition($editionId);
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
        $editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);
        $editions = $editionService->getEditionsForCourse($courseId);

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
        $registeredCounts = $this->batchRegisteredCounts($editionIds);
        $totalEnrolled = array_sum($registeredCounts);

        // Status breakdown across all editions — single batch query
        $statusBreakdown = $this->batchStatusBreakdown($editionIds);

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

    /**
     * Batch count registrations by status for multiple editions in a single query.
     *
     * @param array<int> $editionIds
     * @return array<string, int> Map of status => count
     */
    private function batchStatusBreakdown(array $editionIds): array
    {
        if (empty($editionIds)) {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'vad_registrations';
        $ids = implode(',', array_map('intval', $editionIds));

        $results = $wpdb->get_results(
            "SELECT status, COUNT(*) as cnt
             FROM {$table}
             WHERE edition_id IN ({$ids})
             GROUP BY status",
            ARRAY_A
        );

        $breakdown = [];
        foreach ($results as $row) {
            $breakdown[$row['status']] = (int) $row['cnt'];
        }

        return $breakdown;
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
