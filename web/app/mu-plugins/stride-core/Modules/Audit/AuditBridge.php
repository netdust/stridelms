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
        add_action('stride/registration/confirmed', [$this, 'onRegistrationConfirmed']);
        add_action('stride/registration/cancelled', [$this, 'onRegistrationCancelled']);
        add_action('stride/registration/interest_registered', [$this, 'onInterestRegistered']);
        add_action('stride/registration/waitlisted', [$this, 'onWaitlisted']);
        add_action('stride/registration/updated', [$this, 'onRegistrationUpdated']);
        add_action('stride/enrollment/task_completed', [$this, 'onTaskCompleted']);

        // Voucher events
        add_action('stride/voucher/created', [$this, 'onVoucherCreated']);
        add_action('stride/voucher/redeemed', [$this, 'onVoucherRedeemed']);
        add_action('stride/voucher/released', [$this, 'onVoucherReleased']);

        // Quote events
        add_action('stride/quote/cancelled', [$this, 'onQuoteCancelled']);
        add_action('stride/quote/send_email', [$this, 'onQuoteEmailSent'], 10, 3);
        add_action('stride/quote/regenerate_pdf', [$this, 'onQuotePdfRegenerated'], 10, 1);
        add_action('stride/quote/session_modifier_blocked', [$this, 'onQuoteModifierBlocked']);

        // Trajectory events
        add_action('stride/trajectory/created', [$this, 'onTrajectoryCreated']);
        add_action('stride/trajectory/updated', [$this, 'onTrajectoryUpdated']);
        add_action('stride/trajectory/enrolled', [$this, 'onTrajectoryEnrolled']);
        add_action('stride/trajectory/choices_updated', [$this, 'onTrajectoryChoicesUpdated']);

        // Attendance events
        add_action('stride/attendance/marked', [$this, 'onAttendanceMarked']);

        // Session events
        add_action('stride/session/created', [$this, 'onSessionCreated']);
        add_action('stride/session/note_updated', [$this, 'onSessionNoteUpdated']);
        add_action('stride/session/selections_updated', [$this, 'onSessionSelectionsUpdated']);

        // Completion events
        add_action('stride/completion/attendance_complete', [$this, 'onAttendanceComplete']);
        add_action('stride/completion/completed', [$this, 'onCompletionCompleted']);

        // User lifecycle / GDPR
        add_action('stride/user/anonymised', [$this, 'onUserAnonymised'], 10, 1);
        add_action('stride/gdpr/erasure_requested', [$this, 'onGdprErasureRequested']);

        // LearnDash completion events
        add_action('learndash_course_completed', [$this, 'onCourseCompleted'], 10, 1);

        // Mail send log (fired by netdust-mail after each send)
        add_action('ndmail_after_send', [$this, 'onMailSent'], 10, 3);

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

    // === Registration lifecycle ===

    public function onRegistrationConfirmed(array $data): void
    {
        $this->auditService->record(
            'registration',
            (int) ($data['registration_id'] ?? 0),
            'registration.confirmed',
            null,
            [
                'user_id' => $data['user_id'] ?? null,
                'edition_id' => $data['edition_id'] ?? null,
            ]
        );
    }

    public function onInterestRegistered(array $data): void
    {
        $this->auditService->record(
            'registration',
            (int) ($data['registration_id'] ?? 0),
            'registration.interest_registered',
            $data['user_id'] ?? null,
            [
                'user_id' => $data['user_id'] ?? null,
                'edition_id' => $data['edition_id'] ?? null,
                'trajectory_id' => $data['trajectory_id'] ?? null,
            ]
        );
    }

    public function onWaitlisted(array $data): void
    {
        $this->auditService->record(
            'registration',
            (int) ($data['registration_id'] ?? 0),
            'registration.waitlisted',
            $data['user_id'] ?? null,
            [
                'user_id' => $data['user_id'] ?? null,
                'edition_id' => $data['edition_id'] ?? null,
                'trajectory_id' => $data['trajectory_id'] ?? null,
            ]
        );
    }

    public function onRegistrationUpdated(array $data): void
    {
        $this->auditService->record(
            'registration',
            (int) ($data['registration_id'] ?? 0),
            'registration.updated',
            isset($data['actor_id']) ? (int) $data['actor_id'] : null,
            [
                'diff' => $data['diff'] ?? [],
            ]
        );
    }

    public function onTaskCompleted(array $data): void
    {
        $this->auditService->record(
            'registration',
            (int) ($data['registration_id'] ?? 0),
            'enrollment.task_completed',
            null,
            [
                'task_type' => $data['task_type'] ?? null,
            ]
        );
    }

    // === Voucher ===

    public function onVoucherCreated(array $data): void
    {
        $this->auditService->record(
            'voucher',
            (int) ($data['voucher_id'] ?? 0),
            'voucher.created',
            null,
            [
                'code' => $data['code'] ?? null,
            ]
        );
    }

    public function onVoucherRedeemed(array $data): void
    {
        $this->auditService->record(
            'voucher',
            (int) ($data['voucher_id'] ?? 0),
            'voucher.redeemed',
            $data['user_id'] ?? null,
            [
                'user_id' => $data['user_id'] ?? null,
                'quote_id' => $data['quote_id'] ?? null,
            ]
        );
    }

    public function onVoucherReleased(array $data): void
    {
        $this->auditService->record(
            'voucher',
            (int) ($data['voucher_id'] ?? 0),
            'voucher.released',
            $data['user_id'] ?? null,
            [
                'user_id' => $data['user_id'] ?? null,
                'quote_id' => $data['quote_id'] ?? null,
            ]
        );
    }

    // === Quote ===

    public function onQuoteCancelled(array $data): void
    {
        $this->auditService->record(
            'quote',
            (int) ($data['quote_id'] ?? 0),
            'quote.cancelled',
            null,
            []
        );
    }

    public function onQuoteEmailSent(int $quoteId, string $sendTo, string $sendCc = ''): void
    {
        $this->auditService->record(
            'quote',
            $quoteId,
            'quote.email_sent',
            null,
            [
                'to' => $sendTo,
                'cc' => $sendCc ?: null,
            ]
        );
    }

    public function onQuotePdfRegenerated(int $quoteId): void
    {
        $this->auditService->record(
            'quote',
            $quoteId,
            'quote.pdf_regenerated',
            null,
            []
        );
    }

    public function onQuoteModifierBlocked(array $data): void
    {
        $this->auditService->record(
            'quote',
            (int) ($data['quote_id'] ?? 0),
            'quote.modifier_blocked',
            null,
            [
                'registration_id' => $data['registration_id'] ?? null,
                'user_id' => $data['user_id'] ?? null,
            ]
        );
    }

    // === Trajectory ===

    public function onTrajectoryCreated(array $data): void
    {
        $this->auditService->record(
            'trajectory',
            (int) ($data['trajectory_id'] ?? 0),
            'trajectory.created',
            null,
            []
        );
    }

    public function onTrajectoryUpdated(array $data): void
    {
        $this->auditService->record(
            'trajectory',
            (int) ($data['trajectory_id'] ?? 0),
            'trajectory.updated',
            null,
            []
        );
    }

    public function onTrajectoryEnrolled(array $data): void
    {
        $this->auditService->record(
            'trajectory',
            (int) ($data['trajectory_id'] ?? 0),
            'trajectory.enrolled',
            $data['user_id'] ?? null,
            [
                'registration_id' => $data['registration_id'] ?? null,
                'user_id' => $data['user_id'] ?? null,
            ]
        );
    }

    public function onTrajectoryChoicesUpdated(array $data): void
    {
        $this->auditService->record(
            'trajectory',
            (int) ($data['trajectory_id'] ?? 0),
            'trajectory.choices_updated',
            null,
            [
                'registration_id' => $data['registration_id'] ?? null,
                'edition_ids' => $data['edition_ids'] ?? [],
            ]
        );
    }

    // === Session ===

    public function onSessionCreated(array $data): void
    {
        $this->auditService->record(
            'session',
            (int) ($data['session_id'] ?? 0),
            'session.created',
            null,
            [
                'edition_id' => $data['edition_id'] ?? null,
            ]
        );
    }

    public function onSessionSelectionsUpdated(array $data): void
    {
        $this->auditService->record(
            'session',
            (int) ($data['edition_id'] ?? 0),
            'session.selections_updated',
            null,
            [
                'registration_id' => $data['registration_id'] ?? null,
                'edition_id' => $data['edition_id'] ?? null,
                'session_ids' => $data['session_ids'] ?? [],
            ]
        );
    }

    // === Completion ===

    public function onAttendanceComplete(array $data): void
    {
        $this->auditService->record(
            'completion',
            (int) ($data['registration_id'] ?? 0),
            'completion.attendance_complete',
            null,
            [
                'user_id' => $data['user_id'] ?? null,
                'edition_id' => $data['edition_id'] ?? null,
            ]
        );
    }

    public function onCompletionCompleted(array $data): void
    {
        $this->auditService->record(
            'completion',
            (int) ($data['course_id'] ?? 0),
            'completion.completed',
            $data['user_id'] ?? null,
            [
                'user_id' => $data['user_id'] ?? null,
                'edition_id' => $data['edition_id'] ?? null,
                'course_id' => $data['course_id'] ?? null,
            ]
        );
    }

    // === User lifecycle / GDPR ===

    public function onUserAnonymised(int $userId): void
    {
        $this->auditService->record(
            'user',
            $userId,
            'user.anonymised',
            null,
            []
        );
    }

    public function onGdprErasureRequested(array $data): void
    {
        $this->auditService->record(
            'user',
            (int) ($data['user_id'] ?? 0),
            'gdpr.erasure_requested',
            $data['user_id'] ?? null,
            [
                'email' => $data['email'] ?? null,
                'reason' => $data['reason'] ?? null,
            ]
        );
    }

    // === Mail send log ===

    /**
     * Records every email sent through netdust-mail.
     *
     * Entity-id falls back to user_id from context so admin can filter
     * "all mail sent to user X" via the existing AuditRepository queries.
     */
    public function onMailSent(string $templateSlug, array $context, mixed $to): void
    {
        $userId = isset($context['user_id']) ? (int) $context['user_id'] : 0;

        $this->auditService->record(
            'mail',
            $userId,
            'mail.sent',
            null,
            [
                'template' => $templateSlug,
                'to' => is_array($to) ? implode(',', $to) : (string) $to,
                'registration_id' => $context['registration_id'] ?? null,
                'edition_id' => $context['edition_id'] ?? null,
                'quote_id' => $context['quote_id'] ?? null,
            ]
        );
    }
}
