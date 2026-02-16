<?php

declare(strict_types=1);

namespace Stride\Contracts;

/**
 * LearnDash integration contract.
 *
 * Only 4 touch points with the LMS - keeps coupling minimal.
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
     * Get certificate download link if available.
     */
    public function getCertificateLink(int $userId, int $courseId): ?string;
}
