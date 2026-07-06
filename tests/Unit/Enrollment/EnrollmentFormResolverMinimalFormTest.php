<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Enrollment;

use Mockery;
use Mockery\MockInterface;
use Stride\Domain\OfferingStatus;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\EnrollmentFormResolver;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryService;
use Stride\Modules\User\ProfileTypePolicy;
use Stride\Tests\TestCase;
use WP_Post;

/**
 * T7 (plan 2026-07-05-profiletype-visibility-filter.md, §4 M2 / §6.3).
 *
 * RED-FIRST CONTRACT — authored by the test-author, IMMUTABLE to the implementer.
 * Green it without weakening; if it is wrong, escalate NEEDS_CONTEXT — do not edit.
 *
 * ── The M2 contract this asserts ──────────────────────────────────────────────
 * The enrollment `form_type` is SERVER-decided from the logged-in user's stored
 * profile type, NOT client-selectable. `EnrollmentFormResolver` must consult
 * `ProfileTypePolicy::usesMinimalForm($userId, $enrollableId, $postType)` and:
 *   - policy minimal:true  → force form_type = 'minimal', REGARDLESS of the
 *     enrollable's STORED form (here 'default') or any would-be client input;
 *   - policy minimal:false/absent → the STORED form_type stands ('default').
 * Both resolve paths (edition + trajectory) carry the same rule (§6.3).
 *
 * Boundary note (threat model M2, "form integrity"): the resolver's PUBLIC entry
 * `resolveTemplateArgs(WP_Post $item, string $itemType)` receives NO request /
 * client `form_type` parameter at all. There is therefore no channel through
 * which a client-supplied form_type can reach the decision — the value comes
 * ONLY from getEnrollmentForm() (stored) + the policy. testClientFormTypeHasNoChannel
 * asserts THAT structural guarantee (the M2 shape documented in the T7 brief:
 * "if the resolver genuinely reads no request form_type, assert THAT").
 *
 * Block-precedence (T4): usesMinimalForm is independent of blocksEnrollment. A
 * blocked type is stopped at the enroll seam (T4), not here. These tests use a
 * NON-blocked type (block:false, minimal:true) so the two concepts don't conflate.
 *
 * RED reason (methods exist, no signature shell): the resolver does not consult
 * ProfileTypePolicy yet, so form_type stays 'default' even when the policy says
 * minimal → the primary assertions fail behaviorally ('minimal' expected, got
 * 'default'), not with "module not found".
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Unit --filter EnrollmentFormResolverMinimalForm
 */
final class EnrollmentFormResolverMinimalFormTest extends TestCase
{
    private const USER_ID = 55;
    private const EDITION_ID = 700;
    private const TRAJECTORY_ID = 800;

    private EnrollmentService|MockInterface $enrollmentService;
    private RegistrationRepository|MockInterface $registrations;
    private EditionService|MockInterface $editionService;
    private TrajectoryService|MockInterface $trajectoryService;
    private ProfileTypePolicy|MockInterface $policy;
    private EnrollmentFormResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        global $_test_current_user_id;
        $_test_current_user_id = self::USER_ID;

        $this->enrollmentService = Mockery::mock(EnrollmentService::class);
        // No active registration → resolve proceeds to the open-enrollment branch.
        $this->enrollmentService->shouldReceive('hasActiveRegistration')->andReturn(false)->byDefault();

        $this->registrations = Mockery::mock(RegistrationRepository::class);

        // EditionService: an OPEN edition with capacity → mode 'enrollment', state
        // 'render'. The STORED enrollment form is 'default' — the ONLY thing that
        // could turn form_type into 'minimal' is the policy.
        $this->editionService = Mockery::mock(EditionService::class);
        $this->editionService->shouldReceive('getEffectiveStatus')->andReturn(OfferingStatus::Open)->byDefault();
        $this->editionService->shouldReceive('requiresApproval')->andReturn(false)->byDefault();
        $this->editionService->shouldReceive('hasAvailableSpots')->andReturn(true)->byDefault();
        $this->editionService->shouldReceive('isOnline')->andReturn(false)->byDefault();
        $this->editionService->shouldReceive('getEnrollmentForm')->andReturn('default')->byDefault();

        // TrajectoryService: an OPEN trajectory → mode 'enrollment'. Stored form 'default'.
        $this->trajectoryService = Mockery::mock(TrajectoryService::class);
        $this->trajectoryService->shouldReceive('getTrajectory')
            ->andReturn(['status_enum' => OfferingStatus::Open])->byDefault();
        $this->trajectoryService->shouldReceive('requiresApproval')->andReturn(false)->byDefault();
        $this->trajectoryService->shouldReceive('isEnrollmentOpen')->andReturn(true)->byDefault();
        $this->trajectoryService->shouldReceive('getEnrollmentForm')->andReturn('default')->byDefault();

        $this->policy = Mockery::mock(ProfileTypePolicy::class);

