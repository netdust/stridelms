<?php

namespace Stride\Tests\Integration;

use Stride\Tests\TestCase;
use Stride\Tests\Mocks\MockFluentCRMAdapter;
use Stride\Tests\Mocks\MockLearnDashAdapter;
use Stride\Tests\Mocks\MockStorageBackend;
use stride\services\core\CourseService;
use stride\services\core\SubscriberService;
use stride\services\enrollment\EnrollmentService;
use stride\services\sync\UserDataSync;

/**
 * Integration Test: Quote Service Integration
 *
 * Verifies that the quote service properly integrates with
 * the enrollment workflow via the stride/enrollment/completed hook.
 *
 * Note: These tests verify the hook infrastructure is working.
 * Full QuoteService tests would require additional mocking of
 * the DataManager and CPT registration.
 */
class QuoteServiceIntegrationTest extends TestCase
{
    private MockFluentCRMAdapter $fluentCRM;
    private MockLearnDashAdapter $learnDash;
    private MockStorageBackend $storageBackend;
    private UserDataSync $userDataSync;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fluentCRM = new MockFluentCRMAdapter();
        $this->learnDash = new MockLearnDashAdapter();
        $this->storageBackend = new MockStorageBackend('test', 100);
        $this->userDataSync = new UserDataSync([$this->storageBackend]);
    }

    /**
     * Test: Enrollment completion hook passes correct data for quote generation
     */
    public function testEnrollmentHookProvidesQuoteData(): void
    {
        // Arrange
        $user = $this->createUser(['ID' => 100, 'user_email' => 'quote@example.com']);

        $this->learnDash->seedCourse([
            'ID' => 1000,
            'post_title' => 'Premium Course',
            'settings' => [
                'course_price' => 750.00,
                'course_price_type' => 'paynow',
            ],
        ]);

        $this->fluentCRM->seedSubscriber([
            'user_id' => 100,
            'email' => 'quote@example.com',
            'first_name' => 'Quote',
            'last_name' => 'Test',
            'custom_fields' => [
                'subscriber_invoice_org_name' => 'Test Corp',
                'subscriber_invoice_address' => '789 Quote St',
            ],
        ]);

        // Capture hook data
        $capturedData = null;
        add_action('stride/enrollment/completed', function ($userId, $courseId, $data) use (&$capturedData) {
            $capturedData = [
                'userId' => $userId,
                'courseId' => $courseId,
                'data' => $data,
            ];
        }, 10, 3);

        $courseService = new CourseService($this->learnDash);
        $subscriberService = new SubscriberService($this->fluentCRM, $this->userDataSync);
        $enrollmentService = new EnrollmentService($courseService, $subscriberService, $this->userDataSync);

        // Act
        $enrollmentService->enrollUser(100, 1000, [
            'first_name' => 'Quote',
            'last_name' => 'Test',
            'invoice_org_name' => 'Test Corp',
            'invoice_address' => '789 Quote St',
            'invoice_city' => 'Ghent',
            'invoice_postal_code' => '9000',
            'invoice_vat' => 'BE0111222333',
            'enrollment_path' => 'individual',
        ]);

        // Assert: Hook received complete data
        $this->assertNotNull($capturedData, 'Hook should have captured data');
        $this->assertEquals(100, $capturedData['userId']);
        $this->assertEquals(1000, $capturedData['courseId']);

        // Assert: Invoice data is present for quote generation
        $this->assertEquals('Test Corp', $capturedData['data']['invoice_org_name']);
        $this->assertEquals('789 Quote St', $capturedData['data']['invoice_address']);
        $this->assertEquals('BE0111222333', $capturedData['data']['invoice_vat']);
        $this->assertEquals('individual', $capturedData['data']['enrollment_path']);
    }

    /**
     * Test: Multiple enrollments fire multiple hooks
     */
    public function testMultipleEnrollmentsFireMultipleHooks(): void
    {
        // Arrange
        $user = $this->createUser(['ID' => 101]);

        $this->learnDash->seedCourse(['ID' => 1001, 'post_title' => 'Course 1']);
        $this->learnDash->seedCourse(['ID' => 1002, 'post_title' => 'Course 2']);
        $this->fluentCRM->seedSubscriber(['user_id' => 101, 'email' => 'multi@example.com']);

        $hookCalls = [];
        add_action('stride/enrollment/completed', function ($userId, $courseId) use (&$hookCalls) {
            $hookCalls[] = ['userId' => $userId, 'courseId' => $courseId];
        }, 10, 3);

        $courseService = new CourseService($this->learnDash);
        $subscriberService = new SubscriberService($this->fluentCRM, $this->userDataSync);
        $enrollmentService = new EnrollmentService($courseService, $subscriberService, $this->userDataSync);

        // Act: Enroll in multiple courses
        $enrollmentService->enrollUser(101, 1001);
        $enrollmentService->enrollUser(101, 1002);

        // Assert: Two hooks fired
        $this->assertCount(2, $hookCalls);
        $this->assertEquals(1001, $hookCalls[0]['courseId']);
        $this->assertEquals(1002, $hookCalls[1]['courseId']);
    }

    /**
     * Test: Hook can be filtered to abort enrollment
     */
    public function testBeforeEnrollFilterCanAbort(): void
    {
        // Arrange
        $user = $this->createUser(['ID' => 102]);

        $this->learnDash->seedCourse(['ID' => 1003]);
        $this->fluentCRM->seedSubscriber(['user_id' => 102, 'email' => 'abort@example.com']);

        // Add filter that returns WP_Error
        add_filter('stride/enrollment/before_enroll', function ($data, $userId, $courseId) {
            return new \WP_Error('enrollment_blocked', 'Enrollment blocked by filter');
        }, 10, 3);

        $courseService = new CourseService($this->learnDash);
        $subscriberService = new SubscriberService($this->fluentCRM, $this->userDataSync);
        $enrollmentService = new EnrollmentService($courseService, $subscriberService, $this->userDataSync);

        // Act
        $result = $enrollmentService->enrollUser(102, 1003);

        // Assert: Enrollment was blocked
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('enrollment_blocked', $result->get_error_code());

        // Assert: User not enrolled
        $this->assertFalse($this->learnDash->isEnrolled(102, 1003));

        // Assert: Completion hook not fired
        $this->assertActionNotFired('stride/enrollment/completed');
    }

    /**
     * Test: Quote-relevant fields are properly passed through enrollment
     */
    public function testQuoteRelevantFieldsPassedCorrectly(): void
    {
        // Arrange
        $user = $this->createUser(['ID' => 103]);

        $this->learnDash->seedCourse(['ID' => 1004, 'settings' => ['course_price' => 1200.00]]);
        $this->fluentCRM->seedSubscriber(['user_id' => 103, 'email' => 'fields@example.com']);

        $capturedData = null;
        add_action('stride/enrollment/completed', function ($userId, $courseId, $data) use (&$capturedData) {
            $capturedData = $data;
        }, 10, 3);

        $courseService = new CourseService($this->learnDash);
        $subscriberService = new SubscriberService($this->fluentCRM, $this->userDataSync);
        $enrollmentService = new EnrollmentService($courseService, $subscriberService, $this->userDataSync);

        // Act: Enroll with all quote-relevant fields
        $enrollmentService->enrollUser(103, 1004, [
            'first_name' => 'Invoice',
            'last_name' => 'User',
            'invoice_org_name' => 'Invoice Corp NV',
            'invoice_address' => '100 Invoice Street',
            'invoice_city' => 'Leuven',
            'invoice_postal_code' => '3000',
            'invoice_vat' => 'BE0444555666',
            'invoice_gln' => '5412345678901',
            'invoice_email' => 'accounts@invoicecorp.be',
            'company_id' => 999,
            'enrollment_path' => 'colleague',
            'enrolled_by_user_id' => 88,
        ]);

        // Assert: All fields present in hook data
        $this->assertNotNull($capturedData);

        // Personal data
        $this->assertEquals('Invoice', $capturedData['first_name']);
        $this->assertEquals('User', $capturedData['last_name']);

        // Invoice data
        $this->assertEquals('Invoice Corp NV', $capturedData['invoice_org_name']);
        $this->assertEquals('100 Invoice Street', $capturedData['invoice_address']);
        $this->assertEquals('Leuven', $capturedData['invoice_city']);
        $this->assertEquals('3000', $capturedData['invoice_postal_code']);
        $this->assertEquals('BE0444555666', $capturedData['invoice_vat']);
        $this->assertEquals('5412345678901', $capturedData['invoice_gln']);
        $this->assertEquals('accounts@invoicecorp.be', $capturedData['invoice_email']);

        // Organization
        $this->assertEquals(999, $capturedData['company_id']);

        // Tracking
        $this->assertEquals('colleague', $capturedData['enrollment_path']);
        $this->assertEquals(88, $capturedData['enrolled_by_user_id']);
    }
}
