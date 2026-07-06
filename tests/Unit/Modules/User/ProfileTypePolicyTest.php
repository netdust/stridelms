<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Modules\User;

use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Trajectory\TrajectoryRepository;
use Stride\Modules\User\ProfileTypePolicy;
use Stride\Modules\User\ProfileTypeService;
use Stride\Tests\TestCase;

/**
 * T2 (plan 2026-07-05-profiletype-visibility-filter §3, §4 M1/M2/M3) —
 * RED-first contract for the enroll-time profile-type policy.
 *
 * This is the enroll AUTHORIZATION core: `blocksEnrollment` is the server-side
 * denial the threat model (M1) leans on. The DENIAL PATH — a user whose stored
 * type has block:true is refused — is the whole point of the split and is asserted
 * first (`blocked_type_is_blocked`).
 *
 * Contract is derived from the plan's §4 threat model + §7 T2 acceptance line,
 * NOT from any implementation (the bodies are sentinels at RED time).
 *
 * Rules map shape resolved on the USER'S STORED type slug:
 *   { "<slug>": { "block": bool, "minimal": bool, "voucher": "<code>|null" } }
 * Fail-open everywhere: no rule / no user / deleted type ⇒ not blocked, full form,
 * no voucher.
 *
 * Mocks the three constructor deps (unit isolation): ProfileTypeService::getUserType
 * returns the full type array (policy extracts ['slug']); the two repos'
 * getProfiletypeRules return the rules map for the enrollable.
 */
class ProfileTypePolicyTest extends TestCase
{
    private const EDITION = 'vad_edition';
    private const TRAJECTORY = 'vad_trajectory';

    private ProfileTypeService $profileTypes;
    private EditionRepository $editions;
    private TrajectoryRepository $trajectories;

    protected function setUp(): void
    {
        parent::setUp();

        $this->profileTypes = $this->createMock(ProfileTypeService::class);
        $this->editions = $this->createMock(EditionRepository::class);
        $this->trajectories = $this->createMock(TrajectoryRepository::class);
    }

    private function policy(): ProfileTypePolicy
    {
        return new ProfileTypePolicy(
            $this->profileTypes,
            $this->editions,
            $this->trajectories,
        );
    }

    /**
     * Stub the user's stored type to a given slug (or null for no/deleted type).
     * getUserType returns the FULL type array; the policy extracts ['slug'].
     */
    private function userHasType(int $userId, ?string $slug): void
    {
        $this->profileTypes
            ->method('getUserType')
            ->willReturnCallback(function (int $id) use ($userId, $slug): ?array {
                if ($id !== $userId || $slug === null) {
                    return null;
                }

                return ['slug' => $slug, 'label' => ucfirst($slug), 'order' => 0];
            });
    }

    /**
     * @param array<string, array{block?: bool, minimal?: bool, voucher?: string|null}> $rules
     */
    private function editionRules(array $rules): void
    {
        $this->editions->method('getProfiletypeRules')->willReturn($rules);
    }

    /**
     * @param array<string, array{block?: bool, minimal?: bool, voucher?: string|null}> $rules
     */
    private function trajectoryRules(array $rules): void
    {
        $this->trajectories->method('getProfiletypeRules')->willReturn($rules);
    }

    // ── 1. DENIAL PATH (mandatory — the security boundary) ────────────────────

    public function testBlockedTypeIsBlocked(): void
    {
        $this->userHasType(7, 'werknemer');
        $this->editionRules([
            'werknemer' => ['block' => true, 'minimal' => false, 'voucher' => null],
        ]);

        $this->assertTrue(
            $this->policy()->blocksEnrollment(7, 100, self::EDITION),
            'A user whose stored type has block:true MUST be blocked from enrolling.',
        );
    }

    // ── 2. Fail-open: absent slug, empty map, block:false ─────────────────────

    public function testAbsentSlugInMapIsNotBlocked(): void
    {
        $this->userHasType(7, 'zelfstandige');
        $this->editionRules([
            'werknemer' => ['block' => true, 'minimal' => false, 'voucher' => null],
        ]);

        $this->assertFalse($this->policy()->blocksEnrollment(7, 100, self::EDITION));
    }

    public function testEmptyRulesMapIsNotBlocked(): void
    {
        $this->userHasType(7, 'werknemer');
        $this->editionRules([]);

        $this->assertFalse($this->policy()->blocksEnrollment(7, 100, self::EDITION));
    }

