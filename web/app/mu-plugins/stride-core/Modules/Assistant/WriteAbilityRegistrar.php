<?php

declare(strict_types=1);

namespace Stride\Modules\Assistant;

use Stride\Infrastructure\AbstractService;

/**
 * Registers Stride write abilities for the AI assistant.
 *
 * Owns:
 * - 3 write abilities: enroll-user, unenroll-user, mark-attendance
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

        // Mark attendance
        wp_register_ability('stride/mark-attendance', [
            'label' => 'Aanwezigheid markeren',
            'description' => 'Mark one or multiple users present/absent/excused for a session. Validates enrollment before marking.',
            'category' => 'stride',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => ['type' => 'integer', 'description' => 'Sessie-ID'],
                    'user_id' => ['type' => 'integer', 'description' => 'Enkele gebruiker (optioneel als user_ids meegegeven)'],
                    'user_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Meerdere gebruikers (optioneel als user_id meegegeven)',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['present', 'absent', 'excused'],
                        'description' => 'Aanwezigheidsstatus',
                    ],
                ],
                'required' => ['session_id', 'status'],
            ],
            'permission_callback' => fn() => current_user_can('stride_manage'),
            'execute_callback' => [$this, 'markAttendance'],
            'meta' => [
                'show_in_rest' => true,
                'describe_input' => [$this, 'describeMarkAttendanceInput'],
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

    /**
     * Mark one or multiple users present/absent/excused for a session.
     *
     * Fail-early and atomic: validates all users before writing any attendance.
     *
     * @param array{session_id: int, status: string, user_id?: int, user_ids?: int[]} $input
     * @return array{marked: int, session_id: int, status: string, message: string}|\WP_Error
     */
    public function markAttendance(array $input): array|\WP_Error
    {
        $sessionId = (int) ($input['session_id'] ?? 0);
        $status    = $input['status'] ?? '';

        // 1. Resolve user IDs
        if (!empty($input['user_ids']) && is_array($input['user_ids'])) {
            $userIds = array_map('intval', $input['user_ids']);
        } elseif (!empty($input['user_id'])) {
            $userIds = [(int) $input['user_id']];
        } else {
            return new \WP_Error('invalid_input', 'user_id of user_ids is vereist.');
        }

        if ($sessionId <= 0) {
            return new \WP_Error('invalid_input', 'session_id is vereist.');
        }

        if (!in_array($status, ['present', 'absent', 'excused'], true)) {
            return new \WP_Error('invalid_input', 'Ongeldige status. Gebruik present, absent of excused.');
        }

        // 2. Validate session exists
        $sessionService = ntdst_get(\Stride\Modules\Edition\SessionService::class);
        $session = $sessionService->getSession($sessionId);

        if (!$session) {
            return new \WP_Error('not_found', "Sessie #{$sessionId} niet gevonden.");
        }

        // 3. Resolve parent edition
        $editionId = (int) ($session['edition_id'] ?? 0);

        if ($editionId <= 0) {
            return new \WP_Error('invalid_data', 'Sessie heeft geen gekoppelde editie.');
        }

        // 4. Validate ALL users enrolled (no partial execution)
        $repo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
        $registrations = $repo->findByEdition($editionId, 'confirmed');
        $enrolledUserIds = array_map(fn($r) => (int) $r->user_id, $registrations);

        $notEnrolled = array_diff($userIds, $enrolledUserIds);
        if (!empty($notEnrolled)) {
            return new \WP_Error(
                'not_enrolled',
                sprintf(
                    'Gebruiker(s) niet ingeschreven voor deze editie: %s. Geen aanwezigheid opgeslagen.',
                    implode(', ', $notEnrolled),
                ),
            );
        }

        // 5. Execute based on status
        $attendanceService = ntdst_get(\Stride\Modules\Attendance\AttendanceService::class);
        $markedBy = get_current_user_id();

        if ($status === 'present') {
            $result = $attendanceService->markMultiplePresent($sessionId, $userIds, $markedBy);
            if (is_wp_error($result)) {
                return $result;
            }
        } else {
            $method = $status === 'absent' ? 'markAbsent' : 'markExcused';
            foreach ($userIds as $userId) {
                $result = $attendanceService->$method($sessionId, $userId, $markedBy);
                if (is_wp_error($result)) {
                    return $result;
                }
            }
        }

        // 6. Return summary
        $count = count($userIds);
        $statusLabel = match ($status) {
            'present' => 'aanwezig',
            'absent'  => 'afwezig',
            'excused' => 'verontschuldigd',
            default   => $status,
        };

        return [
            'marked'     => $count,
            'session_id' => $sessionId,
            'status'     => $status,
            'message'    => sprintf('%d gebruiker(s) gemarkeerd als %s.', $count, $statusLabel),
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

    /**
     * Human-readable summary for mark-attendance action confirmation.
     *
     * @param array{session_id: int, status: string, user_id?: int, user_ids?: int[]} $input
     */
    public function describeMarkAttendanceInput(array $input): string
    {
        $sessionId    = (int) ($input['session_id'] ?? 0);
        $userIds      = $input['user_ids'] ?? [];
        $singleUserId = (int) ($input['user_id'] ?? 0);
        $status       = $input['status'] ?? 'present';

        if (empty($userIds) && $singleUserId > 0) {
            $userIds = [$singleUserId];
        }

        $count = count($userIds);
        $statusLabel = match ($status) {
            'present' => 'aanwezig',
            'absent'  => 'afwezig',
            'excused' => 'verontschuldigd',
            default   => $status,
        };

        $sessions   = ntdst_get(\Stride\Modules\Edition\SessionService::class);
        $session    = $sessions->getSession($sessionId);
        $sessionDesc = $session
            ? sprintf('sessie %s (%s)', $session['date'] ?? '?', $session['start_time'] ?? '?')
            : "sessie #{$sessionId}";

        if ($count === 1) {
            $userName = $this->resolveUserName($userIds[0]);
            return sprintf('%s %s markeren voor %s', $userName, $statusLabel, $sessionDesc);
        }

        return sprintf('%d gebruikers %s markeren voor %s', $count, $statusLabel, $sessionDesc);
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
