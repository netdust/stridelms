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
     * Get editions — v1 stub.
     *
     * @param array<string, mixed> $input
     * @return array{message: string}
     */
    public function getEditions(array $input): array
    {
        // TODO: Wire to EditionRepository::findUpcoming / findByCourse with filters
        return [
            'message' => 'stride/get-editions is registered but not yet wired to data. This will be implemented in a follow-up task.',
        ];
    }

    /**
     * Get enrollments — v1 stub.
     *
     * @param array<string, mixed> $input
     * @return array{message: string}
     */
    public function getEnrollments(array $input): array
    {
        // TODO: Wire to RegistrationRepository::findByEdition / findByUser with filters
        return [
            'message' => 'stride/get-enrollments is registered but not yet wired to data. This will be implemented in a follow-up task.',
        ];
    }

    // ---------------------------------------------------------------
    // Execute callbacks — WRITE (stubs)
    // ---------------------------------------------------------------

    /**
     * Enroll user — v1 stub.
     *
     * @param array{user_id: int, edition_id: int} $input
     * @return array{message: string}
     */
    public function enrollUser(array $input): array
    {
        // TODO: Wire to EnrollmentService to create registration + grant LearnDash access
        return [
            'message' => 'stride/enroll-user is registered but not yet wired. This will be implemented in a follow-up task.',
        ];
    }

    /**
     * Unenroll user — v1 stub.
     *
     * @param array{user_id: int, edition_id: int} $input
     * @return array{message: string}
     */
    public function unenrollUser(array $input): array
    {
        // TODO: Wire to EnrollmentService to cancel registration + revoke LearnDash access
        return [
            'message' => 'stride/unenroll-user is registered but not yet wired. This will be implemented in a follow-up task.',
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
