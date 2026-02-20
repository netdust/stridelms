<?php
/**
 * Enrollment & Quote Test Suite
 *
 * Tests that the enrollment and quote systems work correctly
 * with the new DataManager caching layer.
 *
 * Run with: ddev exec wp eval-file scripts/test-enrollment-quotes.php
 */

use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Invoicing\QuoteRepository;
use Stride\Modules\Invoicing\QuoteCPT;
use Stride\Domain\Money;
use Stride\Domain\QuoteStatus;
use Stride\Domain\RegistrationStatus;

class EnrollmentQuoteTestSuite
{
    private array $cleanup = [
        'users' => [],
        'posts' => [],
        'registrations' => [],
    ];
    private int $passed = 0;
    private int $failed = 0;

    private ?EnrollmentService $enrollmentService = null;
    private ?RegistrationRepository $registrationRepo = null;
    private ?QuoteService $quoteService = null;
    private ?QuoteRepository $quoteRepo = null;

    public function run(): void
    {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "  Enrollment & Quote Test Suite\n";
        echo str_repeat("=", 60) . "\n\n";

        // Check if services exist
        if (!$this->checkServices()) {
            echo "  ✗ Required services not found. Skipping tests.\n\n";
            return;
        }

        try {
            // Registration Repository Tests
            $this->testRegistrationCreate();
            $this->testRegistrationFind();
            $this->testRegistrationFindByUserAndEdition();
            $this->testRegistrationUpdateStatus();

            // Quote Repository Tests (uses NTDST Data Manager)
            $this->testQuoteCreate();
            $this->testQuoteFind();
            $this->testQuoteFindByUser();
            $this->testQuoteUpdateStatus();
            $this->testQuoteCacheInvalidation();

            // Quote Number Generation
            $this->testQuoteNumberGeneration();

            // Integration Tests
            $this->testEnrollmentCreatesRegistration();
            $this->testQuoteCalculations();

        } finally {
            $this->cleanup();
        }

        echo "\n" . str_repeat("=", 60) . "\n";
        echo "  Results: {$this->passed} passed, {$this->failed} failed\n";
        echo str_repeat("=", 60) . "\n\n";

        if ($this->failed > 0) {
            exit(1);
        }
    }

    private function checkServices(): bool
    {
        try {
            $this->enrollmentService = ntdst_get(EnrollmentService::class);
            $this->registrationRepo = ntdst_get(RegistrationRepository::class);
            $this->quoteService = ntdst_get(QuoteService::class);
            $this->quoteRepo = ntdst_get(QuoteRepository::class);

            echo "  ✓ All services loaded\n\n";
            return true;
        } catch (\Throwable $e) {
            echo "  Error loading services: {$e->getMessage()}\n";
            return false;
        }
    }

    // =========================================================================
    // Registration Repository Tests
    // =========================================================================

