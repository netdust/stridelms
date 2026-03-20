<?php

declare(strict_types=1);

namespace Stride\Modules\Assistant;

use Stride\Infrastructure\AbstractService;

/**
 * Registers Stride write abilities for the AI assistant.
 *
 * Owns:
 * - 2 write abilities: enroll-user, unenroll-user
 *
 * ReadAbilityRegistrar owns category registration and system prompt injection.
 */
final class WriteAbilityRegistrar extends AbstractService
{
    public static function metadata(): array
    {
        return [
            'name' => 'Assistant Write Ability Registrar',
            'description' => 'Registers Stride write abilities for the AI assistant',
            'priority' => 90, // Late — after all domain services
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'assistant-write';
    }

    protected function init(): void
    {
        add_action('wp_abilities_api_init', [$this, 'registerAbilities']);
    }

    // ---------------------------------------------------------------
    // Ability registration
    // ---------------------------------------------------------------

    public function registerAbilities(): void
    {
        // Enroll user
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

        // Unenroll user
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
    // Execute callbacks — WRITE
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
