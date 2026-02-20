<?php
declare(strict_types=1);

namespace NetdustLTI\Services;

use NetdustLTI\Domain\LtiClaims;
use NetdustLTI\Repositories\ContextRepository;
use WP_User;

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
