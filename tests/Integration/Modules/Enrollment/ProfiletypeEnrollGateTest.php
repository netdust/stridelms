<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Modules\Enrollment;

use IntegrationTestCase;
use Stride\Modules\Edition\EditionCPT;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryCPT;
use Stride\Modules\Trajectory\TrajectorySelection;
use Stride\Modules\User\ProfileTypeService;
use Stride\Domain\RegistrationStatus;

/**
 * T4 — Profile-type ENROLL GATE (M1). RED-first contract test.
 *
 * Plan: docs/plans/2026-07-05-profiletype-visibility-filter.md §4 M1 (CORRECTED),
 * §6.2, §7 T4. Threat model M1: a profile type marked `block:true` genuinely
 * CANNOT create a registration on ANY user-initiated write path.
 *
 * This is the DENIAL-PATH contract for a USER-level authorization boundary
 * (Tier A, erosion guard). It asserts:
 *   - THREE user-initiated seams return WP_Error('profiletype_blocked') for a
 *     blocked type AND create no registration row:
 *       (1) EnrollmentService::enroll()            — vad_edition
 *       (2) EnrollmentService::registerWaitlist()  — edition AND trajectory
 *       (3) TrajectorySelection::enroll()          — vad_trajectory (+ no cascade)
 *   - the SAME seams SUCCEED for an allowed type (positive control).
 *   - ADMIN OVERRIDE: a blocked-type user already on the waitlist is still
 *     promoted by promoteFromWaitlist() — proving the block is user-level, not
 *     admin-level (the gate must NOT re-fire at promotion).
 *   - EXEMPT: a blocked type may still registerInterest().
 *   - FAIL-OPEN: a user with no rule enrolls normally.
 *
 * Gate: profiletype_blocked is the agreed WP_Error code — the implementer must
 * match it. This test is IMMUTABLE to the implementer: green it without
 * weakening; escalate (do not edit) if it is wrong.
 *
 * Run: STRIDE_TEST_DB_DISPOSABLE=1 ddev exec bash -c \
 *   'STRIDE_TEST_DB_DISPOSABLE=1 vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ProfiletypeEnrollGate'
 */
final class ProfiletypeEnrollGateTest extends IntegrationTestCase
{
    private const BLOCKED_SLUG = 'werknemer';
    private const ALLOWED_SLUG = 'zelfstandige';
    private const WP_ERROR_CODE = 'profiletype_blocked';

    private EnrollmentService $enrollment;
    private TrajectorySelection $selection;
    private RegistrationRepository $repo;

    /** @var array<int> registration ids to hard-delete in tearDown */
    private array $createdRegistrationIds = [];
    /** @var array<int> user ids to delete in tearDown */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->enrollment = ntdst_get(EnrollmentService::class);
        $this->selection  = ntdst_get(TrajectorySelection::class);
        $this->repo       = ntdst_get(RegistrationRepository::class);

