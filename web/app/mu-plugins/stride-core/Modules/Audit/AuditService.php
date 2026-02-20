<?php
declare(strict_types=1);

namespace Stride\Modules\Audit;

use Stride\Infrastructure\AbstractService;
use Stride\Modules\Edition\EditionService;
use WP_Error;

/**
 * V2 Considerations (not in V1):
 *
 * 1. Chained hashes - Each entry references previous hash for tamper detection
 * 2. External verification - Exports to S3 Glacier / signed PDFs
 * 3. Real-time notifications - Admin alerts on specific actions
 * 4. Extended coverage - Quotes, vouchers, profile changes
 * 5. Full-text search - Search within context JSON
 * 6. Granular user view - Detailed attendance history
 * 7. Audit reports - Pre-built compliance reports
 * 8. REST API for external systems - External integrations
 * 9. GDPR anonymization - Actor anonymization after user deletion
 */
final class AuditService extends AbstractService
{
    private AuditRepository $repository;

    public static function metadata(): array
    {
        return [
            'name' => 'Audit Service',
            'description' => 'Event-based audit logging for compliance',
            'priority' => 99, // Load late to ensure other services are ready
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'audit';
    }

    protected function init(): void
    {
        $this->repository = new AuditRepository();

        // Ensure table exists
        if (!AuditTable::exists()) {
            AuditTable::create();
        }

        // Registration events
        add_action('stride/registration/created', [$this, 'onRegistrationCreated']);
        add_action('stride/registration/cancelled', [$this, 'onRegistrationCancelled']);

        // Attendance events
        add_action('stride/attendance/marked', [$this, 'onAttendanceMarked']);

        // LearnDash completion events
        add_action('learndash_course_completed', [$this, 'onCourseCompleted'], 10, 2);

        // Retention cleanup cron
        add_action('stride_audit_cleanup', [$this, 'runCleanup']);

        // Schedule cleanup if not scheduled
        if (!wp_next_scheduled('stride_audit_cleanup')) {
            wp_schedule_event(time(), 'weekly', 'stride_audit_cleanup');
        }

        // Register user-facing shortcode
        new ActivityShortcode($this, ntdst_get(EditionService::class));
    }

    /**
     * Record an audit entry.
     */
    public function record(
        string $entityType,
        int $entityId,
        string $action,
        ?int $actorId = null,
        array $context = []
    ): int|WP_Error {
        $actorType = 'user';

        if ($actorId === null) {
            $actorId = get_current_user_id() ?: null;
            if ($actorId === null || $actorId === 0) {
                $actorType = 'system';
                $actorId = null;
            }
        }

        return $this->repository->insert([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'actor_id' => $actorId,
            'actor_type' => $actorType,
            'context' => $context,
        ]);
    }

    /**
     * Get audit entries for an entity.
     */
    public function getForEntity(string $entityType, int $entityId): array
    {
        return $this->repository->findByEntity($entityType, $entityId);
    }

    /**
     * Get audit entries for a user (as actor).
     */
    public function getForUser(int $userId): array
    {
        return $this->repository->findByActor($userId);
    }

    /**
     * Get milestone entries for user dashboard.
     */
    public function getMilestonesForUser(int $userId): array
    {
        return $this->repository->getMilestonesForUser($userId);
    }

    /**
     * Get repository for admin queries.
     */
    public function getRepository(): AuditRepository
    {
        return $this->repository;
    }

    // --- Event Handlers ---

    public function onRegistrationCreated(array $data): void
    {
        $actorId = $data['enrolled_by'] ?? $data['user_id'] ?? null;

        $this->record(
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

        ntdst_log('audit')->info('Audit: registration.created', [
            'registration_id' => $data['registration_id'],
        ]);
    }

    public function onRegistrationCancelled(array $data): void
    {
        $this->record(
            'registration',
            (int) $data['registration_id'],
            'registration.cancelled',
            null, // Current user
            [
                'user_id' => $data['user_id'] ?? null,
                'edition_id' => $data['edition_id'] ?? null,
            ]
        );

        ntdst_log('audit')->info('Audit: registration.cancelled', [
            'registration_id' => $data['registration_id'],
        ]);
    }

    public function onAttendanceMarked(array $data): void
    {
        $action = match ($data['status'] ?? 'present') {
            'present' => 'attendance.marked_present',
            'absent' => 'attendance.marked_absent',
            'excused' => 'attendance.marked_excused',
            default => 'attendance.marked',
        };

        $this->record(
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

        ntdst_log('audit')->info("Audit: {$action}", [
            'attendance_id' => $data['attendance_id'],
        ]);
    }

    public function onCourseCompleted(array $data, \WP_User $user): void
    {
        $courseId = $data['course']->ID ?? $data['course_id'] ?? 0;
        $courseTitle = $data['course']->post_title ?? '';

        $this->record(
            'completion',
            $courseId,
            'completion.course_completed',
            $user->ID,
            [
                'course_id' => $courseId,
                'course_title' => $courseTitle,
            ]
        );

        ntdst_log('audit')->info('Audit: completion.course_completed', [
            'course_id' => $courseId,
            'user_id' => $user->ID,
        ]);

        // Check if course has a certificate and record certificate_issued
        if (function_exists('learndash_get_course_certificate_link')) {
            $certificateLink = learndash_get_course_certificate_link($courseId, $user->ID);
            if (!empty($certificateLink)) {
                $this->record(
                    'completion',
                    $courseId,
                    'completion.certificate_issued',
                    $user->ID,
                    [
                        'course_id' => $courseId,
                        'course_title' => $courseTitle,
                        'certificate_link' => $certificateLink,
                    ]
                );

                ntdst_log('audit')->info('Audit: completion.certificate_issued', [
                    'course_id' => $courseId,
                    'user_id' => $user->ID,
                ]);
            }
        }
    }

    /**
     * Run retention cleanup. Called by cron.
     */
    public function runCleanup(): void
    {
        $retentionYears = apply_filters('stride/audit/retention_years', 7);
        $before = new \DateTime("-{$retentionYears} years");

        $deleted = $this->repository->deleteOlderThan($before);

        ntdst_log('audit')->info('Audit cleanup completed', [
            'deleted_count' => $deleted,
            'retention_years' => $retentionYears,
        ]);
    }
}