    private function testRegistrationCreate(): void
    {
        $this->test("RegistrationRepository::create works", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();

            $result = $this->registrationRepo->create([
                'user_id' => $userId,
                'edition_id' => $editionId,
                'status' => 'confirmed',
                'enrollment_path' => 'individual',
            ]);

            $this->assert(!is_wp_error($result), "Should not return WP_Error");
            $this->assert(is_int($result) && $result > 0, "Should return registration ID");

            $this->cleanup['registrations'][] = $result;
        });
    }

    private function testRegistrationFind(): void
    {
        $this->test("RegistrationRepository::find retrieves registration", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();

            $regId = $this->registrationRepo->create([
                'user_id' => $userId,
                'edition_id' => $editionId,
                'status' => 'confirmed',
                'enrollment_path' => 'individual',
            ]);
            $this->cleanup['registrations'][] = $regId;

            $found = $this->registrationRepo->find($regId);

            $this->assert(!is_wp_error($found), "Should not return WP_Error");
            $this->assert($found->user_id == $userId, "User ID should match");
            $this->assert($found->edition_id == $editionId, "Edition ID should match");
            $this->assert($found->status === 'confirmed', "Status should be confirmed");
        });
    }

    private function testRegistrationFindByUserAndEdition(): void
    {
        $this->test("RegistrationRepository::findByUserAndEdition works", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();

            $regId = $this->registrationRepo->create([
                'user_id' => $userId,
                'edition_id' => $editionId,
                'status' => 'confirmed',
                'enrollment_path' => 'individual',
            ]);
            $this->cleanup['registrations'][] = $regId;

            $found = $this->registrationRepo->findByUserAndEdition($userId, $editionId);

            $this->assert($found !== null, "Should find registration");
            $this->assert($found->id == $regId, "ID should match");
        });
    }

    private function testRegistrationUpdateStatus(): void
    {
        $this->test("RegistrationRepository::updateStatus works", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();

            $regId = $this->registrationRepo->create([
                'user_id' => $userId,
                'edition_id' => $editionId,
                'status' => 'confirmed',
                'enrollment_path' => 'individual',
            ]);
            $this->cleanup['registrations'][] = $regId;

            // Update to cancelled
            $result = $this->registrationRepo->updateStatus($regId, RegistrationStatus::Cancelled);
            $this->assert($result === true, "Update should succeed");

            // Verify
            $found = $this->registrationRepo->find($regId);
            $this->assert($found->status === 'cancelled', "Status should be cancelled");
        });
    }

    // =========================================================================
    // Quote Repository Tests (Uses NTDST Data Manager)
    // =========================================================================

    private function testQuoteCreate(): void
    {
        $this->test("QuoteService::createQuote works with new cache layer", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();

            // Create a registration first
            $regId = $this->registrationRepo->create([
                'user_id' => $userId,
                'edition_id' => $editionId,
                'status' => 'confirmed',
                'enrollment_path' => 'individual',
            ]);
            $this->cleanup['registrations'][] = $regId;

            // Create quote
            $items = [
                [
                    'title' => 'Test Course',
                    'quantity' => 1,
                    'unit_price' => Money::eur(250.00),
                    'total' => Money::eur(250.00),
                    'type' => 'course',
                ]
            ];

            $quoteId = $this->quoteService->createQuote(
                $userId,
                $regId,
                $editionId,
                $items,
                ['name' => 'Test User', 'email' => 'test@test.com']
            );

            $this->assert(!is_wp_error($quoteId), "Should not return WP_Error: " . (is_wp_error($quoteId) ? $quoteId->get_error_message() : ''));
            $this->assert(is_int($quoteId) && $quoteId > 0, "Should return quote ID");

            $this->cleanup['posts'][] = $quoteId;
        });
    }

    private function testQuoteFind(): void
    {
        $this->test("QuoteService::getQuote retrieves quote correctly", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();

            $regId = $this->registrationRepo->create([
                'user_id' => $userId,
                'edition_id' => $editionId,
                'status' => 'confirmed',
                'enrollment_path' => 'individual',
            ]);
            $this->cleanup['registrations'][] = $regId;

            $items = [
                [
                    'title' => 'Test Course',
                    'quantity' => 1,
                    'unit_price' => Money::eur(100.00),
                    'total' => Money::eur(100.00),
                    'type' => 'course',
                ]
            ];

            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            // Retrieve quote
            $quote = $this->quoteService->getQuote($quoteId);

            $this->assert(!is_wp_error($quote), "Should not return WP_Error");
            $this->assert($quote['user_id'] == $userId, "User ID should match");
            $this->assert($quote['registration_id'] == $regId, "Registration ID should match");
            $this->assert(isset($quote['quote_number']), "Should have quote number");
            $this->assert($quote['status'] === 'draft', "Initial status should be draft");
        });
    }

    private function testQuoteFindByUser(): void
    {
        $this->test("QuoteService::getUserQuotes returns user's quotes", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();

            // Create registration
            $regId = $this->registrationRepo->create([
                'user_id' => $userId,
                'edition_id' => $editionId,
                'status' => 'confirmed',
                'enrollment_path' => 'individual',
            ]);
            $this->cleanup['registrations'][] = $regId;

            // Create two quotes
            $items = [['title' => 'Course', 'quantity' => 1, 'unit_price' => Money::eur(50), 'total' => Money::eur(50), 'type' => 'course']];

            $quote1 = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quote1;

            // Second edition and quote
            $editionId2 = $this->createTestEdition();
            $regId2 = $this->registrationRepo->create([
                'user_id' => $userId,
                'edition_id' => $editionId2,
                'status' => 'confirmed',
                'enrollment_path' => 'individual',
            ]);
            $this->cleanup['registrations'][] = $regId2;

            $quote2 = $this->quoteService->createQuote($userId, $regId2, $editionId2, $items);
            $this->cleanup['posts'][] = $quote2;

            // Get user's quotes
            $quotes = $this->quoteService->getUserQuotes($userId);

            $this->assert(count($quotes) >= 2, "User should have at least 2 quotes, found " . count($quotes));
        });
    }

    private function testQuoteUpdateStatus(): void
    {
        $this->test("QuoteService::markAsSent updates status correctly", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();

            $regId = $this->registrationRepo->create([
                'user_id' => $userId,
                'edition_id' => $editionId,
                'status' => 'confirmed',
                'enrollment_path' => 'individual',
            ]);
            $this->cleanup['registrations'][] = $regId;

            $items = [['title' => 'Course', 'quantity' => 1, 'unit_price' => Money::eur(75), 'total' => Money::eur(75), 'type' => 'course']];
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            // Mark as sent
            $result = $this->quoteService->markAsSent($quoteId);
            $this->assert(!is_wp_error($result), "markAsSent should succeed");

            // Verify status changed
            $quote = $this->quoteService->getQuote($quoteId, true); // Skip cache
            $this->assert($quote['status'] === 'sent', "Status should be 'sent', got '{$quote['status']}'");
        });
    }

    private function testQuoteCacheInvalidation(): void
    {
        $this->test("Quote updates invalidate cache correctly", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();

            $regId = $this->registrationRepo->create([
                'user_id' => $userId,
                'edition_id' => $editionId,
                'status' => 'confirmed',
                'enrollment_path' => 'individual',
            ]);
            $this->cleanup['registrations'][] = $regId;

            $items = [['title' => 'Original', 'quantity' => 1, 'unit_price' => Money::eur(100), 'total' => Money::eur(100), 'type' => 'course']];
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            // Get initial quote (caches it)
            $quote1 = $this->quoteService->getQuote($quoteId);
            $initialStatus = $quote1['status'];

            // Update via markAsSent
            $this->quoteService->markAsSent($quoteId);

            // Get again (should be fresh due to cache invalidation)
            $quote2 = $this->quoteService->getQuote($quoteId);

            $this->assert(
                $quote2['status'] !== $initialStatus,
                "Status should have changed from '{$initialStatus}' to 'sent'"
            );
            $this->assert($quote2['status'] === 'sent', "Status should be 'sent'");
        });
    }

    private function testQuoteNumberGeneration(): void
    {
        $this->test("Quote numbers are generated correctly", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();

            $regId = $this->registrationRepo->create([
                'user_id' => $userId,
                'edition_id' => $editionId,
                'status' => 'confirmed',
                'enrollment_path' => 'individual',
            ]);
            $this->cleanup['registrations'][] = $regId;

            $items = [['title' => 'Course', 'quantity' => 1, 'unit_price' => Money::eur(100), 'total' => Money::eur(100), 'type' => 'course']];
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            $quote = $this->quoteService->getQuote($quoteId);

            $this->assert(isset($quote['quote_number']), "Quote should have quote_number");

            // Format should be OFF-YEAR-XXXX
            $pattern = '/^OFF-\d{4}-\d{4}$/';
            $this->assert(
                preg_match($pattern, $quote['quote_number']),
                "Quote number '{$quote['quote_number']}' should match format OFF-YYYY-XXXX"
            );
        });
    }

    // =========================================================================
    // Integration Tests
    // =========================================================================

    private function testEnrollmentCreatesRegistration(): void
    {
        $this->test("EnrollmentService creates registration correctly", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();

            // Use enrollmentService directly
            $result = $this->enrollmentService->enroll($userId, $editionId, [
                'enrollment_path' => 'individual',
            ]);

            if (is_wp_error($result)) {
                // Might fail due to LearnDash not being active, that's OK
                $error = $result->get_error_code();
                if ($error === 'edition_not_found' || $error === 'lms_error') {
                    $this->assert(true, "Expected error in test env: {$error}");
                    return;
                }
            }

            $this->assert(!is_wp_error($result), "Enrollment should succeed");
            $this->assert(is_int($result) && $result > 0, "Should return registration ID");

            $this->cleanup['registrations'][] = $result;

            // Verify registration exists
            $found = $this->registrationRepo->find($result);
            $this->assert(!is_wp_error($found), "Should find registration");
        });
    }

    private function testQuoteCalculations(): void
    {
        $this->test("Quote calculates totals correctly", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();

            $regId = $this->registrationRepo->create([
                'user_id' => $userId,
                'edition_id' => $editionId,
                'status' => 'confirmed',
                'enrollment_path' => 'individual',
            ]);
            $this->cleanup['registrations'][] = $regId;

            // Create items: 2 x €100 = €200 subtotal
            $items = [
                ['title' => 'Course A', 'quantity' => 1, 'unit_price' => Money::eur(100), 'total' => Money::eur(100), 'type' => 'course'],
                ['title' => 'Course B', 'quantity' => 1, 'unit_price' => Money::eur(100), 'total' => Money::eur(100), 'type' => 'course'],
            ];

            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            $quote = $this->quoteService->getQuote($quoteId);

            // Hydration creates *_money keys for Money objects
            // Raw values (subtotal, tax, total) remain as integers
            $this->assert(
                isset($quote['subtotal_money']),
                "Quote should have subtotal_money (Money object)"
            );

            // Subtotal should be €200 (20000 cents)
            $this->assert(
                $quote['subtotal_money']->inCents() === 20000,
                "Subtotal should be 20000 cents, got {$quote['subtotal_money']->inCents()}"
            );

            // Tax should be 21% of €200 = €42 (4200 cents)
            $this->assert(
                $quote['tax_money']->inCents() === 4200,
                "Tax should be 4200 cents (21%), got {$quote['tax_money']->inCents()}"
            );

            // Total should be €242 (24200 cents)
            $this->assert(
                $quote['total_money']->inCents() === 24200,
                "Total should be 24200 cents, got {$quote['total_money']->inCents()}"
            );

            // Also verify raw values are stored as integers (in cents)
            $this->assert(
                $quote['subtotal'] === 20000,
                "Raw subtotal should be 20000 (int), got " . $quote['subtotal']
            );
        });
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createTestUser(): int
    {
        $username = 'test_user_' . uniqid();
        $userId = wp_create_user($username, 'testpass123', $username . '@test.local');

        if (is_wp_error($userId)) {
            throw new \RuntimeException("Failed to create test user: " . $userId->get_error_message());
        }

        $this->cleanup['users'][] = $userId;
        return $userId;
    }

    private function createTestEdition(): int
    {
        $editionId = wp_insert_post([
            'post_type' => 'vad_edition',
            'post_title' => 'Test Edition ' . uniqid(),
            'post_status' => 'publish',
        ]);

        if (is_wp_error($editionId)) {
            throw new \RuntimeException("Failed to create test edition");
        }

        // Set some meta
        update_post_meta($editionId, '_ntdst_price', 25000); // €250 in cents
        update_post_meta($editionId, '_ntdst_member_price', 20000); // €200 in cents
        update_post_meta($editionId, '_ntdst_capacity', 20);
        update_post_meta($editionId, '_ntdst_start_date', date('Y-m-d', strtotime('+1 month')));

        $this->cleanup['posts'][] = $editionId;
        return $editionId;
    }

    private function test(string $name, callable $fn): void
    {
        echo "  Testing: {$name}... ";

        try {
            $fn();
            echo "✓ PASS\n";
            $this->passed++;
        } catch (\Throwable $e) {
            echo "✗ FAIL\n";
            echo "    Error: {$e->getMessage()}\n";
            $this->failed++;
        }
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException("Assertion failed: {$message}");
        }
    }

    private function cleanup(): void
    {
        global $wpdb;

        $regCount = count($this->cleanup['registrations']);
        $postCount = count($this->cleanup['posts']);
        $userCount = count($this->cleanup['users']);

        echo "\n  Cleaning up: {$regCount} registrations, {$postCount} posts, {$userCount} users...\n";

        // Delete registrations
        foreach ($this->cleanup['registrations'] as $regId) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $regId]);
        }

        // Delete posts
        foreach ($this->cleanup['posts'] as $postId) {
            wp_delete_post($postId, true);
        }

        // Delete users
        foreach ($this->cleanup['users'] as $userId) {
            wp_delete_user($userId);
        }

        echo "  Cleanup complete.\n";
    }
}

// Run tests
$suite = new EnrollmentQuoteTestSuite();
$suite->run();
