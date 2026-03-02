<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\NetdustLTI;

use IntegrationTestCase;
use NetdustLTI\ToolProvider\Domain\GradePayload;
use NetdustLTI\ToolProvider\Services\GradePassbackService;
use WP_Error;

/**
 * Integration tests for GradePassbackService.
 *
 * Tests filter hooks, grade payload construction, and user meta handling
 * with real WordPress context.
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Integration --filter GradePassbackIntegration
 */
class GradePassbackIntegrationTest extends IntegrationTestCase
{
    private GradePassbackService $service;

    /** @var list<string> User meta keys to clean up after each test */
    private array $userMetaKeysToClean = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GradePassbackService();
        $this->userMetaKeysToClean = [];
    }

    protected function tearDown(): void
    {
        // Remove any filter hooks added during tests
        remove_all_filters('netdust_lti_grade_payload');
        remove_all_filters('netdust_lti_should_post_grade');

        // Clean up any user meta written during tests
        if (self::$testUserId && !empty($this->userMetaKeysToClean)) {
            $this->cleanupUserMeta(self::$testUserId, $this->userMetaKeysToClean);
        }

        parent::tearDown();
    }

    // =========================================================================
    // GradePayload Factory Methods
    // =========================================================================

    /** @test */
    public function completionPayloadHasCorrectDefaults(): void
    {
        $payload = GradePayload::completion(42, 100);

        $this->assertEquals(42, $payload->userId);
        $this->assertEquals(100, $payload->courseId);
        $this->assertEquals(1.0, $payload->score);
        $this->assertEquals(1.0, $payload->maxScore);
        $this->assertEquals('Completed', $payload->activityProgress);
        $this->assertEquals('FullyGraded', $payload->gradingProgress);
        $this->assertNull($payload->comment);
    }

    /** @test */
    public function quizScorePayloadCalculatesCorrectly(): void
    {
        $payload = GradePayload::quizScore(42, 100, 85.0, 100.0);

        $this->assertEquals(42, $payload->userId);
        $this->assertEquals(100, $payload->courseId);
        $this->assertEquals(85.0, $payload->score);
        $this->assertEquals(100.0, $payload->maxScore);
        $this->assertEquals('Completed', $payload->activityProgress);
        $this->assertEquals('FullyGraded', $payload->gradingProgress);
    }

    /** @test */
    public function tincannyScorePayloadUsesPercentageAndMaxHundred(): void
    {
        $payload = GradePayload::tincannyScore(42, 100, 75.0);

        // tincannyScore stores raw percentage as score with maxScore of 100
        $this->assertEquals(75.0, $payload->score);
        $this->assertEquals(100.0, $payload->maxScore);
        $this->assertEquals('Completed', $payload->activityProgress);
        $this->assertEquals('FullyGraded', $payload->gradingProgress);
    }

    /** @test */
    public function gradePayloadConstructorAcceptsComment(): void
    {
        $payload = new GradePayload(
            userId: 1,
            courseId: 2,
            score: 0.9,
            maxScore: 1.0,
            activityProgress: 'Completed',
            gradingProgress: 'FullyGraded',
            comment: 'Well done!',
        );

        $this->assertEquals('Well done!', $payload->comment);
    }

    // =========================================================================
    // Filter: netdust_lti_grade_payload
    // =========================================================================

    /** @test */
    public function gradePayloadFilterModifiesPayload(): void
    {
        $originalPayload = GradePayload::completion(1, 100);

        add_filter('netdust_lti_grade_payload', function (GradePayload $payload): GradePayload {
            return new GradePayload(
                userId: $payload->userId,
                courseId: $payload->courseId,
                score: 50.0,
                maxScore: $payload->maxScore,
                activityProgress: $payload->activityProgress,
                gradingProgress: $payload->gradingProgress,
                comment: 'Modified by filter',
            );
        });

        // Simulate what postGrade() does internally
        $filtered = apply_filters('netdust_lti_grade_payload', $originalPayload);

        $this->assertEquals(50.0, $filtered->score);
        $this->assertEquals('Modified by filter', $filtered->comment);
        // Original fields preserved
        $this->assertEquals(1, $filtered->userId);
        $this->assertEquals(100, $filtered->courseId);
        $this->assertEquals(1.0, $filtered->maxScore);
    }

    /** @test */
    public function gradePayloadFilterReceivesCorrectType(): void
    {
        $receivedType = null;

        add_filter('netdust_lti_grade_payload', function ($payload) use (&$receivedType) {
            $receivedType = get_class($payload);
            return $payload;
        });

        apply_filters('netdust_lti_grade_payload', GradePayload::completion(1, 100));

        $this->assertEquals(GradePayload::class, $receivedType);
    }

    // =========================================================================
    // Filter: netdust_lti_should_post_grade
    // =========================================================================

    /** @test */
    public function shouldPostGradeFilterCanSuppressGrading(): void
    {
        add_filter('netdust_lti_should_post_grade', '__return_false');

        $should = apply_filters('netdust_lti_should_post_grade', true, GradePayload::completion(1, 100));

        $this->assertFalse($should);
    }

    /** @test */
    public function shouldPostGradeFilterDefaultsToTrue(): void
    {
        // No filter attached — default should be true
        $should = apply_filters('netdust_lti_should_post_grade', true, GradePayload::completion(1, 100));

        $this->assertTrue($should);
    }

    /** @test */
    public function shouldPostGradeFilterReceivesPayloadAsSecondArg(): void
    {
        $capturedPayload = null;

        add_filter('netdust_lti_should_post_grade', function (bool $should, GradePayload $payload) use (&$capturedPayload): bool {
            $capturedPayload = $payload;
            return $should;
        }, 10, 2);

        $original = GradePayload::completion(99, 200);
        apply_filters('netdust_lti_should_post_grade', true, $original);

        $this->assertNotNull($capturedPayload);
        $this->assertEquals(99, $capturedPayload->userId);
        $this->assertEquals(200, $capturedPayload->courseId);
    }

    // =========================================================================
    // postGrade() Integration — suppressed via filter
    // =========================================================================

    /** @test */
    public function postGradeReturnsTrueWhenSuppressedByFilter(): void
    {
        add_filter('netdust_lti_should_post_grade', '__return_false');

        $payload = GradePayload::completion(self::$testUserId, 999);
        $result = $this->service->postGrade($payload);

        // When suppressed, postGrade returns true (not an error)
        $this->assertTrue($result);
    }

    // =========================================================================
    // postGrade() Integration — no LTI context
    // =========================================================================

    /** @test */
    public function postGradeReturnsErrorWhenNoLtiContext(): void
    {
        $courseId = 999;
        $metaKey = '_netdust_lti_context_' . $courseId;

        // Ensure no context exists
        delete_user_meta(self::$testUserId, $metaKey);

        $payload = GradePayload::completion(self::$testUserId, $courseId);
        $result = $this->service->postGrade($payload);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('no_context', $result->get_error_code());
    }

    /** @test */
    public function postGradeReturnsErrorWhenContextMissingAgsEndpoint(): void
    {
        $courseId = 888;
        $metaKey = '_netdust_lti_context_' . $courseId;
        $this->userMetaKeysToClean[] = $metaKey;

        // Store context without AGS endpoints
        update_user_meta(self::$testUserId, $metaKey, [
            'platform_id' => 1,
            'lti_user_id' => 'lti-user-abc',
            // No line_item_url or scores_url
        ]);

        $payload = GradePayload::completion(self::$testUserId, $courseId);
        $result = $this->service->postGrade($payload);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('no_ags', $result->get_error_code());
    }

    // =========================================================================
    // AGS Context User Meta
    // =========================================================================

    /** @test */
    public function agsContextStoredInUserMeta(): void
    {
        $userId = self::$testUserId;
        $courseId = 777;
        $metaKey = '_netdust_lti_context_' . $courseId;
        $this->userMetaKeysToClean[] = $metaKey;

        $contextData = [
            'platform_id' => 42,
            'lti_user_id' => 'lti-user-xyz',
            'line_item_url' => 'https://mock-lms.test/ags/lineitems/123/lineitem',
            'scores_url' => 'https://mock-lms.test/ags/lineitems/123/scores',
        ];

        update_user_meta($userId, $metaKey, $contextData);

        $retrieved = get_user_meta($userId, $metaKey, true);

        $this->assertIsArray($retrieved);
        $this->assertEquals(42, $retrieved['platform_id']);
        $this->assertEquals('lti-user-xyz', $retrieved['lti_user_id']);
        $this->assertEquals('https://mock-lms.test/ags/lineitems/123/lineitem', $retrieved['line_item_url']);
        $this->assertEquals('https://mock-lms.test/ags/lineitems/123/scores', $retrieved['scores_url']);
    }

    /** @test */
    public function agsContextSupportsMultipleCoursesPerUser(): void
    {
        $userId = self::$testUserId;
        $courseId1 = 555;
        $courseId2 = 666;
        $metaKey1 = '_netdust_lti_context_' . $courseId1;
        $metaKey2 = '_netdust_lti_context_' . $courseId2;
        $this->userMetaKeysToClean[] = $metaKey1;
        $this->userMetaKeysToClean[] = $metaKey2;

        update_user_meta($userId, $metaKey1, [
            'platform_id' => 1,
            'lti_user_id' => 'user-1',
            'scores_url' => 'https://platform-a.test/scores/1',
        ]);

        update_user_meta($userId, $metaKey2, [
            'platform_id' => 2,
            'lti_user_id' => 'user-2',
            'scores_url' => 'https://platform-b.test/scores/2',
        ]);

        $context1 = get_user_meta($userId, $metaKey1, true);
        $context2 = get_user_meta($userId, $metaKey2, true);

        // Each course has its own independent context
        $this->assertEquals(1, $context1['platform_id']);
        $this->assertEquals(2, $context2['platform_id']);
        $this->assertNotEquals($context1['scores_url'], $context2['scores_url']);
    }

    /** @test */
    public function payloadFilterCanModifyBeforeContextLookup(): void
    {
        $courseId = 444;
        $metaKey = '_netdust_lti_context_' . $courseId;
        $this->userMetaKeysToClean[] = $metaKey;

        // Store context for the MODIFIED course ID
        update_user_meta(self::$testUserId, $metaKey, [
            'platform_id' => 10,
            'lti_user_id' => 'remap-user',
            'scores_url' => 'https://platform.test/scores/444',
        ]);

        // Filter that redirects to a different course
        add_filter('netdust_lti_grade_payload', function (GradePayload $payload): GradePayload {
            return new GradePayload(
                userId: $payload->userId,
                courseId: 444, // Redirect to course 444
                score: $payload->score,
                maxScore: $payload->maxScore,
                activityProgress: $payload->activityProgress,
                gradingProgress: $payload->gradingProgress,
            );
        });

        // Create payload for course 333 (no context) — filter will redirect to 444
        $payload = GradePayload::completion(self::$testUserId, 333);

        // postGrade will apply the filter first, then look up context for course 444
        // It will still fail at the platform lookup step, but NOT with 'no_context'
        // because the context for course 444 exists
        $result = $this->service->postGrade($payload);

        // Should get past the no_context check (since filter remapped to 444)
        // but fail at platform lookup (since platform_id 10 doesn't exist)
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertNotEquals('no_context', $result->get_error_code());
        $this->assertNotEquals('no_ags', $result->get_error_code());
    }
}
