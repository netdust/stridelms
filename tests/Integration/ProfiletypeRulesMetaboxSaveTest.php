<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Edition\Admin\EditionAdminController;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionRepository;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\Admin\TrajectoryAdminController;
use Stride\Modules\Trajectory\TrajectoryCPT;
use Stride\Modules\Trajectory\TrajectoryRepository;
use Stride\Modules\Trajectory\TrajectoryService;
use Stride\Modules\User\ProfileTypeService;

/**
 * T9 (concerns 2 + 3) — Rules + flag metabox save on BOTH Edition and Trajectory
 * admin controllers. RED-first contract test. ADMIN-WRITE + CAP BOUNDARY (Tier A).
 *
 * Plan: docs/plans/2026-07-05-profiletype-visibility-filter.md §4 M5, §5 Block-1,
 * §8 flow F, §7 T9.
 *
 * T9 adds to BOTH handleSave methods the read + sanitize + persist of two new
 * fields off $_POST['ntdst_fields']:
 *   - profiletype_rules — json map { "<slug>": { block: bool, minimal: bool,
 *     voucher: "<code>|null" } }
 *   - exclude_from_catalog — bool
 * ...through the existing nonce + cap + sanitize save path, read back through the
 * T1 typed getters (getProfiletypeRules / getExcludeFromCatalog).
 *
 * Contract asserted (from M5, the admin-write threat-model mitigation):
 *   1. ROUND-TRIP: a rules map + exclude_from_catalog=true POSTed through
 *      handleSave (valid nonce + a user who can edit_post) persists and reads back
 *      equal — on BOTH edition and trajectory.
 *   2. DENIAL — bad nonce (MANDATORY): a missing/invalid nonce → handleSave
 *      returns early → rules NOT persisted.
 *   3. DENIAL — non-cap user (MANDATORY): a user WITHOUT edit_post → rules NOT
 *      persisted.
 *   4. UNKNOWN-SLUG DROPPED (M5): a rules map carrying a slug NOT in
 *      ProfileTypeService::getTypes() → that slug's rule is DROPPED; only known
 *      slugs survive. (The admin-notice half of M5 is a secondary assertion the
 *      implementer must satisfy; the DROP is asserted firmly here.)
 *   5. SANITIZE: the voucher code is sanitize_text_field'd (HTML stripped),
 *      block/minimal cast to bool, exclude_from_catalog cast to bool, and a
 *      malformed rule value does not fatal.
 *
 * The two known profile-type slugs are seeded via the 'stride_profile_types'
 * option so ProfileTypeService::getTypes() (the allowlist source, concern 3)
 * returns exactly them; resetCache() discards any memo warmed by a prior class.
 *
 * This test is IMMUTABLE to the implementer: green it without weakening; escalate
 * (do not edit) if it is wrong.
 *
 * Run: STRIDE_TEST_DB_DISPOSABLE=1 ddev exec bash -c \
 *   'STRIDE_TEST_DB_DISPOSABLE=1 vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ProfiletypeRulesMetabox'
 */
final class ProfiletypeRulesMetaboxSaveTest extends IntegrationTestCase
{
    private const KNOWN_A = 'vrijwilliger';
    private const KNOWN_B = 'werknemer';
    private const UNKNOWN = 'ditbestaatniet';

    /** @var array<int> extra user ids to delete in tearDown */
    private array $extraUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Seed exactly two known slugs — the allowlist source (concern 3).
        update_option('stride_profile_types', [
            ['slug' => self::KNOWN_A, 'label' => 'Vrijwilliger', 'description' => '', 'color' => '', 'icon' => '', 'order' => 1],
            ['slug' => self::KNOWN_B, 'label' => 'Werknemer', 'description' => '', 'color' => '', 'icon' => '', 'order' => 2],
        ]);
        ntdst_get(ProfileTypeService::class)->resetCache();