        // Define the two profile types this suite keys on. ProfileTypeService
        // reads them from the 'stride_profile_types' option; seed it so
        // setUserType() (which validates against getType()) accepts both slugs.
        update_option('stride_profile_types', [
            ['slug' => self::BLOCKED_SLUG, 'label' => 'Werknemer', 'description' => '', 'color' => '', 'icon' => '', 'order' => 1],
            ['slug' => self::ALLOWED_SLUG, 'label' => 'Zelfstandige', 'description' => '', 'color' => '', 'icon' => '', 'order' => 2],
        ]);
        // ProfileTypeService memoises getTypes() per-instance; force a fresh read.
        ntdst_get(ProfileTypeService::class)->getTypes();
    }

    protected function tearDown(): void
    {
        global $wpdb;

        foreach ($this->createdRegistrationIds as $id) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $id]);
        }
        $this->createdRegistrationIds = [];

        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($this->createdUserIds as $userId) {
            wp_delete_user($userId);
        }
        $this->createdUserIds = [];

        delete_option('stride_profile_types');
        wp_set_current_user(0);

        parent::tearDown();
    }

    // === (1) enroll() — vad_edition =========================================

    /** @test */
    public function blockedTypeCannotEnrollEdition(): void
    {
        $userId    = $this->createUserOfType(self::BLOCKED_SLUG);
        $editionId = $this->createOpenEditionBlocking(self::BLOCKED_SLUG);

        wp_set_current_user($userId);
        $result = $this->enrollment->enroll($userId, $editionId);

        $this->assertWPError($result, 'A blocked profile type must not be able to enroll in an edition');
        $this->assertSame(self::WP_ERROR_CODE, $result->get_error_code());
        $this->assertNull(
            $this->repo->findByUserAndEdition($userId, $editionId),
            'No registration row may be created when enrollment is blocked'
        );
    }

    /** @test */
    public function allowedTypeCanEnrollEdition(): void
    {
        $userId    = $this->createUserOfType(self::ALLOWED_SLUG);
        // Rules block a DIFFERENT type; the allowed type has no rule → fail-open.
        $editionId = $this->createOpenEditionBlocking(self::BLOCKED_SLUG);

        wp_set_current_user($userId);
        $result = $this->enrollment->enroll($userId, $editionId);

        $this->assertIsInt($result, 'An allowed profile type must be able to enroll: ' . $this->err($result));
        $this->trackRegistration($result);
    }

    // === (2a) registerWaitlist() — edition ==================================

    /** @test */
    public function blockedTypeCannotWaitlistEdition(): void
    {
        $userId    = $this->createUserOfType(self::BLOCKED_SLUG);
        $editionId = $this->createFullEditionBlocking(self::BLOCKED_SLUG);

        wp_set_current_user($userId);
        $result = $this->enrollment->registerWaitlist($userId, ['edition_id' => $editionId]);

        $this->assertWPError($result, 'A blocked profile type must not be able to self-waitlist an edition');
        $this->assertSame(self::WP_ERROR_CODE, $result->get_error_code());
        $this->assertNull($this->repo->findByUserAndEdition($userId, $editionId));
    }

    /** @test */
    public function allowedTypeCanWaitlistEdition(): void
    {
        $userId    = $this->createUserOfType(self::ALLOWED_SLUG);
        $editionId = $this->createFullEditionBlocking(self::BLOCKED_SLUG);

        wp_set_current_user($userId);
        $result = $this->enrollment->registerWaitlist($userId, ['edition_id' => $editionId]);

        $this->assertIsInt($result, 'An allowed type must be able to self-waitlist: ' . $this->err($result));
        $this->trackRegistration($result);
    }

    // === (2b) registerWaitlist() — trajectory ===============================

    /** @test */
    public function blockedTypeCannotWaitlistTrajectory(): void
    {
        $userId       = $this->createUserOfType(self::BLOCKED_SLUG);
        $trajectoryId = $this->createTrajectoryBlocking('full', self::BLOCKED_SLUG);

        wp_set_current_user($userId);
        $result = $this->enrollment->registerWaitlist($userId, ['trajectory_id' => $trajectoryId]);

        $this->assertWPError($result, 'A blocked profile type must not be able to self-waitlist a trajectory');
        $this->assertSame(self::WP_ERROR_CODE, $result->get_error_code());
        $this->assertNull($this->repo->findByUserAndTrajectory($userId, $trajectoryId));
    }

    /** @test */
    public function allowedTypeCanWaitlistTrajectory(): void
    {
        $userId       = $this->createUserOfType(self::ALLOWED_SLUG);
        $trajectoryId = $this->createTrajectoryBlocking('full', self::BLOCKED_SLUG);

        wp_set_current_user($userId);
        $result = $this->enrollment->registerWaitlist($userId, ['trajectory_id' => $trajectoryId]);

        $this->assertIsInt($result, 'An allowed type must be able to self-waitlist a trajectory: ' . $this->err($result));
        $this->trackRegistration($result);
    }

    // === (3) TrajectorySelection::enroll() — vad_trajectory =================

    /** @test */
    public function blockedTypeCannotEnrollTrajectoryAndNoCascade(): void
    {
        $userId       = $this->createUserOfType(self::BLOCKED_SLUG);
        $trajectoryId = $this->createTrajectoryBlocking('open', self::BLOCKED_SLUG);

        wp_set_current_user($userId);
        $result = $this->selection->enroll($userId, $trajectoryId);

        $this->assertWPError($result, 'A blocked profile type must not be able to enroll in a trajectory');
        $this->assertSame(self::WP_ERROR_CODE, $result->get_error_code());
        $this->assertNull(
            $this->repo->findByUserAndTrajectory($userId, $trajectoryId),
            'No trajectory registration may be created when blocked'
        );
        $this->assertSame(
            [],
            $this->repo->findByUser($userId),
            'No cascade child registrations may be created when the parent is blocked'
        );
    }

    /** @test */
    public function allowedTypeCanEnrollTrajectory(): void
    {
        $userId       = $this->createUserOfType(self::ALLOWED_SLUG);
        $trajectoryId = $this->createTrajectoryBlocking('open', self::BLOCKED_SLUG);

        wp_set_current_user($userId);
        $result = $this->selection->enroll($userId, $trajectoryId);

        $this->assertIsInt($result, 'An allowed type must be able to enroll in a trajectory: ' . $this->err($result));
        $this->trackRegistration($result);
        foreach ($this->repo->findByParent($result) as $child) {
            $this->trackRegistration((int) $child->id);
        }
    }

    // === ADMIN OVERRIDE (critical) ==========================================

    /** @test */
    public function adminCanPromoteBlockedTypeFromWaitlist(): void
    {
        $userId    = $this->createUserOfType(self::BLOCKED_SLUG);
        $editionId = $this->createFullEditionBlocking(self::BLOCKED_SLUG);

        // Admin puts the blocked-type user on the waitlist directly, bypassing
        // the user-level gate (exactly as an admin action would). Then set the
        // edition Open so a seat exists for promotion.
        $regId = $this->repo->create([
            'user_id'    => $userId,
            'edition_id' => $editionId,
            'status'     => RegistrationStatus::Waitlist->value,
        ]);
        $this->assertIsInt($regId, 'fixture: could not seed waitlist row');
        $this->trackRegistration($regId);

        $this->setEditionStatus($editionId, 'open');

        $result = $this->enrollment->promoteFromWaitlist($regId);

        $this->assertNotWPError(
            $result,
            'Admin promotion of a blocked-type waitlist row must SUCCEED — the block is user-level, not admin-level: ' . $this->err($result)
        );
        $row = $this->repo->find($regId);
        $this->assertSame(
            RegistrationStatus::Confirmed->value,
            $row->status,
            'Promotion must transition the blocked-type user to Confirmed'
        );
    }

    // === EXEMPT: interest ====================================================

    /** @test */
    public function blockedTypeCanRegisterInterest(): void
    {
        $userId    = $this->createUserOfType(self::BLOCKED_SLUG);
        $editionId = $this->createAnnouncementEditionBlocking(self::BLOCKED_SLUG);

        wp_set_current_user($userId);
        $result = $this->enrollment->registerInterest($userId, ['edition_id' => $editionId]);

        $this->assertIsInt($result, 'Interest is a lead signal exempt from the block: ' . $this->err($result));
        $this->trackRegistration($result);
    }

    // === FAIL-OPEN: no rule ==================================================

    /** @test */
    public function typeWithNoRuleEnrollsNormally(): void
    {
        $userId    = $this->createUserOfType(self::BLOCKED_SLUG);
        // Edition has NO profiletype_rules at all → fail-open, anyone enrolls.
        $editionId = $this->createTestEdition();

        wp_set_current_user($userId);
        $result = $this->enrollment->enroll($userId, $editionId);

        $this->assertIsInt($result, 'No rules ⇒ fail-open, enrollment proceeds: ' . $this->err($result));
        $this->trackRegistration($result);
    }

    // === Fixtures ===========================================================

    private function createUserOfType(string $slug): int
    {
        $username = 'ptgate_' . wp_generate_password(6, false);
        $userId = wp_create_user($username, 'testpass123', $username . '@test.local');
        $this->assertIsInt($userId, 'fixture: failed to create user');
        $this->createdUserIds[] = $userId;
        update_user_meta($userId, '_stride_profile_type', [$slug]);
        return $userId;
    }

    /** Open edition (allows enroll), blocking the given profile-type slug. */
    private function createOpenEditionBlocking(string $slug): int
    {
        $editionId = $this->createTestEdition();
        $this->setEditionStatus($editionId, 'open');
        $this->writeRules(EditionCPT::POST_TYPE, $editionId, $slug);
        return $editionId;
    }

    /** Full edition (allows waitlist), blocking the given profile-type slug. */
    private function createFullEditionBlocking(string $slug): int
    {
        $editionId = $this->createTestEdition();
        $this->setEditionStatus($editionId, 'full');
        $this->writeRules(EditionCPT::POST_TYPE, $editionId, $slug);
        return $editionId;
    }

    /** Announcement edition (allows interest), blocking the given slug. */
    private function createAnnouncementEditionBlocking(string $slug): int
    {
        $editionId = $this->createTestEdition();
        $this->setEditionStatus($editionId, 'announcement');
        $this->writeRules(EditionCPT::POST_TYPE, $editionId, $slug);
        return $editionId;
    }

    /**
     * Trajectory in the given status ('open' for enroll, 'full' for waitlist),
     * blocking the given profile-type slug.
     */
    private function createTrajectoryBlocking(string $status, string $slug): int
    {
        $trajectoryId = wp_insert_post([
            'post_type'   => TrajectoryCPT::POST_TYPE,
            'post_title'  => 'PT-gate trajectory ' . wp_generate_password(6, false),
            'post_status' => 'publish',
        ]);
        $this->assertIsInt($trajectoryId, 'fixture: failed to create trajectory');
        self::$testPosts[] = $trajectoryId;

        $model = ntdst_data()->get(TrajectoryCPT::POST_TYPE);
        $model->update($trajectoryId, [
            'status'   => $status,
            'capacity' => 0, // 0 = unlimited in TrajectorySelection::hasCapacity
        ]);
        $this->writeRules(TrajectoryCPT::POST_TYPE, $trajectoryId, $slug);
        return $trajectoryId;
    }

    /** Write a block:true rule for $slug via the SAME model getProfiletypeRules reads through. */
    private function writeRules(string $postType, int $id, string $slug): void
    {
        ntdst_data()->get($postType)->update($id, [
            'profiletype_rules' => [
                $slug => ['block' => true, 'minimal' => false, 'voucher' => null],
            ],
        ]);
    }

    private function setEditionStatus(int $editionId, string $status): void
    {
        ntdst_data()->get(EditionCPT::POST_TYPE)->update($editionId, ['status' => $status]);
    }

    private function trackRegistration(int $id): void
    {
        $this->createdRegistrationIds[] = $id;
    }

    private function err(mixed $result): string
    {
        return is_wp_error($result) ? $result->get_error_message() : '(not a WP_Error)';
    }

    private function assertNotWPError(mixed $value, string $message = ''): void
    {
        $this->assertFalse(is_wp_error($value), $message);
    }

    private function assertWPError(mixed $value, string $message = ''): void
    {
        $this->assertTrue(is_wp_error($value), $message);
    }
}
