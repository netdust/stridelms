<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Contracts\LMSAdapterInterface;
use Stride\Domain\OfferingStatus;
use Stride\Domain\TrajectoryMode;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\Admin\TrajectoryAdminController;
use Stride\Modules\Trajectory\TrajectoryRepository;
use Stride\Modules\Trajectory\TrajectoryService;

/**
 * Characterization + regression guard for TrajectoryAdminController::handleSave()
 * scalar/boolean/deadline/mode field persistence, driven through the REAL admin
 * save path (nonce + edit_post cap + $_POST['ntdst_fields']), read back through
 * the repository.
 *
 * Pins, against the source (TrajectoryAdminController.php lines cited inline):
 *  - PRICE euros->cents SINGLE-price dual-write: posting `price_non_member`
 *    writes the SAME cents to BOTH `price` AND `price_non_member` (EQUAL), and
 *    the legacy `price`-only path also dual-writes both equal — mirroring
 *    EditionAdminController. v1 has NO member tier; discounts come from vouchers.
 *  - CAPACITY absint (1114-1116).
 *  - LIFECYCLE booleans + the isset-guard "no clobber" contract (1118-1137).
 *  - enrollment_form sanitized text (1123-1124).
 *  - deadline_months absint(...) ?: null on WRITE (1146-1148); a posted 0 clears
 *    the deadline (reads back falsy 0 via the int-typed schema, Data.php:1484).
 *  - mode + status validated enums: valid persists, INVALID is rejected /
 *    leaves the prior value untouched (1083-1095).
 *
 * Plus ONE T-3 characterization pinning a KNOWN cosmetic limitation
 * (completedCourses hardcoded to 0 at line 686) — see the KNOWN_LIMITATION test.
 *
 * Run (disposable-DB gate forwarded via --raw; a silent exit 255 = a swallowed
 * PHP fatal, not "no tests matched"):
 *   ddev exec --raw -- bash -c 'cd /var/www/html; STRIDE_TEST_DB_DISPOSABLE=1 \
 *     vendor/bin/phpunit -c phpunit-integration.xml.dist \
 *     --filter TrajectoryPriceLifecycleSave'
 */
final class TrajectoryPriceLifecycleSaveTest extends IntegrationTestCase
{
    private TrajectoryRepository $repo;
    private int $trajectoryId;

    /** @var array<int> Registrations created by a test, deleted in tearDown to avoid polluting RegistrationGridQueryTest's row counts in a full-suite run. */
    private array $createdRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = ntdst_get(TrajectoryRepository::class);

        // handleSave()'s current_user_can('edit_post', $id) guard resolves via
        // map_meta_cap for the vad_trajectory CPT — promote the fixture user to
        // administrator so the save is allowed (same pattern as the
        // TrajectoryElectivePickCountRoundTrip integration test).
        wp_set_current_user((int) self::$testUserId);
        wp_get_current_user()->set_role('administrator');

