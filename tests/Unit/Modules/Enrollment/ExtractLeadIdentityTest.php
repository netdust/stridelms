<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Modules\Enrollment;

use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Tests\TestCase;

/**
 * Unit: the single lead-identity extractor (F-G3).
 *
 * The write paths (create/reactivate/update), the v5 migration backfill and
 * the grid search all depend on THIS definition of where an anonymous lead's
 * name/email live: enrollment_data[interest|waitlist]['data']['name'|'email'].
 */
final class ExtractLeadIdentityTest extends TestCase
{
    public function test_extracts_from_the_interest_stage(): void
    {
        $identity = RegistrationRepository::extractLeadIdentity([
            'interest' => ['submitted_at' => 'x', 'submitted_by' => null, 'data' => [
                'name' => 'Anna Voorbeeld', 'email' => 'anna@example.test',
            ]],
        ]);

        $this->assertSame('Anna Voorbeeld', $identity['name']);
        $this->assertSame('anna@example.test', $identity['email']);
    }

    public function test_falls_through_to_the_waitlist_stage(): void
    {
        $identity = RegistrationRepository::extractLeadIdentity([
            'waitlist' => ['data' => ['name' => 'Bert Wacht', 'email' => 'bert@example.test']],
        ]);

        $this->assertSame('Bert Wacht', $identity['name']);
    }

    public function test_interest_wins_over_waitlist_when_both_present(): void
    {
        $identity = RegistrationRepository::extractLeadIdentity([
            'interest' => ['data' => ['name' => 'Eerste', 'email' => 'a@a.test']],
            'waitlist' => ['data' => ['name' => 'Tweede', 'email' => 'b@b.test']],
        ]);

        $this->assertSame('Eerste', $identity['name']);
    }

    public function test_empty_or_foreign_stages_yield_empty_identity(): void
    {
        $this->assertSame(['name' => '', 'email' => ''], RegistrationRepository::extractLeadIdentity([]));
        $this->assertSame(['name' => '', 'email' => ''], RegistrationRepository::extractLeadIdentity([
            'enrollment_personal' => ['data' => ['name' => 'Geen lead']],
        ]));
        $this->assertSame(['name' => '', 'email' => ''], RegistrationRepository::extractLeadIdentity([
            'interest' => ['data' => []],
        ]));
        $this->assertSame(['name' => '', 'email' => ''], RegistrationRepository::extractLeadIdentity([
            'interest' => 'not-an-envelope',
        ]));
    }

    public function test_values_are_sanitized_and_length_capped(): void
    {
        $identity = RegistrationRepository::extractLeadIdentity([
            'interest' => ['data' => [
                'name' => "  <script>x</script>" . str_repeat('a', 300),
                'email' => 'mail@example.test',
            ]],
        ]);

        $this->assertStringNotContainsString('<script>', $identity['name']);
        $this->assertLessThanOrEqual(191, mb_strlen($identity['name']));
        $this->assertSame('mail@example.test', $identity['email']);
    }
}
