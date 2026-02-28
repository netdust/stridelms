<?php

declare(strict_types=1);

namespace Stride\Integrations\LearnDash;

use Stride\Contracts\LMSAdapterInterface;

/**
 * LearnDash implementation of LMS adapter.
 *
 * 7 methods - keeps coupling minimal.
 */
final class LearnDashAdapter implements LMSAdapterInterface
{
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

    public function getCertificateLink(int $userId, int $courseId): ?string
    {
        if (!function_exists('learndash_get_course_certificate_link')) {
            return null;
        }

        $link = learndash_get_course_certificate_link($courseId, $userId);

        return $link ?: null;
    }

    public function getEnrolledCourses(int $userId): array
    {
        if (!function_exists('learndash_user_get_enrolled_courses')) {
            return [];
        }

        return learndash_user_get_enrolled_courses($userId);
    }

    public function getProgress(int $userId, int $courseId): int
    {
        if (!function_exists('learndash_course_progress')) {
            return 0;
        }

        $progress = learndash_course_progress([
            'user_id'   => $userId,
            'course_id' => $courseId,
            'array'     => true,
        ]);

        return (int) ($progress['percentage'] ?? 0);
    }

    public function getCompletionDate(int $userId, int $courseId): ?int
    {
        if (!$this->isComplete($userId, $courseId)) {
            return null;
        }

        if (!function_exists('learndash_user_get_course_completed_date')) {
            return null;
        }

        $timestamp = learndash_user_get_course_completed_date($userId, $courseId);

        return $timestamp ?: null;
    }
}
