<?php
declare(strict_types=1);

namespace NetdustLTI\ToolProvider\Services;

use NetdustLTI\Shared\Domain\LtiClaims;
use NetdustLTI\ToolProvider\ContextRepository;
use WP_User;

/**
 * Enrolls LTI users in LearnDash courses.
 *
 * Current scope: Online/self-paced courses only.
 * Grants direct LearnDash course access without creating Stride registration records.
 * Completion is tracked via LearnDash and synced back to partner LMS via grade passback.
 *
 * Future: Edition-based LTI Enrollments
 * If LTI is needed for in-person/hybrid courses with sessions:
 * - Deep link could specify edition ID instead of course ID
 * - This service would create a wp_vad_registrations record
 * - Session attendance would be trackable for LTI users
 * - Partner billing could be based on Stride registration data
 */
final class CourseEnroller
{
    private const META_LTI_CONTEXT = '_netdust_lti_context_';

    public function __construct(
        private readonly ContextRepository $contextRepository,
    ) {}

    public function enroll(WP_User $user, int $courseId, LtiClaims $claims, int $platformId): void
    {
        // Check if LearnDash is active
        if (!function_exists('ld_update_course_access')) {
            ntdst_log('lti')->warning('LearnDash not available for enrollment', [
                'user_id' => $user->ID,
                'course_id' => $courseId,
            ]);
            return;
        }

        // Filter: allow suppressing enrollment
        $shouldEnroll = apply_filters('netdust_lti_should_enroll', true, $user, $courseId, $claims);
        if (!$shouldEnroll) {
            ntdst_log('lti')->info('Enrollment suppressed by filter', [
                'user_id' => $user->ID,
                'course_id' => $courseId,
            ]);
            // Still store LTI context for grade passback even if enrollment is skipped
            $this->storeLtiContext($user->ID, $courseId, $claims, $platformId);
            return;
        }

        // Check if already enrolled
        $hasAccess = sfwd_lms_has_access($courseId, $user->ID);

        if (!$hasAccess) {
            // Grant course access
            ld_update_course_access($user->ID, $courseId, false);

            ntdst_log('lti')->info('User enrolled in course', [
                'user_id' => $user->ID,
                'course_id' => $courseId,
            ]);
        }

        // Store LTI context for grade passback
        $this->storeLtiContext($user->ID, $courseId, $claims, $platformId);
    }

    private function storeLtiContext(int $userId, int $courseId, LtiClaims $claims, int $platformId): void
    {
        $contextData = [
            'platform_id' => $platformId,
            'lti_context_id' => $claims->contextId,
            'resource_link_id' => $claims->resourceLinkId,
            'lti_user_id' => $claims->sub,
            'line_item_url' => $claims->lineItemUrl,
            'line_items_url' => $claims->lineItemsUrl,
            'scores_url' => $claims->scoresUrl,
            'stored_at' => current_time('mysql'),
        ];

        // Store in user meta for quick access during grade passback
        update_user_meta(
            $userId,
            self::META_LTI_CONTEXT . $courseId,
            $contextData
        );

        // Also store/update in contexts table
        $existing = $this->contextRepository->findByLtiContext(
            $platformId,
            $claims->contextId ?? '',
            $claims->resourceLinkId
        );

        if ($existing) {
            $this->contextRepository->update($existing['id'], [
                'line_item_url' => $claims->lineItemUrl,
            ]);
        } elseif ($claims->contextId) {
            $this->contextRepository->create([
                'platform_id' => $platformId,
                'lti_context_id' => $claims->contextId,
                'ld_course_id' => $courseId,
                'resource_link_id' => $claims->resourceLinkId,
                'line_item_url' => $claims->lineItemUrl,
            ]);
        }
    }

    public function getLtiContext(int $userId, int $courseId): ?array
    {
        $context = get_user_meta($userId, self::META_LTI_CONTEXT . $courseId, true);
        return $context ?: null;
    }

    public function hasLtiContext(int $userId, int $courseId): bool
    {
        return $this->getLtiContext($userId, $courseId) !== null;
    }
}
