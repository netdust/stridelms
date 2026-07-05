<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
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
 *  - PRICE euros->cents dual STORE, as two INDEPENDENT keys (member vs
 *    non-member) — NOT the edition-style dual-write-to-same-key (lines 1139-1144).
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
    // 1 + 2. PRICE: euros->cents, member/non-member independent keys
    // ---------------------------------------------------------------

    /**
     * price=150.50 euros must STORE 15050 cents; price_non_member=200 euros must
     * STORE 20000 cents (handleSave lines 1139-1144: (int) round(x * 100)).
     */
    public function testPriceIsStoredAsCents(): void
    {
        $this->save([
            'price'            => '150.50',
            'price_non_member' => '200',
        ]);

        $this->assertSame(
            15050,
            (int) $this->field('price'),
            'price=150.50 euros must persist as 15050 cents (euros->cents at line 1140)',
        );
        $this->assertSame(
            20000,
            (int) $this->field('price_non_member'),
            'price_non_member=200 euros must persist as 20000 cents (line 1143)',
        );
    }

    /**
     * price and price_non_member are TWO INDEPENDENT fields. Unlike editions
     * (which dual-write price == price_non_member), the trajectory save stores
     * them under distinct keys. Setting DIFFERENT values must persist BOTH
     * independently — neither clobbers the other, no dual-write-to-same-key.
     */
    public function testMemberAndNonMemberPricesPersistIndependently(): void
    {
        $this->save([
            'price'            => '100',
            'price_non_member' => '175.25',
        ]);

        $this->assertSame(
            10000,
            (int) $this->field('price'),
            'member price must persist independently as 10000 cents',
        );
        $this->assertSame(
            17525,
            (int) $this->field('price_non_member'),
            'non-member price must persist independently as 17525 cents — '
            . 'trajectory does NOT dual-write price == price_non_member the way editions do',
        );
        $this->assertNotSame(
            (int) $this->field('price'),
            (int) $this->field('price_non_member'),
            'the two prices are independent keys — different inputs must stay different',
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
    // T-3 characterization — KNOWN LIMITATION, not desired behavior
    // ---------------------------------------------------------------

    /**
     * KNOWN LIMITATION (T-3): renderEnrollmentsMetabox() hardcodes
     * `$completedCourses = 0;` (TrajectoryAdminController.php:686), so the
     * per-enrollment progress bar ALWAYS renders "0/N" regardless of how many
     * courses the user has actually completed.
     *
     * This test PINS that current cosmetic behavior; it does NOT assert desired
     * behavior. It exists so that if/when a human decides to compute real
     * progress, this characterization goes RED and flags the intentional change.
     * Do NOT "fix" the product to make this pass differently — the fix is a
     * separate product decision.
     */
    public function testEnrollmentsMetaboxProgressIsCurrentlyHardcodedZeroKnownLimitation(): void
    {
        // A trajectory with 2 courses so N = 2 in the "0/N" text.
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

        // Enroll the (administrator) fixture user in this trajectory and mark the
        // registration completed — so if progress were computed, it would NOT be 0.
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

        // Render the metabox and capture its HTML.
        ob_start();
        $this->controller()->renderEnrollmentsMetabox(get_post($this->trajectoryId));
        $html = (string) ob_get_clean();

        // Despite a COMPLETED enrollment and 2 courses, progress text is "0/2"
        // because completedCourses is hardcoded 0 (line 686). This pins the
        // known cosmetic limitation.
        $this->assertStringContainsString(
            '0/2',
            $html,
            'KNOWN LIMITATION: progress text is hardcoded "0/N" (completedCourses = 0 at '
            . 'TrajectoryAdminController.php:686), even for a completed enrollment. '
            . 'This pins current behavior pending a product decision — NOT desired behavior.',
        );
    }
}