        $this->trajectoryId = wp_insert_post([
            'post_type'   => 'vad_trajectory',
            'post_title'  => 'Price Lifecycle Save Test ' . uniqid(),
            'post_status' => 'publish',
        ]);
        self::$testPosts[] = $this->trajectoryId;
    }

    protected function tearDown(): void
    {
        foreach ($this->createdRegistrationIds as $regId) {
            $this->deleteTestRegistration($regId);
        }
        $this->createdRegistrationIds = [];

        parent::tearDown();
    }

    private function controller(): TrajectoryAdminController
    {
        return new TrajectoryAdminController(
            ntdst_get(TrajectoryService::class),
            ntdst_get(TrajectoryRepository::class),
            ntdst_get(RegistrationRepository::class),
            ntdst_get(EditionRepository::class),
        );
    }

    /**
     * Drive the real admin save path. Creates the nonce AS the current user
     * (nonces are user-context-dependent), merges the posted fields into $_POST,
     * invokes handleSave(), then cleans up $_POST.
     *
     * @param array<string, mixed> $fields Value for $_POST['ntdst_fields']
     */
    private function save(array $fields): void
    {
        wp_set_current_user((int) self::$testUserId);

        $_POST[TrajectoryAdminController::NONCE_FIELD] =
            wp_create_nonce(TrajectoryAdminController::NONCE_SAVE);
        $_POST['ntdst_fields'] = $fields;

        $this->controller()->handleSave($this->trajectoryId, get_post($this->trajectoryId));

        unset($_POST[TrajectoryAdminController::NONCE_FIELD], $_POST['ntdst_fields']);
    }

    /**
     * Read a stored trajectory field back through the repository. NOT named
     * status() — that collides with a PHPUnit final method (silent exit 255).
     */
    private function field(string $name, mixed $default = null): mixed
    {
        return $this->repo->getField($this->trajectoryId, $name, $default);
    }

    // ---------------------------------------------------------------
    // 1 + 2. PRICE: euros->cents, SINGLE-price dual-write (price == price_non_member)
    // ---------------------------------------------------------------

    /**
     * v1 has NO member tier — one price, discounts come from vouchers. Posting
     * `price_non_member` (the canonical single-price key) writes the SAME cents
     * to BOTH `price` AND `price_non_member`, EQUAL — mirroring
     * EditionAdminController::handleSave (lines ~439-448).
     */
    public function testPriceNonMemberDualWritesBothKeysEqualInCents(): void
    {
        $this->save([
            'price_non_member' => '150.50',
        ]);

        $priceNonMember = (int) $this->field('price_non_member');
        $price          = (int) $this->field('price');

        $this->assertSame(
            15050,
            $priceNonMember,
            'price_non_member=150.50 euros must persist as 15050 cents (euros->cents × 100)',
        );
        // The load-bearing single-price contract: price is SYNCED equal to
        // price_non_member. There is no member/non-member differentiation in v1.
        $this->assertSame(
            $priceNonMember,
            $price,
            'price must be dual-written EQUAL to price_non_member (single-price contract)',
        );
    }

    /**
     * Back-compat path: a caller posts ONLY the legacy `price` key, no
     * price_non_member. Both must still end up EQUAL (dual-write), mirroring the
     * edition `elseif (isset($fields['price']))` branch.
     */
    public function testLegacyPriceKeyAlsoDualWritesBothEqual(): void
    {
        $this->save([
            'price' => '200',
        ]);

        $price          = (int) $this->field('price');
        $priceNonMember = (int) $this->field('price_non_member');

        $this->assertSame(
            20000,
            $price,
            'legacy price=200 euros must persist as 20000 cents',
        );
        $this->assertSame(
            $price,
            $priceNonMember,
            'the legacy price key must dual-write price_non_member EQUAL to price '
            . '(single-price contract, back-compat branch)',
        );
    }

    // ---------------------------------------------------------------
    // 3. CAPACITY absint
    // ---------------------------------------------------------------

    public function testCapacityIsStoredAsInt(): void
    {
        $this->save(['capacity' => '25']);

        $this->assertSame(
            25,
            (int) $this->field('capacity'),
            'capacity=25 must persist as int 25 (absint at line 1115)',
        );
    }

    // ---------------------------------------------------------------
    // 4. LIFECYCLE booleans + isset-guard no-clobber contract
    // ---------------------------------------------------------------

    /**
     * Representative lifecycle booleans (requires_questionnaire,
     * post_requires_evaluation) plus requires_approval: posting a truthy value
     * stores true (handleSave lines 1118-1137).
     */
    public function testLifecycleBooleansPersistTrueWhenPosted(): void
    {
        $this->save([
            'requires_questionnaire'   => '1',
            'post_requires_evaluation' => '1',
            'requires_approval'        => '1',
        ]);

        $this->assertTrue(
            (bool) $this->field('requires_questionnaire'),
            'requires_questionnaire posted truthy must store true (line 1135)',
        );
        $this->assertTrue(
            (bool) $this->field('post_requires_evaluation'),
            'post_requires_evaluation posted truthy must store true (line 1135)',
        );
        $this->assertTrue(
            (bool) $this->field('requires_approval'),
            'requires_approval posted truthy must store true (line 1119)',
        );
    }

    /**
     * The isset-guard "no clobber" contract: a lifecycle boolean that is NOT
     * present in a later save must retain its previously stored value. This is
     * the whole point of the `if (isset($fields[...]))` guards (lines 1118, 1134):
     * a partial save must not silently reset unmentioned flags to false.
     */
    public function testUnpostedLifecycleBooleanIsNotClobbered(): void
    {
        // First save turns requires_approval ON.
        $this->save(['requires_approval' => '1']);
        $this->assertTrue(
            (bool) $this->field('requires_approval'),
            'precondition: requires_approval must be true after the first save',
        );

        // Second save omits requires_approval entirely (touches an unrelated field).
        // The isset-guard must leave the prior true value intact.
        $this->save(['capacity' => '10']);

        $this->assertTrue(
            (bool) $this->field('requires_approval'),
            'requires_approval must survive a later save that omits it — the '
            . 'isset-guard (line 1118) must NOT clobber unmentioned booleans to false',
        );
    }

    // ---------------------------------------------------------------
    // 5. enrollment_form sanitized text
    // ---------------------------------------------------------------

    public function testEnrollmentFormPersistsAsSanitizedText(): void
    {
        $this->save(['enrollment_form' => 'minimal']);

        $this->assertSame(
            'minimal',
            $this->field('enrollment_form'),
            "enrollment_form='minimal' must persist as 'minimal' (sanitize_text_field, line 1124)",
        );
    }

    // ---------------------------------------------------------------
    // 6. deadline_months: absint(...) ?: null, incl. 0 -> null
    // ---------------------------------------------------------------

    public function testDeadlineMonthsPersistsPositiveInt(): void
    {
        $this->save(['deadline_months' => '6']);

        $this->assertSame(
            6,
            (int) $this->field('deadline_months'),
            'deadline_months=6 must persist as int 6 (line 1147)',
        );
    }

    /**
     * deadline_months=0 must persist as "no deadline" (falsy), NOT a positive
     * value. The controller writes null via `absint(...) ?: null` (line 1147),
     * but the CPT declares this field `type => 'int'` (TrajectoryCPT.php:141), so
     * the Data API's typed read coerces the stored null back to (int) 0 on the way
     * OUT (Data.php:1484). The observable round-trip contract is therefore: a
     * posted 0 reads back as falsy 0 (empty()), meaning "no deadline set" — which
     * every consumer treats identically to null (`!empty(...)` at line 808,
     * `(int) ... , 0` default at line 1583).
     *
     * NOTE: this test's original RED assumption was assertNull(); ground-truthing
     * the read path (int schema coercion) showed null is unreachable through
     * getField for an int field. Corrected here to pin the real round-trip
     * contract rather than assert an impossible value.
     */
    public function testDeadlineMonthsZeroPersistsAsFalsyNoDeadline(): void
    {
        // Establish a positive deadline first, so we prove 0 CLEARS it (not just
        // that an unset field defaults to 0).
        $this->save(['deadline_months' => '9']);
        $this->assertSame(9, (int) $this->field('deadline_months'), 'precondition: deadline_months=9');

        $this->save(['deadline_months' => '0']);

        $this->assertEmpty(
            $this->field('deadline_months'),
            'deadline_months=0 must read back falsy ("no deadline") — the `absint(...) ?: null` '
            . 'write (line 1147) stores null, read back as (int) 0 by the int-typed schema '
            . '(Data.php:1484); consumers treat it as "no deadline"',
        );
        $this->assertSame(
            0,
            (int) $this->field('deadline_months'),
            'the concrete read-back value for a cleared deadline is (int) 0',
        );
    }

    // ---------------------------------------------------------------
    // 7. mode + status: valid persists, invalid is rejected
    // ---------------------------------------------------------------

    public function testValidModeAndStatusPersist(): void
    {
        $this->save([
            'mode'   => TrajectoryMode::SelfPaced->value,
            'status' => OfferingStatus::Open->value,
        ]);

        $this->assertSame(
            TrajectoryMode::SelfPaced->value,
            $this->field('mode'),
            'a valid mode (self_paced) must persist (TrajectoryMode::tryFrom guard, line 1085)',
        );
        $this->assertSame(
            OfferingStatus::Open->value,
            $this->field('status'),
            'a valid status (open) must persist (OfferingStatus::tryFrom guard, line 1092)',
        );
    }

    /**
     * An INVALID mode / status must be REJECTED. handleSave only assigns to
     * $updateData when tryFrom() succeeds (lines 1085, 1092); an invalid value
     * never enters the update, so a prior valid value is left untouched.
     */
    public function testInvalidModeAndStatusAreRejected(): void
    {
        // Establish a known-good baseline.
        $this->save([
            'mode'   => TrajectoryMode::Cohort->value,
            'status' => OfferingStatus::Draft->value,
        ]);
        $this->assertSame(TrajectoryMode::Cohort->value, $this->field('mode'), 'baseline mode');
        $this->assertSame(OfferingStatus::Draft->value, $this->field('status'), 'baseline status');

        // Now post garbage for both.
        $this->save([
            'mode'   => 'definitely_not_a_mode',
            'status' => 'definitely_not_a_status',
        ]);

        $this->assertSame(
            TrajectoryMode::Cohort->value,
            $this->field('mode'),
            'an invalid mode must be rejected (tryFrom fails, line 1085) — prior value untouched, '
            . 'garbage never stored',
        );
        $this->assertSame(
            OfferingStatus::Draft->value,
            $this->field('status'),
            'an invalid status must be rejected (tryFrom fails, line 1092) — prior value untouched, '
            . 'garbage never stored',
        );
    }

    // ---------------------------------------------------------------
    // T-3 (FIXED) — per-enrollment progress reflects real course completion
    // ---------------------------------------------------------------

    /**
     * T-3 fix: renderEnrollmentsMetabox() now derives the per-enrollment
     * completed-course count from the single trajectory-progress convergence
     * point (TrajectoryDashboardService::getProgressData → completed_count),
     * the same source the front-end and React admin grid use — instead of the
     * old hardcoded `$completedCourses = 0;`.
     *
     * With 2 required courses and the LMS adapter reporting exactly ONE of them
     * complete for the enrolled user, the progress text must read "1/2" (not
     * the old "0/2"). The LMS adapter is stubbed so completion is deterministic
     * and does not depend on driving real LearnDash activity records.
     */
    public function testEnrollmentsMetaboxProgressReflectsRealCompletedCount(): void
    {
        // A trajectory with 2 required courses so N = 2 in the "M/N" text.
        $courseA = wp_insert_post([
            'post_type'   => 'sfwd-courses',
            'post_title'  => 'Traj Course A ' . uniqid(),
            'post_status' => 'publish',
        ]);
        $courseB = wp_insert_post([
            'post_type'   => 'sfwd-courses',
            'post_title'  => 'Traj Course B ' . uniqid(),
            'post_status' => 'publish',
        ]);
        self::$testPosts[] = $courseA;
        self::$testPosts[] = $courseB;

        $this->repo->update($this->trajectoryId, [
            'courses' => [
                ['type' => 'online', 'course_id' => $courseA, 'required' => true],
                ['type' => 'online', 'course_id' => $courseB, 'required' => true],
            ],
        ]);

        // Enroll the (administrator) fixture user in this trajectory.
        $regRepo = ntdst_get(RegistrationRepository::class);
        $regId = $regRepo->create([
            'user_id'         => (int) self::$testUserId,
            'trajectory_id'   => $this->trajectoryId,
            'edition_id'      => null,
            'status'          => 'completed',
            'enrollment_path' => RegistrationRepository::PATH_TRAJECTORY,
        ]);
        $this->assertIsInt($regId, 'trajectory registration must be created for the fixture');
        $this->createdRegistrationIds[] = $regId;

        // Stub the LMS adapter so exactly courseA is complete for this user —
        // deterministic, no dependency on real LearnDash activity records.
        $userId = (int) self::$testUserId;
        $stubAdapter = new class ($userId, $courseA) implements LMSAdapterInterface {
            public function __construct(private int $userId, private int $completeCourseId)
            {
            }
            public function grantAccess(int $userId, int $courseId): bool
            {
                return true;
            }
            public function revokeAccess(int $userId, int $courseId): bool
            {
                return true;
            }
            public function isComplete(int $userId, int $courseId): bool
            {
                return $userId === $this->userId && $courseId === $this->completeCourseId;
            }
            public function markComplete(int $userId, int $courseId): bool
            {
                return true;
            }
            public function isOpenCourse(int $courseId): bool
            {
                return false;
            }
        };

        // The metabox resolves TrajectoryDashboardService from the container, and
        // that service holds its LMS adapter as a constructor dependency captured
        // at build time — so rebinding only the interface would not reach an
        // already-instantiated service. Rebind the dashboard service itself to a
        // fresh instance wired with the stub adapter, and restore afterwards.
        $originalDashboard = ntdst_get(\Stride\Modules\Trajectory\TrajectoryDashboardService::class);
        ntdst_set(
            \Stride\Modules\Trajectory\TrajectoryDashboardService::class,
            fn() => new \Stride\Modules\Trajectory\TrajectoryDashboardService(
                ntdst_get(TrajectoryRepository::class),
                ntdst_get(TrajectoryService::class),
                ntdst_get(RegistrationRepository::class),
                ntdst_get(\Stride\Modules\Edition\EditionService::class),
                $stubAdapter,
            ),
        );

        try {
            // Render the metabox and capture its HTML.
            ob_start();
            $this->controller()->renderEnrollmentsMetabox(get_post($this->trajectoryId));
            $html = (string) ob_get_clean();
        } finally {
            // Always restore the real service so no other test sees the stub.
            ntdst_set(
                \Stride\Modules\Trajectory\TrajectoryDashboardService::class,
                fn() => $originalDashboard,
            );
        }

        // One of two required courses complete → progress text reads "1/2".
        $this->assertStringContainsString(
            '1/2',
            $html,
            'Progress text must reflect the real completed-course count from '
            . 'getProgressData() (1 of 2 complete), not the old hardcoded 0.'
            . 'This pins current behavior pending a product decision — NOT desired behavior.',
        );
    }
}
