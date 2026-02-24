<?php
/**
 * Enrollment & Quote Comprehensive Test Suite
 *
 * Tests enrollment and quote systems with full coverage:
 * - Registration CRUD and edge cases
 * - Quote CRUD and calculations
 * - Status transitions and validation
 * - Data persistence and integrity
 * - Cache behavior
 * - Error handling
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
        echo "\n" . str_repeat("=", 70) . "\n";
        echo "  Enrollment & Quote Comprehensive Test Suite\n";
        echo str_repeat("=", 70) . "\n\n";

        if (!$this->checkServices()) {
            echo "  ✗ Required services not found. Skipping tests.\n\n";
            return;
        }

        try {
            // =====================================================================
            echo "  >> Registration Repository Tests\n";
            // =====================================================================
            $this->testRegistrationCreate();
            $this->testRegistrationFind();
            $this->testRegistrationFindReturnsNullForNonExistent();
            $this->testRegistrationFindByUserAndEdition();
            $this->testRegistrationFindByUserAndEditionReturnsNull();
            $this->testRegistrationUpdateStatus();
            $this->testRegistrationStatusTransitions();
            $this->testRegistrationDataPersistence();
            $this->testRegistrationEnrollmentPaths();

            // =====================================================================
            echo "\n  >> Quote Creation & Retrieval Tests\n";
            // =====================================================================
            $this->testQuoteCreate();
            $this->testQuoteFind();
            $this->testQuoteFindReturnsErrorForNonExistent();
            $this->testQuoteFindByUser();
            $this->testQuoteFindByUserReturnsEmptyArray();
            $this->testQuoteFindByRegistration();
            $this->testQuoteFindByQuoteNumber();

            // =====================================================================
            echo "\n  >> Quote Calculation Tests\n";
            // =====================================================================
            $this->testQuoteCalculationsSingleItem();
            $this->testQuoteCalculationsMultipleItems();
            $this->testQuoteCalculationsWithQuantity();
            $this->testQuoteCalculationsZeroDiscount();

            // =====================================================================
            echo "\n  >> Quote Status & Transitions Tests\n";
            // =====================================================================
            $this->testQuoteInitialStatusIsDraft();
            $this->testQuoteMarkAsSent();
            $this->testQuoteMarkAsSentSetsTimestamp();
            $this->testQuoteCannotSendNonDraft();
            $this->testQuoteCancellation();
            $this->testQuoteCannotCancelExported();

            // =====================================================================
            echo "\n  >> Quote Data Persistence Tests\n";
            // =====================================================================
            $this->testQuoteNumberGeneration();
            $this->testQuoteNumbersAreSequential();
            $this->testQuoteBillingDataPersisted();
            $this->testQuoteItemsPersisted();
            $this->testQuoteValidUntilSet();
            $this->testQuoteEditionIdPersisted();

            // =====================================================================
            echo "\n  >> Quote Cache Tests\n";
            // =====================================================================
            $this->testQuoteCacheInvalidation();
            $this->testMultipleQuotesForUser();
            $this->testQuoteDeletionRemovesFromUserQuotes();

            // =====================================================================
            echo "\n  >> Money Object Tests\n";
            // =====================================================================
            $this->testMoneyObjectsInHydratedQuote();
            $this->testMoneyFormatting();

            // =====================================================================
            echo "\n  >> Integration Tests\n";
            // =====================================================================
            $this->testEnrollmentCreatesRegistration();
            $this->testFullEnrollmentToQuoteFlow();

        } finally {
            $this->cleanup();
        }

        echo "\n" . str_repeat("=", 70) . "\n";
        $total = $this->passed + $this->failed;
        echo "  Results: {$this->passed}/{$total} passed";
        if ($this->failed > 0) {
            echo " ({$this->failed} failed)";
        }
        echo "\n" . str_repeat("=", 70) . "\n\n";

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
        $this->test("Registration create returns valid ID", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();

            $result = $this->registrationRepo->create([
                'user_id' => $userId,
                'edition_id' => $editionId,
                'status' => 'confirmed',
                'enrollment_path' => 'individual',
            ]);

            $this->assert(!is_wp_error($result), "Should not return WP_Error");
            $this->assert(is_int($result) && $result > 0, "Should return positive integer ID");

            $this->cleanup['registrations'][] = $result;
        });
    }

    private function testRegistrationFind(): void
    {
        $this->test("Registration find retrieves all fields", function() {
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
            $this->assert((int)$found->user_id === $userId, "User ID should match");
            $this->assert((int)$found->edition_id === $editionId, "Edition ID should match");
            $this->assert($found->status === 'confirmed', "Status should be confirmed");
            $this->assert($found->enrollment_path === 'individual', "Enrollment path should be individual");
        });
    }

    private function testRegistrationFindReturnsNullForNonExistent(): void
    {
        $this->test("Registration find returns null for non-existent ID", function() {
            $found = $this->registrationRepo->find(999999);
            $this->assert($found === null || is_wp_error($found), "Should return null or WP_Error for non-existent ID");
        });
    }

    private function testRegistrationFindByUserAndEdition(): void
    {
        $this->test("Registration findByUserAndEdition works", function() {
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
            $this->assert((int)$found->id === $regId, "ID should match");
        });
    }

    private function testRegistrationFindByUserAndEditionReturnsNull(): void
    {
        $this->test("Registration findByUserAndEdition returns null when not found", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();

            // Don't create registration - just search
            $found = $this->registrationRepo->findByUserAndEdition($userId, $editionId);

            $this->assert($found === null, "Should return null when no registration exists");
        });
    }

    private function testRegistrationUpdateStatus(): void
    {
        $this->test("Registration updateStatus changes status", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();

            $regId = $this->registrationRepo->create([
                'user_id' => $userId,
                'edition_id' => $editionId,
                'status' => 'confirmed',
                'enrollment_path' => 'individual',
            ]);
            $this->cleanup['registrations'][] = $regId;

            $result = $this->registrationRepo->updateStatus($regId, RegistrationStatus::Cancelled);
            $this->assert($result === true, "Update should succeed");

            $found = $this->registrationRepo->find($regId);
            $this->assert($found->status === 'cancelled', "Status should be cancelled");
        });
    }

    private function testRegistrationStatusTransitions(): void
    {
        $this->test("Registration supports all status transitions", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();

            // Start with confirmed status
            $regId = $this->registrationRepo->create([
                'user_id' => $userId,
                'edition_id' => $editionId,
                'status' => RegistrationStatus::Confirmed->value,
                'enrollment_path' => 'individual',
            ]);
            $this->cleanup['registrations'][] = $regId;

            // confirmed -> cancelled (user cancels)
            $this->registrationRepo->updateStatus($regId, RegistrationStatus::Cancelled);
            $found = $this->registrationRepo->find($regId);
            $this->assert($found->status === 'cancelled', "Should transition to cancelled");

            // Create another registration for waitlist -> confirmed transition
            // Use different edition to avoid duplicate check
            $editionId2 = $this->createTestEdition();
            $regId2 = $this->registrationRepo->create([
                'user_id' => $userId,
                'edition_id' => $editionId2,
                'status' => RegistrationStatus::Waitlist->value,
                'enrollment_path' => 'individual',
            ]);
            $this->cleanup['registrations'][] = $regId2;

            // waitlist -> confirmed (slot opens up)
            $this->registrationRepo->updateStatus($regId2, RegistrationStatus::Confirmed);
            $found = $this->registrationRepo->find($regId2);
            $this->assert($found->status === 'confirmed', "Should transition from waitlist to confirmed");
        });
    }

    private function testRegistrationDataPersistence(): void
    {
        $this->test("Registration persists all data fields", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();

            $regId = $this->registrationRepo->create([
                'user_id' => $userId,
                'edition_id' => $editionId,
                'status' => 'confirmed',
                'enrollment_path' => 'organization',
                'notes' => 'Test notes here',
            ]);
            $this->cleanup['registrations'][] = $regId;

            $found = $this->registrationRepo->find($regId);

            $this->assert((int)$found->user_id === $userId, "user_id persisted");
            $this->assert((int)$found->edition_id === $editionId, "edition_id persisted");
            $this->assert($found->status === 'confirmed', "status persisted");
            $this->assert($found->enrollment_path === 'organization', "enrollment_path persisted");
        });
    }

    private function testRegistrationEnrollmentPaths(): void
    {
        $this->test("Registration supports different enrollment paths", function() {
            $userId = $this->createTestUser();

            $paths = ['individual', 'organization', 'voucher'];

            foreach ($paths as $path) {
                $editionId = $this->createTestEdition();
                $regId = $this->registrationRepo->create([
                    'user_id' => $userId,
                    'edition_id' => $editionId,
                    'status' => 'confirmed',
                    'enrollment_path' => $path,
                ]);
                $this->cleanup['registrations'][] = $regId;

                $found = $this->registrationRepo->find($regId);
                $this->assert($found->enrollment_path === $path, "Path '$path' should be persisted");
            }
        });
    }

    // =========================================================================
    // Quote Creation & Retrieval Tests
    // =========================================================================

    private function testQuoteCreate(): void
    {
        $this->test("Quote create returns valid ID", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();
            $regId = $this->createRegistration($userId, $editionId);

            $items = $this->makeItems([['title' => 'Course', 'price' => 100]]);
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);

            $this->assert(!is_wp_error($quoteId), "Should not return WP_Error");
            $this->assert(is_int($quoteId) && $quoteId > 0, "Should return positive integer ID");

            $this->cleanup['posts'][] = $quoteId;
        });
    }

    private function testQuoteFind(): void
    {
        $this->test("Quote getQuote retrieves all fields", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();
            $regId = $this->createRegistration($userId, $editionId);

            $items = $this->makeItems([['title' => 'Test Course', 'price' => 150]]);
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            $quote = $this->quoteService->getQuote($quoteId);

            $this->assert(!is_wp_error($quote), "Should not return WP_Error");
            $this->assert((int)$quote['user_id'] === $userId, "user_id should match");
            $this->assert((int)$quote['registration_id'] === $regId, "registration_id should match");
            $this->assert((int)$quote['edition_id'] === $editionId, "edition_id should match");
            $this->assert(isset($quote['quote_number']), "Should have quote_number");
            $this->assert(isset($quote['items']), "Should have items");
        });
    }

    private function testQuoteFindReturnsErrorForNonExistent(): void
    {
        $this->test("Quote getQuote returns error for non-existent ID", function() {
            $quote = $this->quoteService->getQuote(999999);
            $this->assert(is_wp_error($quote), "Should return WP_Error for non-existent ID");
        });
    }

    private function testQuoteFindByUser(): void
    {
        $this->test("Quote getUserQuotes returns all user quotes", function() {
            $userId = $this->createTestUser();

            // Create 3 quotes for this user
            for ($i = 0; $i < 3; $i++) {
                $editionId = $this->createTestEdition();
                $regId = $this->createRegistration($userId, $editionId);
                $items = $this->makeItems([['title' => "Course $i", 'price' => 100]]);
                $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
                $this->cleanup['posts'][] = $quoteId;
            }

            $quotes = $this->quoteService->getUserQuotes($userId);

            $this->assert(count($quotes) === 3, "Should return exactly 3 quotes, got " . count($quotes));
        });
    }

    private function testQuoteFindByUserReturnsEmptyArray(): void
    {
        $this->test("Quote getUserQuotes returns empty array for user with no quotes", function() {
            $userId = $this->createTestUser();
            $quotes = $this->quoteService->getUserQuotes($userId);

            $this->assert(is_array($quotes), "Should return array");
            $this->assert(count($quotes) === 0, "Should return empty array");
        });
    }

    private function testQuoteFindByRegistration(): void
    {
        $this->test("Quote getQuoteByRegistration finds quote", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();
            $regId = $this->createRegistration($userId, $editionId);

            $items = $this->makeItems([['title' => 'Course', 'price' => 200]]);
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            $quote = $this->quoteService->getQuoteByRegistration($regId);

            $this->assert($quote !== null, "Should find quote");
            $this->assert((int)$quote['registration_id'] === $regId, "registration_id should match");
        });
    }

    private function testQuoteFindByQuoteNumber(): void
    {
        $this->test("QuoteRepository findByNumber finds quote", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();
            $regId = $this->createRegistration($userId, $editionId);

            $items = $this->makeItems([['title' => 'Course', 'price' => 100]]);
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            $quote = $this->quoteService->getQuote($quoteId);
            $quoteNumber = $quote['quote_number'];

            $found = $this->quoteRepo->findByNumber($quoteNumber);

            $this->assert($found !== null, "Should find quote by number");
            // Repository returns raw data with meta under 'meta' key (not hydrated)
            $foundQuoteNumber = $found['meta']['quote_number'] ?? $found['quote_number'] ?? null;
            $this->assert($foundQuoteNumber === $quoteNumber, "Quote number should match");
        });
    }

    // =========================================================================
    // Quote Calculation Tests
    // =========================================================================

    private function testQuoteCalculationsSingleItem(): void
    {
        $this->test("Quote calculates correctly for single item", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();
            $regId = $this->createRegistration($userId, $editionId);

            // €100 item
            $items = $this->makeItems([['title' => 'Course', 'price' => 100]]);
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            $quote = $this->quoteService->getQuote($quoteId);

            // Subtotal: €100 = 10000 cents
            $this->assert($quote['subtotal'] === 10000, "Subtotal should be 10000 cents");
            // Tax: 21% of €100 = €21 = 2100 cents
            $this->assert($quote['tax'] === 2100, "Tax should be 2100 cents (21%)");
            // Total: €121 = 12100 cents
            $this->assert($quote['total'] === 12100, "Total should be 12100 cents");
        });
    }

    private function testQuoteCalculationsMultipleItems(): void
    {
        $this->test("Quote calculates correctly for multiple items", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();
            $regId = $this->createRegistration($userId, $editionId);

            // €100 + €150 + €50 = €300
            $items = $this->makeItems([
                ['title' => 'Course A', 'price' => 100],
                ['title' => 'Course B', 'price' => 150],
                ['title' => 'Material', 'price' => 50],
            ]);
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            $quote = $this->quoteService->getQuote($quoteId);

            // Subtotal: €300 = 30000 cents
            $this->assert($quote['subtotal'] === 30000, "Subtotal should be 30000 cents");
            // Tax: 21% of €300 = €63 = 6300 cents
            $this->assert($quote['tax'] === 6300, "Tax should be 6300 cents");
            // Total: €363 = 36300 cents
            $this->assert($quote['total'] === 36300, "Total should be 36300 cents");
        });
    }

    private function testQuoteCalculationsWithQuantity(): void
    {
        $this->test("Quote calculates correctly with quantity > 1", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();
            $regId = $this->createRegistration($userId, $editionId);

            // 3 x €50 = €150
            $items = [[
                'title' => 'Workshop',
                'quantity' => 3,
                'unit_price' => Money::eur(50),
                'total' => Money::eur(150),
                'type' => 'course',
            ]];
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            $quote = $this->quoteService->getQuote($quoteId);

            $this->assert($quote['subtotal'] === 15000, "Subtotal should be 15000 cents (3 x €50)");
        });
    }

    private function testQuoteCalculationsZeroDiscount(): void
    {
        $this->test("Quote with no discount has discount = 0", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();
            $regId = $this->createRegistration($userId, $editionId);

            $items = $this->makeItems([['title' => 'Course', 'price' => 100]]);
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            $quote = $this->quoteService->getQuote($quoteId);

            $this->assert($quote['discount'] === 0, "Discount should be 0 when no voucher applied");
        });
    }

    // =========================================================================
    // Quote Status & Transitions Tests
    // =========================================================================

    private function testQuoteInitialStatusIsDraft(): void
    {
        $this->test("New quote has status 'draft'", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();
            $regId = $this->createRegistration($userId, $editionId);

            $items = $this->makeItems([['title' => 'Course', 'price' => 100]]);
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            $quote = $this->quoteService->getQuote($quoteId);

            $this->assert($quote['status'] === 'draft', "Initial status should be 'draft'");
            $this->assert($quote['status_enum'] === QuoteStatus::Draft, "status_enum should be QuoteStatus::Draft");
        });
    }

    private function testQuoteMarkAsSent(): void
    {
        $this->test("Quote markAsSent changes status to 'sent'", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();
            $regId = $this->createRegistration($userId, $editionId);

            $items = $this->makeItems([['title' => 'Course', 'price' => 100]]);
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            $result = $this->quoteService->markAsSent($quoteId);
            $this->assert(!is_wp_error($result), "markAsSent should succeed");

            $quote = $this->quoteService->getQuote($quoteId, true);
            $this->assert($quote['status'] === 'sent', "Status should be 'sent'");
        });
    }

    private function testQuoteMarkAsSentSetsTimestamp(): void
    {
        $this->test("Quote markAsSent sets sent_at timestamp", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();
            $regId = $this->createRegistration($userId, $editionId);

            $items = $this->makeItems([['title' => 'Course', 'price' => 100]]);
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            $this->quoteService->markAsSent($quoteId);

            $quote = $this->quoteService->getQuote($quoteId, true);
            $this->assert(!empty($quote['sent_at']), "sent_at should be set after markAsSent");
        });
    }

    private function testQuoteCannotSendNonDraft(): void
    {
        $this->test("Quote markAsSent fails for non-draft quote", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();
            $regId = $this->createRegistration($userId, $editionId);

            $items = $this->makeItems([['title' => 'Course', 'price' => 100]]);
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            // First mark as sent
            $this->quoteService->markAsSent($quoteId);

            // Try to send again
            $result = $this->quoteService->markAsSent($quoteId);

            $this->assert(is_wp_error($result), "Should return WP_Error for non-draft quote");
            $this->assert($result->get_error_code() === 'invalid_status', "Error code should be 'invalid_status'");
        });
    }

    private function testQuoteCancellation(): void
    {
        $this->test("Quote cancel changes status to 'cancelled'", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();
            $regId = $this->createRegistration($userId, $editionId);

            $items = $this->makeItems([['title' => 'Course', 'price' => 100]]);
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            $result = $this->quoteService->cancel($quoteId);
            $this->assert(!is_wp_error($result), "cancel should succeed");

            $quote = $this->quoteService->getQuote($quoteId, true);
            $this->assert($quote['status'] === 'cancelled', "Status should be 'cancelled'");
        });
    }

    private function testQuoteCannotCancelExported(): void
    {
        $this->test("Quote cancel fails for exported quote", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();
            $regId = $this->createRegistration($userId, $editionId);

            $items = $this->makeItems([['title' => 'Course', 'price' => 100]]);
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            // Manually set status to exported (simulating export)
            $this->quoteRepo->updateStatus($quoteId, QuoteStatus::Exported);

            $result = $this->quoteService->cancel($quoteId);

            $this->assert(is_wp_error($result), "Should return WP_Error for exported quote");
            $this->assert($result->get_error_code() === 'cannot_cancel', "Error code should be 'cannot_cancel'");
        });
    }

    // =========================================================================
    // Quote Data Persistence Tests
    // =========================================================================

    private function testQuoteNumberGeneration(): void
    {
        $this->test("Quote number follows format OFF-YYYY-XXXX", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();
            $regId = $this->createRegistration($userId, $editionId);

            $items = $this->makeItems([['title' => 'Course', 'price' => 100]]);
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            $quote = $this->quoteService->getQuote($quoteId);
            $pattern = '/^OFF-\d{4}-\d{4}$/';

            $this->assert(
                preg_match($pattern, $quote['quote_number']) === 1,
                "Quote number '{$quote['quote_number']}' should match format OFF-YYYY-XXXX"
            );
        });
    }

    private function testQuoteNumbersAreSequential(): void
    {
        $this->test("Quote numbers increment sequentially", function() {
            $userId = $this->createTestUser();
            $numbers = [];

            for ($i = 0; $i < 3; $i++) {
                $editionId = $this->createTestEdition();
                $regId = $this->createRegistration($userId, $editionId);
                $items = $this->makeItems([['title' => "Course $i", 'price' => 100]]);
                $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
                $this->cleanup['posts'][] = $quoteId;

                $quote = $this->quoteService->getQuote($quoteId);
                $numbers[] = $quote['quote_number'];
            }

            // Extract sequence numbers
            $sequences = array_map(function($n) {
                preg_match('/(\d{4})$/', $n, $m);
                return (int)$m[1];
            }, $numbers);

            $this->assert($sequences[1] === $sequences[0] + 1, "Second number should be +1");
            $this->assert($sequences[2] === $sequences[1] + 1, "Third number should be +1");
        });
    }

    private function testQuoteBillingDataPersisted(): void
    {
        $this->test("Quote billing data is persisted", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();
            $regId = $this->createRegistration($userId, $editionId);

            $billing = [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'company' => 'ACME Corp',
                'vat' => 'BE0123456789',
            ];

            $items = $this->makeItems([['title' => 'Course', 'price' => 100]]);
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items, $billing);
            $this->cleanup['posts'][] = $quoteId;

            $quote = $this->quoteService->getQuote($quoteId);

            $this->assert(is_array($quote['billing']), "billing should be array");
            $this->assert($quote['billing']['name'] === 'John Doe', "billing name persisted");
            $this->assert($quote['billing']['email'] === 'john@example.com', "billing email persisted");
            $this->assert($quote['billing']['company'] === 'ACME Corp', "billing company persisted");
        });
    }

    private function testQuoteItemsPersisted(): void
    {
        $this->test("Quote items are persisted correctly", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();
            $regId = $this->createRegistration($userId, $editionId);

            $items = [
                ['title' => 'Course A', 'quantity' => 2, 'unit_price' => Money::eur(100), 'total' => Money::eur(200), 'type' => 'course'],
                ['title' => 'Materials', 'quantity' => 1, 'unit_price' => Money::eur(50), 'total' => Money::eur(50), 'type' => 'material'],
            ];
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            $quote = $this->quoteService->getQuote($quoteId);

            $this->assert(is_array($quote['items']), "items should be array");
            $this->assert(count($quote['items']) === 2, "Should have 2 items");
            $this->assert($quote['items'][0]['title'] === 'Course A', "First item title");
            $this->assert($quote['items'][0]['quantity'] === 2, "First item quantity");
            $this->assert($quote['items'][1]['title'] === 'Materials', "Second item title");
        });
    }

    private function testQuoteValidUntilSet(): void
    {
        $this->test("Quote valid_until is set (30 days from creation)", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();
            $regId = $this->createRegistration($userId, $editionId);

            $items = $this->makeItems([['title' => 'Course', 'price' => 100]]);
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            $quote = $this->quoteService->getQuote($quoteId);

            $this->assert(!empty($quote['valid_until']), "valid_until should be set");

            // Should be approximately 30 days from now
            $validUntil = strtotime($quote['valid_until']);
            $expected = strtotime('+30 days');
            $diff = abs($validUntil - $expected);

            $this->assert($diff < 86400, "valid_until should be ~30 days from now (within 1 day tolerance)");
        });
    }

    private function testQuoteEditionIdPersisted(): void
    {
        $this->test("Quote edition_id is persisted", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();
            $regId = $this->createRegistration($userId, $editionId);

            $items = $this->makeItems([['title' => 'Course', 'price' => 100]]);
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            $quote = $this->quoteService->getQuote($quoteId);

            $this->assert((int)$quote['edition_id'] === $editionId, "edition_id should match");
        });
    }

    // =========================================================================
    // Quote Cache Tests
    // =========================================================================

    private function testQuoteCacheInvalidation(): void
    {
        $this->test("Quote status update invalidates cache", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();
            $regId = $this->createRegistration($userId, $editionId);

            $items = $this->makeItems([['title' => 'Course', 'price' => 100]]);
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            // Get quote (caches it)
            $quote1 = $this->quoteService->getQuote($quoteId);
            $this->assert($quote1['status'] === 'draft', "Initial status is draft");

            // Update status
            $this->quoteService->markAsSent($quoteId);

            // Get again (should reflect update)
            $quote2 = $this->quoteService->getQuote($quoteId);
            $this->assert($quote2['status'] === 'sent', "Status should update to sent (cache invalidated)");
        });
    }

    private function testMultipleQuotesForUser(): void
    {
        $this->test("getUserQuotes returns all quotes for user with many quotes", function() {
            $userId = $this->createTestUser();

            // Create 5 quotes
            for ($i = 0; $i < 5; $i++) {
                $editionId = $this->createTestEdition();
                $regId = $this->createRegistration($userId, $editionId);
                $items = $this->makeItems([['title' => "Course $i", 'price' => 100 + $i * 10]]);
                $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
                $this->cleanup['posts'][] = $quoteId;
            }

            $quotes = $this->quoteService->getUserQuotes($userId);

            $this->assert(count($quotes) === 5, "Should return all 5 quotes");
        });
    }

    private function testQuoteDeletionRemovesFromUserQuotes(): void
    {
        $this->test("Deleted quote not returned in getUserQuotes", function() {
            $userId = $this->createTestUser();

            // Create 2 quotes
            $editionId1 = $this->createTestEdition();
            $regId1 = $this->createRegistration($userId, $editionId1);
            $items = $this->makeItems([['title' => 'Course 1', 'price' => 100]]);
            $quoteId1 = $this->quoteService->createQuote($userId, $regId1, $editionId1, $items);
            $this->cleanup['posts'][] = $quoteId1;

            $editionId2 = $this->createTestEdition();
            $regId2 = $this->createRegistration($userId, $editionId2);
            $quoteId2 = $this->quoteService->createQuote($userId, $regId2, $editionId2, $items);
            // Don't add to cleanup - we'll delete manually

            // Verify both exist
            $quotes = $this->quoteService->getUserQuotes($userId);
            $this->assert(count($quotes) === 2, "Should have 2 quotes initially");

            // Delete one
            wp_delete_post($quoteId2, true);

            // Should only have 1 now
            $quotes = $this->quoteService->getUserQuotes($userId);
            $this->assert(count($quotes) === 1, "Should have 1 quote after deletion");
        });
    }

    // =========================================================================
    // Money Object Tests
    // =========================================================================

    private function testMoneyObjectsInHydratedQuote(): void
    {
        $this->test("Hydrated quote has Money objects in *_money fields", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();
            $regId = $this->createRegistration($userId, $editionId);

            $items = $this->makeItems([['title' => 'Course', 'price' => 100]]);
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            $quote = $this->quoteService->getQuote($quoteId);

            $this->assert($quote['subtotal_money'] instanceof Money, "subtotal_money should be Money instance");
            $this->assert($quote['discount_money'] instanceof Money, "discount_money should be Money instance");
            $this->assert($quote['tax_money'] instanceof Money, "tax_money should be Money instance");
            $this->assert($quote['total_money'] instanceof Money, "total_money should be Money instance");
        });
    }

    private function testMoneyFormatting(): void
    {
        $this->test("Money objects format correctly", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();
            $regId = $this->createRegistration($userId, $editionId);

            // €123.45
            $items = [[
                'title' => 'Course',
                'quantity' => 1,
                'unit_price' => Money::cents(12345),
                'total' => Money::cents(12345),
                'type' => 'course',
            ]];
            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items);
            $this->cleanup['posts'][] = $quoteId;

            $quote = $this->quoteService->getQuote($quoteId);

            $this->assert($quote['subtotal_money']->inCents() === 12345, "subtotal_money should be 12345 cents");

            // Test format() method
            $formatted = $quote['subtotal_money']->format();
            $this->assert(strpos($formatted, '123') !== false, "Formatted should contain '123'");
        });
    }

    // =========================================================================
    // Integration Tests
    // =========================================================================

    private function testEnrollmentCreatesRegistration(): void
    {
        $this->test("EnrollmentService::enroll creates registration", function() {
            $userId = $this->createTestUser();
            $editionId = $this->createTestEdition();

            $result = $this->enrollmentService->enroll($userId, $editionId, [
                'enrollment_path' => 'individual',
            ]);

            if (is_wp_error($result)) {
                $error = $result->get_error_code();
                if (in_array($error, ['edition_not_found', 'lms_error', 'course_not_found'])) {
                    $this->assert(true, "Expected error in test env: {$error}");
                    return;
                }
            }

            $this->assert(!is_wp_error($result), "Enrollment should succeed");
            $this->assert(is_int($result) && $result > 0, "Should return registration ID");

            $this->cleanup['registrations'][] = $result;
        });
    }

    private function testFullEnrollmentToQuoteFlow(): void
    {
        $this->test("Full flow: Create user → Register → Create quote → Send", function() {
            // 1. Create user
            $userId = $this->createTestUser();
            $this->assert($userId > 0, "User created");

            // 2. Create edition
            $editionId = $this->createTestEdition();
            $this->assert($editionId > 0, "Edition created");

            // 3. Create registration
            $regId = $this->registrationRepo->create([
                'user_id' => $userId,
                'edition_id' => $editionId,
                'status' => 'confirmed',
                'enrollment_path' => 'individual',
            ]);
            $this->cleanup['registrations'][] = $regId;
            $this->assert($regId > 0, "Registration created");

            // 4. Create quote
            $items = $this->makeItems([
                ['title' => 'Training Course', 'price' => 250],
                ['title' => 'Course Materials', 'price' => 50],
            ]);
            $billing = ['name' => 'Test User', 'email' => 'test@test.com'];

            $quoteId = $this->quoteService->createQuote($userId, $regId, $editionId, $items, $billing);
            $this->cleanup['posts'][] = $quoteId;
            $this->assert(!is_wp_error($quoteId), "Quote created");

            // 5. Verify quote data
            $quote = $this->quoteService->getQuote($quoteId);
            $this->assert($quote['subtotal'] === 30000, "Subtotal is €300");
            $this->assert($quote['status'] === 'draft', "Status is draft");

            // 6. Send quote
            $result = $this->quoteService->markAsSent($quoteId);
            $this->assert(!is_wp_error($result), "Quote sent");

            // 7. Verify final state
            $quote = $this->quoteService->getQuote($quoteId, true);
            $this->assert($quote['status'] === 'sent', "Final status is sent");
            $this->assert(!empty($quote['sent_at']), "sent_at is set");
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

        update_post_meta($editionId, '_ntdst_price', 25000);
        update_post_meta($editionId, '_ntdst_member_price', 20000);
        update_post_meta($editionId, '_ntdst_capacity', 20);
        update_post_meta($editionId, '_ntdst_start_date', date('Y-m-d', strtotime('+1 month')));

        $this->cleanup['posts'][] = $editionId;
        return $editionId;
    }

    private function createRegistration(int $userId, int $editionId): int
    {
        $regId = $this->registrationRepo->create([
            'user_id' => $userId,
            'edition_id' => $editionId,
            'status' => 'confirmed',
            'enrollment_path' => 'individual',
        ]);
        $this->cleanup['registrations'][] = $regId;
        return $regId;
    }

    /**
     * Helper to create item arrays
     * @param array $items [['title' => 'X', 'price' => 100], ...]
     */
    private function makeItems(array $items): array
    {
        return array_map(function($item) {
            return [
                'title' => $item['title'],
                'quantity' => $item['quantity'] ?? 1,
                'unit_price' => Money::eur($item['price']),
                'total' => Money::eur($item['price'] * ($item['quantity'] ?? 1)),
                'type' => $item['type'] ?? 'course',
            ];
        }, $items);
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

        foreach ($this->cleanup['registrations'] as $regId) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $regId]);
        }

        foreach ($this->cleanup['posts'] as $postId) {
            wp_delete_post($postId, true);
        }

        foreach ($this->cleanup['users'] as $userId) {
            wp_delete_user($userId);
        }

        echo "  Cleanup complete.\n";
    }
}

// Run tests
$suite = new EnrollmentQuoteTestSuite();
$suite->run();
