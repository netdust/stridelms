<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Domain;

use Stride\Domain\RegistrationStatus;
use Stride\Tests\TestCase;

/**
 * F-V6: ONE capacity definition. capacityValues() is the SQL-ready mirror of
 * countsTowardCapacity() — the capacity melding and
 * EditionService::getRegisteredCount both build their IN() from it, so the
 * "near capacity" alert and the waitlist-open queue can never again reason
 * over two different occupancy numbers (the shipped drift: confirmed-only vs
 * confirmed+completed+pending).
 */
final class RegistrationStatusCapacityTest extends TestCase
{
    public function test_capacity_values_mirror_the_predicate(): void
    {
        $expected = [];
        foreach (RegistrationStatus::cases() as $status) {
            if ($status->countsTowardCapacity()) {
                $expected[] = $status->value;
            }
        }

        $this->assertSame($expected, RegistrationStatus::capacityValues());
        $this->assertNotEmpty($expected);
    }

    public function test_the_seat_holding_set_is_the_documented_one(): void
    {
        // Deliberate duplication of the literal set: a change here is a
        // PRODUCT decision (which statuses hold a seat), not a refactor —
        // this test makes that change loud.
        $this->assertSame(['confirmed', 'completed', 'pending'], RegistrationStatus::capacityValues());
    }
}
