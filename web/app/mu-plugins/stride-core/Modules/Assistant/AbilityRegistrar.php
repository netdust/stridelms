<?php

declare(strict_types=1);

namespace Stride\Modules\Assistant;

use Stride\Infrastructure\AbstractService;

/**
 * Registers Stride domain abilities for the AI assistant.
 *
 * Provides 4 read abilities and 2 write abilities that expose
 * edition, enrollment, and user data to the assistant chat.
 */
final class AbilityRegistrar extends AbstractService
{
    public static function metadata(): array
    {
        return [
            'name' => 'Assistant Ability Registrar',
            'description' => 'Registers Stride abilities for the AI assistant',
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
        $this->registerReadAbilities();
        $this->registerWriteAbilities();
    }

    private function registerReadAbilities(): void
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

        // Get editions (stub)
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

        // Get enrollments (stub)
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
    }

    private function registerWriteAbilities(): void
    {
        // Enroll user (stub)
        wp_register_ability('stride/enroll-user', [
            'label' => 'Gebruiker inschrijven',
            'description' => 'Enroll a user in an edition. Creates a confirmed registration and grants LearnDash course access.',
            'category' => 'stride',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'user_id' => [
                        'type' => 'integer',
                        'description' => 'User ID to enroll',
                    ],
                    'edition_id' => [
                        'type' => 'integer',
                        'description' => 'Edition ID to enroll in',
                    ],
                ],
                'required' => ['user_id', 'edition_id'],
            ],
            'permission_callback' => fn() => current_user_can('stride_manage'),
            'execute_callback' => [$this, 'enrollUser'],
            'meta' => [
                'show_in_rest' => true,
                'describe_input' => [$this, 'describeEnrollInput'],
            ],
        ]);

        // Unenroll user (stub)
        wp_register_ability('stride/unenroll-user', [
            'label' => 'Gebruiker uitschrijven',
            'description' => 'Cancel a user enrollment. Revokes LearnDash course access.',
            'category' => 'stride',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'user_id' => [
                        'type' => 'integer',
                        'description' => 'User ID to unenroll',
                    ],
                    'edition_id' => [
                        'type' => 'integer',
                        'description' => 'Edition ID to unenroll from',
                    ],
                ],
                'required' => ['user_id', 'edition_id'],
            ],
            'permission_callback' => fn() => current_user_can('stride_manage'),
            'execute_callback' => [$this, 'unenrollUser'],
            'meta' => [
                'show_in_rest' => true,
                'describe_input' => [$this, 'describeUnenrollInput'],
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

        $editions = [];
        foreach (array_slice($raw, 0, $perPage) as $item) {
            $id = (int) ($item['id'] ?? $item['ID'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $editions[] = [
                'id' => $id,
                'title' => $item['title'] ?? $item['post_title'] ?? '',
                'status' => $editionService->getStatus($id)->value,
                'start_date' => $editionRepo->getField($id, 'start_date'),
                'end_date' => $editionRepo->getField($id, 'end_date'),
                'venue' => $editionRepo->getField($id, 'venue'),
                'capacity' => $editionService->getCapacity($id),
                'registered' => $editionService->getRegisteredCount($id),
                'can_enroll' => $editionService->canEnroll($id),
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
            ];
        }

        return ['enrollments' => $enrollments];
    }

    // ---------------------------------------------------------------
    // Execute callbacks — WRITE (stubs)
    // ---------------------------------------------------------------

    /**
     * Enroll a user in an edition.
     *
     * @param array{user_id: int, edition_id: int} $input
     * @return array{registration_id: int, status: string}|\WP_Error
     */
    public function enrollUser(array $input): array|\WP_Error
    {
        $userId = (int) ($input['user_id'] ?? 0);
        $editionId = (int) ($input['edition_id'] ?? 0);

        if ($userId <= 0 || $editionId <= 0) {
            return new \WP_Error('invalid_input', 'user_id and edition_id are required.');
        }

        $enrollmentService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);

        $result = $enrollmentService->enroll($userId, $editionId, [
            'enrollment_path' => 'individual',
            'enrolled_by' => get_current_user_id(),
            'notes' => 'Ingeschreven via Stride Assistant',
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        $user = get_userdata($userId);
        $edition = get_post($editionId);

        return [
            'registration_id' => $result,
            'status' => 'confirmed',
            'user_name' => $user ? $user->display_name : '(onbekend)',
            'edition_title' => $edition ? $edition->post_title : '(onbekend)',
            'message' => sprintf(
                '%s is ingeschreven voor %s. LearnDash-toegang is verleend.',
                $user ? $user->display_name : "Gebruiker #{$userId}",
                $edition ? $edition->post_title : "Editie #{$editionId}",
            ),
        ];
    }

    /**
     * Unenroll a user from an edition.
     *
     * @param array{user_id: int, edition_id: int} $input
     * @return array{cancelled: bool, message: string}|\WP_Error
     */
    public function unenrollUser(array $input): array|\WP_Error
    {
        $userId = (int) ($input['user_id'] ?? 0);
        $editionId = (int) ($input['edition_id'] ?? 0);

        if ($userId <= 0 || $editionId <= 0) {
            return new \WP_Error('invalid_input', 'user_id and edition_id are required.');
        }

        $repo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
        $enrollmentService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);

        $registration = $repo->findByUserAndEdition($userId, $editionId);

        if (!$registration) {
            return new \WP_Error('not_found', 'Geen inschrijving gevonden voor deze gebruiker en editie.');
        }

        $result = $enrollmentService->cancel((int) $registration->id);

        if (is_wp_error($result)) {
            return $result;
        }

        $user = get_userdata($userId);
        $edition = get_post($editionId);

        return [
            'cancelled' => true,
            'registration_id' => (int) $registration->id,
            'message' => sprintf(
                '%s is uitgeschreven uit %s. LearnDash-toegang is ingetrokken.',
                $user ? $user->display_name : "Gebruiker #{$userId}",
                $edition ? $edition->post_title : "Editie #{$editionId}",
            ),
        ];
    }

    // ---------------------------------------------------------------
    // Describe input callbacks (for confirmation flow)
    // ---------------------------------------------------------------

    /**
     * Human-readable summary for enroll action confirmation.
     *
     * @param array{user_id: int, edition_id: int} $input
     */
    public function describeEnrollInput(array $input): string
    {
        $userName = $this->resolveUserName((int) ($input['user_id'] ?? 0));
        $editionTitle = $this->resolveEditionTitle((int) ($input['edition_id'] ?? 0));

        return sprintf('%s inschrijven voor %s', $userName, $editionTitle);
    }

    /**
     * Human-readable summary for unenroll action confirmation.
     *
     * @param array{user_id: int, edition_id: int} $input
     */
    public function describeUnenrollInput(array $input): string
    {
        $userName = $this->resolveUserName((int) ($input['user_id'] ?? 0));
        $editionTitle = $this->resolveEditionTitle((int) ($input['edition_id'] ?? 0));

        return sprintf('%s uitschrijven uit %s (LearnDash-toegang wordt ingetrokken)', $userName, $editionTitle);
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

    // ---------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------

    private function resolveUserName(int $userId): string
    {
        if ($userId <= 0) {
            return '(onbekende gebruiker)';
        }

        $user = get_userdata($userId);

        return $user ? $user->display_name : "(gebruiker #{$userId})";
    }

    private function resolveEditionTitle(int $editionId): string
    {
        if ($editionId <= 0) {
            return '(onbekende editie)';
        }

        $post = get_post($editionId);

        return $post ? $post->post_title : "(editie #{$editionId})";
    }
}