    public function testBlockFalseIsNotBlocked(): void
    {
        $this->userHasType(7, 'werknemer');
        $this->editionRules([
            'werknemer' => ['block' => false, 'minimal' => false, 'voucher' => null],
        ]);

        $this->assertFalse($this->policy()->blocksEnrollment(7, 100, self::EDITION));
    }

    // ── 3. Logged-out / no user → fail-open, never call getUserType(0) ────────

    public function testNullUserIsNotBlocked(): void
    {
        $this->editionRules([
            'werknemer' => ['block' => true, 'minimal' => false, 'voucher' => null],
        ]);

        $this->assertFalse($this->policy()->blocksEnrollment(null, 100, self::EDITION));
    }

    public function testZeroUserIsNotBlocked(): void
    {
        $this->editionRules([
            'werknemer' => ['block' => true, 'minimal' => false, 'voucher' => null],
        ]);

        $this->assertFalse($this->policy()->blocksEnrollment(0, 100, self::EDITION));
    }

    public function testCurrentUserTypeNullForNullUser(): void
    {
        $this->assertNull($this->policy()->currentUserType(null));
    }

    public function testCurrentUserTypeNullForZeroUser(): void
    {
        $this->assertNull($this->policy()->currentUserType(0));
    }

    public function testCurrentUserTypeReturnsStoredSlug(): void
    {
        $this->userHasType(7, 'werknemer');

        $this->assertSame('werknemer', $this->policy()->currentUserType(7));
    }

    // ── 4. Deleted type: stored slug no longer resolves → null → not blocked ──

    public function testDeletedTypeResolvesToNullAndIsNotBlocked(): void
    {
        // getUserType returns null (getType(slug) miss) for a deleted stored slug.
        $this->userHasType(7, null);
        $this->editionRules([
            'werknemer' => ['block' => true, 'minimal' => false, 'voucher' => null],
        ]);

        $this->assertNull($this->policy()->currentUserType(7));
        $this->assertFalse($this->policy()->blocksEnrollment(7, 100, self::EDITION));
    }

    // ── 5. usesMinimalForm ────────────────────────────────────────────────────

    public function testMinimalTrueUsesMinimalForm(): void
    {
        $this->userHasType(7, 'werknemer');
        $this->editionRules([
            'werknemer' => ['block' => false, 'minimal' => true, 'voucher' => null],
        ]);

        $this->assertTrue($this->policy()->usesMinimalForm(7, 100, self::EDITION));
    }

    public function testMinimalFalseUsesFullForm(): void
    {
        $this->userHasType(7, 'werknemer');
        $this->editionRules([
            'werknemer' => ['block' => false, 'minimal' => false, 'voucher' => null],
        ]);

        $this->assertFalse($this->policy()->usesMinimalForm(7, 100, self::EDITION));
    }

    public function testMinimalAbsentUsesFullForm(): void
    {
        $this->userHasType(7, 'zelfstandige');
        $this->editionRules([
            'werknemer' => ['block' => false, 'minimal' => true, 'voucher' => null],
        ]);

        $this->assertFalse($this->policy()->usesMinimalForm(7, 100, self::EDITION));
    }

    // ── 6. autoVoucherCode (money boundary — wrong type gets nothing) ─────────

    public function testAutoVoucherResolvesForType(): void
    {
        $this->userHasType(7, 'werknemer');
        $this->editionRules([
            'werknemer' => ['block' => false, 'minimal' => false, 'voucher' => 'CODEA'],
        ]);

        $this->assertSame('CODEA', $this->policy()->autoVoucherCode(7, 100, self::EDITION));
    }

    public function testAutoVoucherNullForWrongType(): void
    {
        // Type-B user against a map that only grants type-A a voucher → null.
        $this->userHasType(7, 'zelfstandige');
        $this->editionRules([
            'werknemer' => ['block' => false, 'minimal' => false, 'voucher' => 'CODEA'],
        ]);

        $this->assertNull($this->policy()->autoVoucherCode(7, 100, self::EDITION));
    }

    public function testAutoVoucherNullWhenTypeHasNoVoucher(): void
    {
        $this->userHasType(7, 'werknemer');
        $this->editionRules([
            'werknemer' => ['block' => false, 'minimal' => false, 'voucher' => null],
        ]);

        $this->assertNull($this->policy()->autoVoucherCode(7, 100, self::EDITION));
    }

    // ── 7. postType routing: trajectory branch reads the trajectory repo ──────

