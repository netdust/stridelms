<?php

declare(strict_types=1);

namespace Stride\Integrations\LearnDash;

use Stride\Contracts\LMSAdapterInterface;
use Stride\Infrastructure\AbstractService;

/**
 * LearnDash integration service.
 *
 * Business operations only: grant/revoke access, completion check.
 * For read-only presentation data, use LearnDashHelper.
 */
final class LearnDashService extends AbstractService implements LMSAdapterInterface
{
    public static function metadata(): array
    {
        return [
            'name' => 'LearnDash Integration',
            'description' => 'LearnDash LMS integration: access, completion, taxonomies',
            'priority' => 5,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'learndash';
    }

    protected function init(): void
    {
        add_action('init', function () {
            (new CourseTaxonomies())->register();
        }, 5);

        // Bust the admin dashboard's cached counts when a course completes —
        // completion is when a certificate becomes available, the nocert
        // queue count's only input not covered by a Stride write event. The
        // hook lives HERE (not with the other busts in AdminDashboardService)
        // because that service is admin_only while completion fires on
        // frontend page requests and REST calls; this integration service
        // boots on every request. Mirrors AdminDashboardService's
        // $invalidateQueue closure.
        add_action('learndash_course_completed', static function (): void {
            delete_transient('stride_action_queue');
            delete_transient(\Stride\Admin\AdminStatsService::STATS_TRANSIENT_KEY);
        });
    }

    // === LMSAdapterInterface ===

    public function grantAccess(int $userId, int $courseId): bool
    {
        if (!function_exists('ld_update_course_access')) {
            return false;
        }
        // Guard against non-existent / wrong-type course IDs. LD core
        // accepts any int and silently records orphan usermeta otherwise.
        if (get_post_type($courseId) !== 'sfwd-courses') {
            return false;
        }

        ld_update_course_access($userId, $courseId, false);

        return true;
    }

    public function revokeAccess(int $userId, int $courseId): bool
    {
        if (!function_exists('ld_update_course_access')) {
            return false;
        }
        if (get_post_type($courseId) !== 'sfwd-courses') {
            return false;
        }

        ld_update_course_access($userId, $courseId, true);

        return true;
    }

    public function isComplete(int $userId, int $courseId): bool
    {
        if (!function_exists('learndash_course_completed')) {
            return false;
        }

        return learndash_course_completed($userId, $courseId);
    }

    public function markComplete(int $userId, int $courseId): bool
    {
        if (!function_exists('learndash_process_mark_complete')) {
            return false;
        }
        if (get_post_type($courseId) !== 'sfwd-courses') {
            return false;
        }

        learndash_process_mark_complete($userId, $courseId);

        return true;
    }

    public function isOpenCourse(int $courseId): bool
    {
        if (get_post_type($courseId) !== 'sfwd-courses') {
            return false;
        }

        // LearnDash stores course settings in a serialized array under
        // _sfwd-courses meta. Other code outside this adapter shouldn't
        // know about that shape.
        $courseMeta = get_post_meta($courseId, '_sfwd-courses', true);
        $priceType = is_array($courseMeta) ? ($courseMeta['course_price_type'] ?? '') : '';

        return $priceType === 'open';
    }
}