        // handleSave's current_user_can('edit_post', $id) resolves via map_meta_cap
        // for the CPT — the base fixture user is a plain subscriber, so promote it
        // to administrator (same pattern as EditionAdminHandleSaveTest).
        wp_set_current_user((int) self::$testUserId);
        wp_get_current_user()->set_role('administrator');
    }

    protected function tearDown(): void
    {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($this->extraUserIds as $userId) {
            wp_delete_user($userId);
        }
        $this->extraUserIds = [];

        delete_option('stride_profile_types');
        ntdst_get(ProfileTypeService::class)->resetCache();
        wp_set_current_user(0);

        parent::tearDown();
    }

    // ======================================================================
    // 1. ROUND-TRIP — edition
    // ======================================================================

    public function test_edition_rules_and_flag_round_trip_through_handleSave(): void
    {
        $editionId = $this->createTestEdition();

        $rules = [
            self::KNOWN_A => ['block' => true,  'minimal' => false, 'voucher' => 'VRIJ10'],
            self::KNOWN_B => ['block' => false, 'minimal' => true,  'voucher' => null],
        ];

        $this->saveEdition($editionId, [
            'profiletype_rules'    => $rules,
            'exclude_from_catalog' => 1,
        ]);

        $persisted = $this->editionRepo()->getProfiletypeRules($editionId);
        $this->assertArrayHasKey(self::KNOWN_A, $persisted, 'known slug A rule must persist');
        $this->assertArrayHasKey(self::KNOWN_B, $persisted, 'known slug B rule must persist');
        $this->assertTrue((bool) $persisted[self::KNOWN_A]['block'], 'block must round-trip true for slug A');
        $this->assertTrue((bool) $persisted[self::KNOWN_B]['minimal'], 'minimal must round-trip true for slug B');
        $this->assertSame('VRIJ10', (string) $persisted[self::KNOWN_A]['voucher'], 'voucher code must round-trip');

        $this->assertTrue(
            $this->editionRepo()->getExcludeFromCatalog($editionId),
            'exclude_from_catalog must round-trip true on the edition',
        );
    }

    // ======================================================================
    // 1b. ROUND-TRIP — trajectory
    // ======================================================================

    public function test_trajectory_rules_and_flag_round_trip_through_handleSave(): void
    {
        $trajectoryId = $this->createTrajectory();

        $rules = [
            self::KNOWN_A => ['block' => false, 'minimal' => false, 'voucher' => 'TRAJ20'],
        ];

        $this->saveTrajectory($trajectoryId, [
            'profiletype_rules'    => $rules,
            'exclude_from_catalog' => 1,
        ]);

        $persisted = $this->trajectoryRepo()->getProfiletypeRules($trajectoryId);
        $this->assertArrayHasKey(self::KNOWN_A, $persisted, 'known slug rule must persist on the trajectory');
        $this->assertSame('TRAJ20', (string) $persisted[self::KNOWN_A]['voucher'], 'trajectory voucher code must round-trip');

        $this->assertTrue(
            $this->trajectoryRepo()->getExcludeFromCatalog($trajectoryId),
            'exclude_from_catalog must round-trip true on the trajectory',
        );
    }

    // ======================================================================
    // 1c. UN-CHECK — exclude_from_catalog can be CLEARED (PR #7 finding 1)
    // ======================================================================
    //
    // The metabox renders a BARE checkbox (no hidden companion), so unticking it
    // POSTs no exclude_from_catalog key at all. handleSave must still clear the
    // stored flag — otherwise a hidden edition/trajectory can never be un-hidden.
    // Pre-fix handleSave only wrote the flag inside `if (isset(...))`, so the
    // absent key left the stored '1' untouched.

    public function test_edition_exclude_from_catalog_can_be_uncleared(): void
    {
        $editionId = $this->createTestEdition();

        // 1) Save WITH the flag set → stored true.
        $this->saveEdition($editionId, ['exclude_from_catalog' => 1]);
        $this->assertTrue(
            $this->editionRepo()->getExcludeFromCatalog($editionId),
            'precondition: exclude_from_catalog must be true after a checked save',
        );

        // 2) Save again WITHOUT the checkbox key (an unticked bare checkbox POSTs
        //    nothing) → the flag must now be FALSE.
        $this->saveEdition($editionId, ['profiletype_rules' => []]);

        $this->assertFalse(
            $this->editionRepo()->getExcludeFromCatalog($editionId),
            'unticking the bare checkbox (no posted key) must CLEAR exclude_from_catalog',
        );
    }

    public function test_trajectory_exclude_from_catalog_can_be_uncleared(): void
    {
        $trajectoryId = $this->createTrajectory();

        $this->saveTrajectory($trajectoryId, ['exclude_from_catalog' => 1]);
        $this->assertTrue(
            $this->trajectoryRepo()->getExcludeFromCatalog($trajectoryId),
            'precondition: trajectory exclude_from_catalog must be true after a checked save',
        );

        $this->saveTrajectory($trajectoryId, ['profiletype_rules' => []]);

        $this->assertFalse(
            $this->trajectoryRepo()->getExcludeFromCatalog($trajectoryId),
            'unticking the bare checkbox must CLEAR trajectory exclude_from_catalog',
        );
    }

    // ======================================================================
    // 2. DENIAL — bad nonce → no write (MANDATORY)
    // ======================================================================

    public function test_edition_bad_nonce_does_not_persist_rules(): void
    {
        $editionId = $this->createTestEdition();

        $this->saveEdition($editionId, [
            'profiletype_rules'    => [self::KNOWN_A => ['block' => true, 'minimal' => false, 'voucher' => 'X']],
            'exclude_from_catalog' => 1,
        ], validNonce: false);

        $this->assertSame(
            [],
            $this->editionRepo()->getProfiletypeRules($editionId),
            'a bad nonce must cause handleSave to return early with NO rules write',
        );
        $this->assertFalse(
            $this->editionRepo()->getExcludeFromCatalog($editionId),
            'a bad nonce must NOT persist the exclude_from_catalog flag',
        );
    }

    public function test_trajectory_bad_nonce_does_not_persist_rules(): void
    {
        $trajectoryId = $this->createTrajectory();

        $this->saveTrajectory($trajectoryId, [
            'profiletype_rules'    => [self::KNOWN_A => ['block' => true, 'minimal' => false, 'voucher' => 'X']],
            'exclude_from_catalog' => 1,
        ], validNonce: false);

        $this->assertSame(
            [],
            $this->trajectoryRepo()->getProfiletypeRules($trajectoryId),
            'a bad nonce must cause the trajectory handleSave to return early with NO rules write',
        );
    }

    // ======================================================================
    // 3. DENIAL — non-cap user → no write (MANDATORY)
    // ======================================================================

    public function test_edition_non_cap_user_cannot_persist_rules(): void
    {
        $editionId = $this->createTestEdition();
        $subscriberId = $this->createSubscriber();

        // Act AS a user with no edit_post cap on the CPT. Nonce is valid — the
        // ONLY thing stopping the write is the capability guard.
        wp_set_current_user($subscriberId);

        $this->saveEdition($editionId, [
            'profiletype_rules'    => [self::KNOWN_A => ['block' => true, 'minimal' => false, 'voucher' => 'X']],
            'exclude_from_catalog' => 1,
        ], asUserId: $subscriberId);

        $this->assertSame(
            [],
            $this->editionRepo()->getProfiletypeRules($editionId),
            'a user without edit_post must NOT persist rules (cap guard)',
        );
        $this->assertFalse(
            $this->editionRepo()->getExcludeFromCatalog($editionId),
            'a user without edit_post must NOT persist the exclude_from_catalog flag',
        );
    }

    public function test_trajectory_non_cap_user_cannot_persist_rules(): void
    {
        $trajectoryId = $this->createTrajectory();
        $subscriberId = $this->createSubscriber();

        wp_set_current_user($subscriberId);

        $this->saveTrajectory($trajectoryId, [
            'profiletype_rules'    => [self::KNOWN_A => ['block' => true, 'minimal' => false, 'voucher' => 'X']],
            'exclude_from_catalog' => 1,
        ], asUserId: $subscriberId);

        $this->assertSame(
            [],
            $this->trajectoryRepo()->getProfiletypeRules($trajectoryId),
            'a user without edit_post must NOT persist trajectory rules (cap guard)',
        );
    }

    // ======================================================================
    // 4. UNKNOWN-SLUG DROPPED (M5, concern 3)
    // ======================================================================

    public function test_edition_unknown_slug_is_dropped_from_persisted_rules(): void
    {
        $editionId = $this->createTestEdition();

        $this->saveEdition($editionId, [
            'profiletype_rules' => [
                self::KNOWN_A  => ['block' => true,  'minimal' => false, 'voucher' => 'KEEP'],
                self::UNKNOWN  => ['block' => true,  'minimal' => true,  'voucher' => 'DROP'],
            ],
        ]);

        $persisted = $this->editionRepo()->getProfiletypeRules($editionId);

        $this->assertArrayHasKey(self::KNOWN_A, $persisted, 'a known slug must survive the save');
        $this->assertArrayNotHasKey(
            self::UNKNOWN,
            $persisted,
            'a slug not in ProfileTypeService::getTypes() must be DROPPED from the persisted rules',
        );
    }

    public function test_trajectory_unknown_slug_is_dropped_from_persisted_rules(): void
    {
        $trajectoryId = $this->createTrajectory();

        $this->saveTrajectory($trajectoryId, [
            'profiletype_rules' => [
                self::KNOWN_A  => ['block' => false, 'minimal' => false, 'voucher' => 'KEEP'],
                self::UNKNOWN  => ['block' => true,  'minimal' => true,  'voucher' => 'DROP'],
            ],
        ]);

        $persisted = $this->trajectoryRepo()->getProfiletypeRules($trajectoryId);

        $this->assertArrayHasKey(self::KNOWN_A, $persisted, 'a known slug must survive the trajectory save');
        $this->assertArrayNotHasKey(
            self::UNKNOWN,
            $persisted,
            'an unknown slug must be DROPPED from the persisted trajectory rules',
        );
    }

    // ======================================================================
    // 5. SANITIZE — voucher text-field'd, bools cast, malformed no-fatal
    // ======================================================================

    public function test_edition_rule_voucher_is_sanitized_and_bools_cast(): void
    {
        $editionId = $this->createTestEdition();

        $this->saveEdition($editionId, [
            'profiletype_rules' => [
                self::KNOWN_A => ['block' => '1', 'minimal' => '0', 'voucher' => '<b>ABC10</b>'],
            ],
        ]);

        $persisted = $this->editionRepo()->getProfiletypeRules($editionId);
        $this->assertArrayHasKey(self::KNOWN_A, $persisted);

        $this->assertSame(
            'ABC10',
            (string) $persisted[self::KNOWN_A]['voucher'],
            'the voucher code must be sanitize_text_field-ed (HTML stripped)',
        );
        $this->assertTrue((bool) $persisted[self::KNOWN_A]['block'], "block '1' must cast to true");
        $this->assertFalse((bool) $persisted[self::KNOWN_A]['minimal'], "minimal '0' must cast to false");
    }

    public function test_edition_malformed_rule_value_does_not_fatal(): void
    {
        $editionId = $this->createTestEdition();

        // A non-array rule value for a known slug — the sanitizer must not fatal.
        $this->saveEdition($editionId, [
            'profiletype_rules' => [
                self::KNOWN_A => 'not-an-array',
                self::KNOWN_B => ['block' => true, 'minimal' => false, 'voucher' => 'OK'],
            ],
        ]);

        // Getting here (no fatal) is the primary assertion; the well-formed rule
        // for the other known slug must still persist.
        $persisted = $this->editionRepo()->getProfiletypeRules($editionId);
        $this->assertArrayHasKey(
            self::KNOWN_B,
            $persisted,
            'a well-formed rule must persist even when a sibling rule value is malformed',
        );
    }

    // ======================================================================
    // Harness
    // ======================================================================

    private function editionRepo(): EditionRepository
    {
        return ntdst_get(EditionRepository::class);
    }

    private function trajectoryRepo(): TrajectoryRepository
    {
        return ntdst_get(TrajectoryRepository::class);
    }

    private function editionController(): EditionAdminController
    {
        return new EditionAdminController(
            ntdst_get(EditionService::class),
            ntdst_get(EditionRepository::class),
            ntdst_get(SessionService::class),
            ntdst_get(SessionRepository::class),
            ntdst_get(AttendanceRepository::class),
        );
    }

    private function trajectoryController(): TrajectoryAdminController
    {
        return new TrajectoryAdminController(
            ntdst_get(TrajectoryService::class),
            ntdst_get(TrajectoryRepository::class),
            ntdst_get(RegistrationRepository::class),
            ntdst_get(EditionRepository::class),
        );
    }

    private function createTrajectory(): int
    {
        $trajectoryId = wp_insert_post([
            'post_title'  => 'Metabox Trajectory ' . wp_generate_password(4, false),
            'post_type'   => TrajectoryCPT::POST_TYPE,
            'post_status' => 'publish',
        ]);
        self::assertIsInt($trajectoryId, 'fixture: failed to create trajectory');
        self::$testPosts[] = $trajectoryId;
        return $trajectoryId;
    }

    private function createSubscriber(): int
    {
        $username = 'noncap_' . wp_generate_password(6, false);
        $userId = wp_create_user($username, 'testpass123', $username . '@test.local');
        self::assertIsInt($userId, 'fixture: failed to create subscriber');
        wp_update_user(['ID' => $userId, 'role' => 'subscriber']);
        $this->extraUserIds[] = $userId;
        return $userId;
    }

    /**
     * Drive the real EditionAdminController::handleSave path.
     *
     * @param array<string, mixed> $fields  goes into $_POST['ntdst_fields']
     * @param bool $validNonce  false posts a deliberately invalid nonce
     * @param int|null $asUserId user whose nonce context to use (defaults to the
     *        admin fixture user); pass a non-cap user for the capability test
     */
    private function saveEdition(int $editionId, array $fields, bool $validNonce = true, ?int $asUserId = null): void
    {
        $userId = $asUserId ?? (int) self::$testUserId;
        wp_set_current_user($userId);

        $_POST[EditionAdminController::NONCE_FIELD] = $validNonce
            ? wp_create_nonce(EditionAdminController::NONCE_SAVE)
            : 'not-a-real-nonce';
        $_POST['ntdst_fields'] = $fields;

        $this->editionController()->handleSave($editionId, get_post($editionId));

        unset($_POST[EditionAdminController::NONCE_FIELD], $_POST['ntdst_fields']);
    }

    /**
     * Drive the real TrajectoryAdminController::handleSave path.
     *
     * @param array<string, mixed> $fields  goes into $_POST['ntdst_fields']
     */
    private function saveTrajectory(int $trajectoryId, array $fields, bool $validNonce = true, ?int $asUserId = null): void
    {
        $userId = $asUserId ?? (int) self::$testUserId;
        wp_set_current_user($userId);

        $_POST[TrajectoryAdminController::NONCE_FIELD] = $validNonce
            ? wp_create_nonce(TrajectoryAdminController::NONCE_SAVE)
            : 'not-a-real-nonce';
        $_POST['ntdst_fields'] = $fields;

        $this->trajectoryController()->handleSave($trajectoryId, get_post($trajectoryId));

        unset($_POST[TrajectoryAdminController::NONCE_FIELD], $_POST['ntdst_fields']);
    }
}
