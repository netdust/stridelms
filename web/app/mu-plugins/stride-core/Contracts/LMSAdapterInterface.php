<?php

declare(strict_types=1);

namespace Stride\Contracts;

/**
 * LMS integration contract.
 *
 * Business operations only — the write/critical-read points with the LMS.
 * For read-only presentation data (progress, certificates, lessons),
 * use LearnDashHelper static methods.
 */
interface LMSAdapterInterface
{
    /**
     * Grant course access to user.
     */
    public function grantAccess(int $userId, int $courseId): bool;

    /**
     * Revoke course access from user.
     */
    public function revokeAccess(int $userId, int $courseId): bool;

    /**
     * Check if user has completed the course.
     */
    public function isComplete(int $userId, int $courseId): bool;

    /**
     * Request the LMS to mark the course complete for the user.
     *
     * Note: the LMS may enforce its own completion rules (required lessons,
     * quizzes, etc.) and treat this as a no-op when they aren't satisfied.
     * Returns true when the call was dispatched to the LMS, false when the
     * LMS isn't available or the course is invalid — NOT a guarantee that
     * the user is now marked complete.
     */
    public function markComplete(int $userId, int $courseId): bool;

    /**
     * Is the course configured as "open" (auto-enroll on lesson access,
     * no enrollment record required)?
     *
     * Encapsulates the LMS's course-pricing-type concept so callers don't
     * have to reach into the LMS's internal meta shape.
     */
    public function isOpenCourse(int $courseId): bool;
}
