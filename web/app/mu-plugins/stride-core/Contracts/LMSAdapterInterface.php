<?php

declare(strict_types=1);

namespace Stride\Contracts;

/**
 * LearnDash integration contract.
 *
 * 3 business operations — the only write/critical-read points with the LMS.
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
}