        // The resolver reads these three collaborators via ntdst_get().
        ntdst_set(EditionService::class, $this->editionService);
        ntdst_set(TrajectoryService::class, $this->trajectoryService);
        ntdst_set(ProfileTypePolicy::class, $this->policy);

        $this->resolver = new EnrollmentFormResolver(
            $this->enrollmentService,
            $this->registrations,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function editionPost(): WP_Post
    {
        return new WP_Post(['ID' => self::EDITION_ID, 'post_type' => 'vad_edition', 'post_title' => 'Edition']);
    }

    private function trajectoryPost(): WP_Post
    {
        return new WP_Post(['ID' => self::TRAJECTORY_ID, 'post_type' => 'vad_trajectory', 'post_title' => 'Trajectory']);
    }

    // ── 1. Edition: policy minimal:true forces 'minimal' over the stored 'default' ──
    public function testEditionMinimalPolicyForcesMinimalFormOverStoredDefault(): void
    {
        // Non-blocked type whose rule says minimal. Post type is the CPT slug 'vad_edition'.
        $this->policy->shouldReceive('usesMinimalForm')
            ->with(self::USER_ID, self::EDITION_ID, 'vad_edition')
            ->andReturn(true);

        $args = $this->resolver->resolveTemplateArgs($this->editionPost(), 'edition');

        // Stored form is 'default'; policy says minimal → server MUST force 'minimal'.
        $this->assertSame(
            'minimal',
            $args['form_type'],
            'Policy minimal:true must force form_type to "minimal" on the edition path, overriding the stored "default".'
        );
    }

    // ── 2. Edition negative: policy minimal:false → stored 'default' stands ──
    public function testEditionNonMinimalPolicyLeavesStoredFormType(): void
    {
        $this->policy->shouldReceive('usesMinimalForm')
            ->with(self::USER_ID, self::EDITION_ID, 'vad_edition')
            ->andReturn(false);

        $args = $this->resolver->resolveTemplateArgs($this->editionPost(), 'edition');

        $this->assertSame(
            'default',
            $args['form_type'],
            'Policy minimal:false must NOT force minimal — the stored form_type ("default") stands.'
        );
    }

    // ── 3a. Trajectory parity: policy minimal:true forces 'minimal' ──
    public function testTrajectoryMinimalPolicyForcesMinimalFormOverStoredDefault(): void
    {
        $this->policy->shouldReceive('usesMinimalForm')
            ->with(self::USER_ID, self::TRAJECTORY_ID, 'vad_trajectory')
            ->andReturn(true);

        $args = $this->resolver->resolveTemplateArgs($this->trajectoryPost(), 'trajectory');

        $this->assertSame(
            'minimal',
            $args['form_type'],
            'Policy minimal:true must force form_type to "minimal" on the trajectory path too (parity).'
        );
    }

    // ── 3b. Trajectory negative: policy minimal:false → stored 'default' stands ──
    public function testTrajectoryNonMinimalPolicyLeavesStoredFormType(): void
    {
        $this->policy->shouldReceive('usesMinimalForm')
            ->with(self::USER_ID, self::TRAJECTORY_ID, 'vad_trajectory')
            ->andReturn(false);

        $args = $this->resolver->resolveTemplateArgs($this->trajectoryPost(), 'trajectory');

        $this->assertSame(
            'default',
            $args['form_type'],
            'Trajectory: policy minimal:false must leave the stored form_type ("default") in place.'
        );
    }

    // ── 4. Client-not-trusted (M2 boundary): decision is purely stored-form + policy ──
    // The resolver's public entry takes only (WP_Post, string $itemType). There is NO
    // request/client form_type parameter — so a client cannot select 'minimal'. When the
    // policy says NOT minimal, the result is exactly the stored value, no matter what a
    // client "wanted". This is the structural M2 guarantee (form_type is server-derived).
    public function testClientFormTypeHasNoChannelWhenPolicyNotMinimal(): void
    {
        // Policy is NOT minimal for this user.
        $this->policy->shouldReceive('usesMinimalForm')->andReturn(false);
        // Stored form is 'default' (set in setUp).

        // Simulate a client that "wants" minimal by polluting the request superglobal —
        // the only channel a tamperer has. The resolver must ignore it entirely.
        $_POST['form_type'] = 'minimal';
        $_GET['form_type'] = 'minimal';
        $_REQUEST['form_type'] = 'minimal';

        try {
            $args = $this->resolver->resolveTemplateArgs($this->editionPost(), 'edition');
        } finally {
            unset($_POST['form_type'], $_GET['form_type'], $_REQUEST['form_type']);
        }

        // Client-supplied 'minimal' had NO effect: form_type is the stored value + policy
        // (policy not-minimal → stored 'default'). If this ever became 'minimal', a client
        // channel would have leaked into the decision.
        $this->assertSame(
            'default',
            $args['form_type'],
            'A client-supplied form_type must have NO channel into the decision — value is stored-form + policy only.'
        );
    }
}
