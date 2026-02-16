<?php

declare(strict_types=1);

namespace Stride\Contracts;

use Stride\Domain\EditionStatus;

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
     * Get edition status.
     */
    public function getStatus(int $editionId): EditionStatus;

    /**
     * Get linked LearnDash course ID.
     */
    public function getCourseId(int $editionId): ?int;

    /**
     * Check if edition exists and is valid.
     */
    public function exists(int $editionId): bool;
}
