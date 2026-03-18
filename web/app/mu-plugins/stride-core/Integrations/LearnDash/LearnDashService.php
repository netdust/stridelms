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
    }

    // === LMSAdapterInterface ===

    public function grantAccess(int $userId, int $courseId): bool
    {
        if (!function_exists('ld_update_course_access')) {
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
}