    public function testPostTypeRoutesToTrajectoryRepository(): void
    {
        $this->userHasType(7, 'werknemer');

        // SAME enrollable id, DIVERGENT maps per repo. The edition repo would NOT
        // block; the trajectory repo WOULD. Asserting blocked proves the trajectory
        // branch consulted the trajectory repo (outcome, not interaction).
        $this->editionRules([
            'werknemer' => ['block' => false, 'minimal' => false, 'voucher' => null],
        ]);
        $this->trajectoryRules([
            'werknemer' => ['block' => true, 'minimal' => false, 'voucher' => null],
        ]);

        $this->assertTrue(
            $this->policy()->blocksEnrollment(7, 100, self::TRAJECTORY),
            'vad_trajectory postType MUST resolve rules from the TrajectoryRepository.',
        );
    }

    public function testPostTypeRoutesToEditionRepository(): void
    {
        $this->userHasType(7, 'werknemer');

        $this->editionRules([
            'werknemer' => ['block' => true, 'minimal' => false, 'voucher' => null],
        ]);
        $this->trajectoryRules([
            'werknemer' => ['block' => false, 'minimal' => false, 'voucher' => null],
        ]);

        $this->assertTrue(
            $this->policy()->blocksEnrollment(7, 100, self::EDITION),
            'vad_edition postType MUST resolve rules from the EditionRepository.',
        );
    }

    // ── 8. Corrupt data fail-open (M1): a non-array rule row must not deny ─────
    //
    // Regression pins (Cluster-1 review, code-review S2). A rules map whose slug
    // maps to a SCALAR instead of {block,minimal,voucher} — e.g. from malformed
    // stored JSON — must fail OPEN across all three methods, never fatal, warn,
    // or truthily coerce a string into a block. Would go RED if resolveRule's
    // `is_array($row) ? $row : []` normalization were removed (a scalar row
    // makes `$row['block']` a TypeError / `(bool) $row` truthy).

    public function testMalformedScalarRuleRowDoesNotBlock(): void
    {
        $this->userHasType(7, 'werknemer');
        $this->editionRules(['werknemer' => 'block']); // scalar, not the expected array

        $this->assertFalse(
            $this->policy()->blocksEnrollment(7, 100, self::EDITION),
            'A corrupt (scalar) rule row MUST fail open — enrollment is not denied.',
        );
    }

    public function testMalformedScalarRuleRowHasNoAutoVoucher(): void
    {
        $this->userHasType(7, 'werknemer');
        $this->editionRules(['werknemer' => 'CODEA']); // scalar string, not an array

        $this->assertNull($this->policy()->autoVoucherCode(7, 100, self::EDITION));
    }

    public function testMalformedScalarRuleRowUsesFullForm(): void
    {
        $this->userHasType(7, 'werknemer');
        $this->editionRules(['werknemer' => 'minimal']); // scalar, not an array

        $this->assertFalse($this->policy()->usesMinimalForm(7, 100, self::EDITION));
    }

    // ── 9. Null/0 user fail-open on the OTHER two methods (security S2) ────────
    //
    // Regression pins. Only blocksEnrollment previously had null/0 user tests;
    // extend the same never-call-getUserType(0) guarantee to usesMinimalForm and
    // autoVoucherCode. Would go RED if currentUserType's `$userId === null ||
    // $userId === 0` short-circuit were removed (getUserType(0) is then called
    // and the guarantee breaks).

    public function testNullUserUsesFullForm(): void
    {
        $this->editionRules([
            'werknemer' => ['block' => false, 'minimal' => true, 'voucher' => null],
        ]);

        $this->assertFalse($this->policy()->usesMinimalForm(null, 100, self::EDITION));
    }

    public function testZeroUserUsesFullForm(): void
    {
        $this->editionRules([
            'werknemer' => ['block' => false, 'minimal' => true, 'voucher' => null],
        ]);

        $this->assertFalse($this->policy()->usesMinimalForm(0, 100, self::EDITION));
    }

    public function testNullUserHasNoAutoVoucher(): void
    {
        $this->editionRules([
            'werknemer' => ['block' => false, 'minimal' => false, 'voucher' => 'CODEA'],
        ]);

        $this->assertNull($this->policy()->autoVoucherCode(null, 100, self::EDITION));
    }

    public function testZeroUserHasNoAutoVoucher(): void
    {
        $this->editionRules([
            'werknemer' => ['block' => false, 'minimal' => false, 'voucher' => 'CODEA'],
        ]);

        $this->assertNull($this->policy()->autoVoucherCode(0, 100, self::EDITION));
    }
}
