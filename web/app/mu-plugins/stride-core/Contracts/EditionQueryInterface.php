<?php

declare(strict_types=1);

namespace Stride\Contracts;

use Stride\Domain\OfferingStatus;

/**
 * Query interface for editions - used by other modules.
 *
 * Enrollment module depends on this interface, not EditionService directly.
 */
interface EditionQueryInterface
{
    /**
     * Check if edition has available spots.
     */
    public function hasAvailableSpots(int $editionId): bool;

    /**
     * Get current capacity count.
     */
    public function getRegisteredCount(int $editionId): int;

    /**
     * Get maximum capacity.
     */
    public function getCapacity(int $editionId): int;

    /**
     * Get stored edition status (admin intent).
     */
    public function getStatus(int $editionId): OfferingStatus;

    /**
     * Get display status for the public frontend.
     *
     * Stored status reflects admin intent, but past end_date and a few other
     * conditions can override what we actually show — use this anywhere a
     * status is shown to a visitor.
     */
    public function getEffectiveStatus(int $editionId): OfferingStatus;

    /**
     * True when the edition's end_date (or start_date as fallback) is in
     * the past. Pure calendar check — independent of OfferingStatus.
     */
    public function isPast(int $editionId): bool;

    /**
     * Get linked LearnDash course ID.
     */
    public function getCourseId(int $editionId): ?int;

    /**
     * Check if edition exists and is valid.
     */
    public function exists(int $editionId): bool;

    /**
     * Check if edition requires admin approval for enrollment.
     */
    public function requiresApproval(int $editionId): bool;
}
