<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Modules\Enrollment;

use Stride\Domain\RegistrationStatus;
use Stride\Modules\Enrollment\RegistrationTransitions;
use Stride\Tests\TestCase;

/**
 * Tests the RegistrationTransitions state machine — the single source of truth
 * for valid registration lifecycle state transitions.
 *
 * Tier A: pure state machine with branching logic.
 */
final class RegistrationTransitionsTest extends TestCase
{
    // --- validFor ---

    public function testValidForConfirmedIncludesCancelled(): void
    {
        $allowed = RegistrationTransitions::validFor(RegistrationStatus::Confirmed);

        $this->assertContains(RegistrationStatus::Cancelled, $allowed);
    }

    public function testValidForConfirmedExcludesPending(): void
    {
        $allowed = RegistrationTransitions::validFor(RegistrationStatus::Confirmed);

        $this->assertNotContains(RegistrationStatus::Pending, $allowed);
    }

    public function testValidForCancelledIsEmptyArray(): void
    {
        $allowed = RegistrationTransitions::validFor(RegistrationStatus::Cancelled);

        $this->assertSame([], $allowed);
    }

    public function testValidForInterestContainsExactlyPendingAndCancelled(): void
    {
        $allowed = RegistrationTransitions::validFor(RegistrationStatus::Interest);

        // Both must be present
        $this->assertContains(RegistrationStatus::Pending, $allowed);
        $this->assertContains(RegistrationStatus::Cancelled, $allowed);
        // Nothing else — exactly 2 elements
        $this->assertCount(2, $allowed);
    }

    public function testValidForPendingContainsOnlyConfirmed(): void
    {
        $allowed = RegistrationTransitions::validFor(RegistrationStatus::Pending);

        $this->assertCount(1, $allowed);
        $this->assertContains(RegistrationStatus::Confirmed, $allowed);
    }

    public function testValidForWaitlistContainsOnlyConfirmed(): void
    {
        $allowed = RegistrationTransitions::validFor(RegistrationStatus::Waitlist);

        $this->assertCount(1, $allowed);
        $this->assertContains(RegistrationStatus::Confirmed, $allowed);
    }

    public function testValidForCompletedIsEmptyArray(): void
    {
        $allowed = RegistrationTransitions::validFor(RegistrationStatus::Completed);

        $this->assertSame([], $allowed);
    }

    // --- isTerminal ---

    public function testIsTerminalForCompleted(): void
    {
        $this->assertTrue(RegistrationTransitions::isTerminal(RegistrationStatus::Completed));
    }

    public function testIsTerminalForCancelled(): void
    {
        $this->assertTrue(RegistrationTransitions::isTerminal(RegistrationStatus::Cancelled));
    }

    public function testIsNotTerminalForConfirmed(): void
    {
        $this->assertFalse(RegistrationTransitions::isTerminal(RegistrationStatus::Confirmed));
    }

    public function testIsNotTerminalForPending(): void
    {
        $this->assertFalse(RegistrationTransitions::isTerminal(RegistrationStatus::Pending));
    }

    public function testIsNotTerminalForWaitlist(): void
    {
        $this->assertFalse(RegistrationTransitions::isTerminal(RegistrationStatus::Waitlist));
    }

    public function testIsNotTerminalForInterest(): void
    {
        $this->assertFalse(RegistrationTransitions::isTerminal(RegistrationStatus::Interest));
    }

    // --- isAllowed ---

    public function testIsAllowedPendingToConfirmed(): void
    {
        $this->assertTrue(
            RegistrationTransitions::isAllowed(RegistrationStatus::Pending, RegistrationStatus::Confirmed)
        );
    }

    public function testIsAllowedWaitlistToConfirmed(): void
    {
        $this->assertTrue(
            RegistrationTransitions::isAllowed(RegistrationStatus::Waitlist, RegistrationStatus::Confirmed)
        );
    }

    public function testIsAllowedConfirmedToCancelled(): void
    {
        $this->assertTrue(
            RegistrationTransitions::isAllowed(RegistrationStatus::Confirmed, RegistrationStatus::Cancelled)
        );
    }

    public function testIsAllowedInterestToPending(): void
    {
        $this->assertTrue(
            RegistrationTransitions::isAllowed(RegistrationStatus::Interest, RegistrationStatus::Pending)
        );
    }

    public function testIsAllowedInterestToCancelled(): void
    {
        $this->assertTrue(
            RegistrationTransitions::isAllowed(RegistrationStatus::Interest, RegistrationStatus::Cancelled)
        );
    }

    // --- denial paths (the gate must reject these) ---

    public function testIsNotAllowedCancelledToConfirmed(): void
    {
        $this->assertFalse(
            RegistrationTransitions::isAllowed(RegistrationStatus::Cancelled, RegistrationStatus::Confirmed)
        );
    }

    public function testIsNotAllowedCompletedToConfirmed(): void
    {
        $this->assertFalse(
            RegistrationTransitions::isAllowed(RegistrationStatus::Completed, RegistrationStatus::Confirmed)
        );
    }

    public function testIsNotAllowedCompletedToCancelled(): void
    {
        $this->assertFalse(
            RegistrationTransitions::isAllowed(RegistrationStatus::Completed, RegistrationStatus::Cancelled)
        );
    }

    public function testIsNotAllowedConfirmedToConfirmed(): void
    {
        // Self-transition is not in the map
        $this->assertFalse(
            RegistrationTransitions::isAllowed(RegistrationStatus::Confirmed, RegistrationStatus::Confirmed)
        );
    }

    public function testIsNotAllowedPendingToCancelled(): void
    {
        // Pending can only go to Confirmed per the map
        $this->assertFalse(
            RegistrationTransitions::isAllowed(RegistrationStatus::Pending, RegistrationStatus::Cancelled)
        );
    }
}
