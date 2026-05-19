<?php

declare(strict_types=1);

namespace Stride\Modules\Audit;

use NTDST\Audit\AuditService;
use Stride\Infrastructure\AbstractService;

/**
 * Bridge between Stride events and generic NTDST Audit plugin.
 */
final class AuditBridge extends AbstractService
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Audit Bridge',
            'description' => 'Connects Stride events to NTDST Audit plugin',
            'priority' => 99,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'audit_bridge';
    }

    protected function init(): void
    {
        // Registration events
        add_action('stride/registration/created', [$this, 'onRegistrationCreated']);
        add_action('stride/registration/cancelled', [$this, 'onRegistrationCancelled']);

        // Attendance events
        add_action('stride/attendance/marked', [$this, 'onAttendanceMarked']);

        // Session events
        add_action('stride/session/note_updated', [$this, 'onSessionNoteUpdated']);

        // LearnDash completion events
        add_action('learndash_course_completed', [$this, 'onCourseCompleted'], 10, 1);

        // Assistant ability executions
        add_action('ntdst_assistant/after_execute', [$this, 'onAssistantExecute'], 10, 4);
    }

    public function onRegistrationCreated(array $data): void
    {
        $actorId = $data['enrolled_by'] ?? $data['user_id'] ?? null;

        $this->auditService->record(
            'registration',
            (int) $data['registration_id'],
            'registration.created',
            $actorId ? (int) $actorId : null,
            [
                'user_id' => $data['user_id'] ?? null,
                'edition_id' => $data['edition_id'] ?? null,
                'enrollment_path' => $data['enrollment_path'] ?? 'individual',
            ]
        );
    }

    public function onRegistrationCancelled(array $data): void
    {
        $this->auditService->record(
            'registration',
            (int) $data['registration_id'],
            'registration.cancelled',
            null,
            [
                'user_id' => $data['user_id'] ?? null,
                'edition_id' => $data['edition_id'] ?? null,
            ]
        );
    }

    public function onAttendanceMarked(array $data): void
    {
        $action = match ($data['status'] ?? 'present') {
            'present' => 'attendance.marked_present',
            'absent' => 'attendance.marked_absent',
            'excused' => 'attendance.marked_excused',
            default => 'attendance.marked',
        };

        $this->auditService->record(
            'attendance',
            (int) $data['attendance_id'],
            $action,
            isset($data['marked_by']) ? (int) $data['marked_by'] : null,
            [
                'session_id' => $data['session_id'] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'edition_id' => $data['edition_id'] ?? null,
                'status' => $data['status'] ?? null,
            ]
        );
    }

    public function onSessionNoteUpdated(array $data): void
    {
        $this->auditService->record(
            'session',
            (int) $data['session_id'],
            'session.note_updated',
            null,
            [
                'session_id' => $data['session_id'] ?? null,
                'edition_id' => $data['edition_id'] ?? null,
            ]
        );
    }

    public function onAssistantExecute(string $ability, array $input, mixed $result, array $meta): void
    {
        $this->auditService->record(
            'assistant',
            get_current_user_id() ?: 0,
            "assistant.{$ability}",
            get_current_user_id() ?: null,
            [
                'source'   => $meta['source'] ?? 'chat',
                'input'    => $input,
                'readonly' => $meta['readonly'] ?? false,
                'success'  => !is_wp_error($result),
            ]
        );
    }

    public function onCourseCompleted(array $data): void
    {
        $user = $data['user'] ?? null;
        $courseId = $data['course']->ID ?? $data['course_id'] ?? 0;
        $courseTitle = $data['course']->post_title ?? '';
        $userId = $user instanceof \WP_User ? $user->ID : ($data['user_id'] ?? 0);

        $this->auditService->record(
            'completion',
            $courseId,
            'completion.course_completed',
            $userId ?: null,
            [
                'course_id' => $courseId,
                'course_title' => $courseTitle,
            ]
        );

        // Check if course has a certificate
        if ($userId && function_exists('learndash_get_course_certificate_link')) {
            $certificateLink = learndash_get_course_certificate_link($courseId, $userId);
            if (!empty($certificateLink)) {
                $this->auditService->record(
                    'completion',
                    $courseId,
                    'completion.certificate_issued',
                    $userId,
                    [
                        'course_id' => $courseId,
                        'course_title' => $courseTitle,
                        'certificate_link' => $certificateLink,
                    ]
                );
            }
        }
    }
}
