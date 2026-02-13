<?php

namespace ntdst\Stride\handlers;

defined('ABSPATH') || exit;

use ntdst\Stride\core\CourseService;
use ntdst\Stride\core\EditionService;
use ntdst\Stride\core\RegistrationRepository;
use ntdst\Stride\core\SubscriberService;
use ntdst\Stride\invoicing\QuoteService;
use ntdst\Stride\invoicing\VoucherService;
use ntdst\Stride\invoicing\Support\QuoteConfig;

/**
 * Enrollment Quote Handler
 *
 * Bridge between enrollment/edition modules and invoicing.
 * Provides edition-specific implementations for the generic quote system.
 *
 * Responsibilities:
 * - Provides item resolution for 'edition' type via filters
 * - Calculates voucher discounts for editions
 * - Creates quotes when enrollments are completed
 * - Links quotes to registrations
 *
 * Filter handlers:
 * - stride/quote/resolve_item: Returns edition title, price, validation
 * - stride/quote/resolve_price: Returns edition price
 * - stride/quote/calculate_discount: Calculates voucher discount
 *
 * @package stride\services\handlers
 */
class EnrollmentQuoteHandler implements \NTDST_Service_Meta
{
    private ?SubscriberService $subscriberService = null;
    private ?CourseService $courseService = null;
    private ?EditionService $editionService = null;
    private ?RegistrationRepository $registrationRepository = null;
    private ?QuoteService $quoteService = null;
    private ?VoucherService $voucherService = null;

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Enrollment Quote Handler',
            'description' => 'Bridge between enrollment and invoicing - provides edition-specific quote resolution',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 50, // After QuoteService and VoucherService
        ];
    }

    /**
     * Constructor - register hooks
     */
    public function __construct()
    {
        // Register filter handlers for edition item resolution
        add_filter('stride/quote/resolve_item', [$this, 'resolveItem'], 10, 3);
        add_filter('stride/quote/resolve_price', [$this, 'resolvePrice'], 10, 3);
        add_filter('stride/quote/calculate_discount', [$this, 'calculateDiscount'], 10, 5);

        // Listen for enrollment completion to auto-create quotes
        // New signature: (userId, editionId, registrationId, data)
        add_action('stride/enrollment/completed', [$this, 'onEnrollmentCompleted'], 10, 4);

        // Listen for enrollment cancellation to cancel quotes (free cancellation only)
        // Signature: (userId, editionId, registrationId, data)
        add_action('stride/enrollment/cancelled', [$this, 'onEnrollmentCancelled'], 10, 4);
    }

    // ========================================
    // FILTER HANDLERS
    // ========================================

    /**
     * Resolve item details for 'edition' type
     *
     * @param array|null $resolved Previous resolution (for chaining)
     * @param string $itemType Item type ('edition' or 'course' for legacy)
     * @param int $itemId Item ID
     * @return array|null Resolved item data or null if not handled
     */
    public function resolveItem(?array $resolved, string $itemType, int $itemId): ?array
    {
        // Handle edition type
        if ($itemType === 'edition') {
            return $this->resolveEditionItem($itemId);
        }

        // Handle legacy course type
        if ($itemType === 'course') {
            return $this->resolveCourseItem($itemId);
        }

        return $resolved;
    }

    /**
     * Resolve edition item details
     */
    private function resolveEditionItem(int $editionId): ?array
    {
        $editionService = $this->getEditionService();
        if (!$editionService) {
            return null;
        }

        $edition = $editionService->getEdition($editionId);
        if (!$edition) {
            return [
                'valid' => false,
                'error' => __('Editie niet gevonden.', 'stride'),
            ];
        }

        $price = $editionService->getPrice($editionId) ?? 0.0;
        $invoiceItemId = $editionService->getInvoiceItem($editionId);

        return [
            'valid' => true,
            'title' => $edition['title'],
            'price' => $price,
            'meta' => [
                'edition_id' => $editionId,
                'course_id' => $edition['course_id'],
                'invoice_item_id' => $invoiceItemId,
            ],
        ];
    }

    /**
     * Resolve legacy course item details (deprecated)
     */
    private function resolveCourseItem(int $courseId): ?array
    {
        $courseService = $this->getCourseService();
        if (!$courseService) {
            return null;
        }

        $validation = $courseService->validateCourse($courseId);
        if (is_wp_error($validation)) {
            return [
                'valid' => false,
                'error' => $validation->get_error_message(),
            ];
        }

        $title = $courseService->getCourseTitle($courseId);

        // Try to get price from an open edition
        $editionService = $this->getEditionService();
        $price = 0.0;
        $editionId = null;

        if ($editionService) {
            $editions = $editionService->getEditionsForCourse($courseId);
            foreach ($editions as $edition) {
                if ($editionService->isEnrollmentOpen($edition['id'])) {
                    $price = $editionService->getPrice($edition['id']) ?? 0.0;
                    $editionId = $edition['id'];
                    break;
                }
            }
        }

        return [
            'valid' => true,
            'title' => $title,
            'price' => $price,
            'meta' => [
                'course_id' => $courseId,
                'edition_id' => $editionId,
            ],
        ];
    }

    /**
     * Resolve price for 'edition' type
     *
     * @param float $price Previous price (for chaining)
     * @param string $itemType Item type
     * @param int $itemId Item ID
     * @return float Price
     */
    public function resolvePrice(float $price, string $itemType, int $itemId): float
    {
        if ($itemType === 'edition') {
            $editionService = $this->getEditionService();
            if ($editionService) {
                return $editionService->getPrice($itemId) ?? $price;
            }
        }

        // Legacy: course type
        if ($itemType === 'course') {
            $editionService = $this->getEditionService();
            if ($editionService) {
                $editions = $editionService->getEditionsForCourse($itemId);
                foreach ($editions as $edition) {
                    if ($editionService->isEnrollmentOpen($edition['id'])) {
                        return $editionService->getPrice($edition['id']) ?? $price;
                    }
                }
            }
        }

        return $price;
    }

    /**
     * Calculate voucher discount for editions
     *
     * @param float $discount Previous discount (for chaining)
     * @param string $voucherCode Voucher code
     * @param string $itemType Item type
     * @param int $itemId Item ID
     * @param float $itemPrice Item price
     * @return float Discount amount
     */
    public function calculateDiscount(float $discount, string $voucherCode, string $itemType, int $itemId, float $itemPrice): float
    {
        $voucherService = $this->getVoucherService();
        if (!$voucherService) {
            return $discount;
        }

        // For editions, validate against the edition
        // For legacy courses, validate against course
        $validateAgainst = $itemId;
        if ($itemType === 'edition') {
            $editionService = $this->getEditionService();
            if ($editionService) {
                $courseId = $editionService->getCourseId($itemId);
                if ($courseId) {
                    $validateAgainst = $courseId;
                }
            }
        }

        $voucher = $voucherService->validateVoucher($voucherCode, $validateAgainst);
        if (is_wp_error($voucher)) {
            return $discount;
        }

        return $voucherService->calculateDiscount($voucher, $itemType, $itemId, $itemPrice);
    }

    // ========================================
    // ENROLLMENT HANDLER
    // ========================================

    /**
     * Handle enrollment completion - create quote if applicable
     *
     * @param int $userId WordPress user ID
     * @param int $editionId Edition post ID
     * @param int $registrationId Registration ID in custom table
     * @param array $data Enrollment data
     */
    public function onEnrollmentCompleted(int $userId, int $editionId, int $registrationId, array $data): void
    {
        if (!$this->shouldCreateQuote($userId, $editionId)) {
            return;
        }

        $quoteService = $this->getQuoteService();
        if (!$quoteService) {
            return;
        }

        // Create quote with edition-specific data
        $result = $quoteService->createQuoteForItem($userId, 'edition', $editionId, $data);

        if (is_wp_error($result)) {
            if (function_exists('ntdst_log')) {
                ntdst_log()->error('Failed to create quote for enrollment', [
                    'user_id' => $userId,
                    'edition_id' => $editionId,
                    'registration_id' => $registrationId,
                    'error' => $result->get_error_message(),
                ]);
            }
            return;
        }

        // Link quote to registration
        // Note: createQuoteForItem returns int|WP_Error, not WP_Post
        $registrationRepository = $this->getRegistrationRepository();
        if ($registrationRepository && is_int($result)) {
            $registrationRepository->linkQuote($registrationId, $result);
        }
    }

    /**
     * Determine if a quote should be created
     *
     * Business rules:
     * - User is not an admin
     * - User email is not from skip domains (vad.be, druglijn.be)
     * - User doesn't have "geen-factuur" tag in FluentCRM
     * - Edition has a price > 0
     * - Edition has invoicing enabled
     * - No existing quote for this user/edition combination
     *
     * @param int $userId WordPress user ID
     * @param int $editionId Edition post ID
     * @return bool
     */
    private function shouldCreateQuote(int $userId, int $editionId): bool
    {
        // Skip if user is admin
        if (user_can($userId, 'manage_options')) {
            return false;
        }

        // Skip for internal email domains
        $subscriberService = $this->getSubscriberService();
        if ($subscriberService) {
            $emailDomain = $subscriberService->getUserEmailDomain($userId);
            if (!$emailDomain) {
                return false;
            }

            $skipDomains = QuoteConfig::get('skip_domains', ['vad.be', 'druglijn.be']);
            if (in_array($emailDomain, $skipDomains, true)) {
                return false;
            }

            // Skip if user has "geen-factuur" tag in FluentCRM
            $skipTag = QuoteConfig::get('skip_tag', 'geen-factuur');
            if ($skipTag && $subscriberService->hasTag($userId, $skipTag)) {
                return false;
            }
        }

        // Check edition settings
        $editionService = $this->getEditionService();
        if ($editionService) {
            // Skip if invoicing is disabled for this edition
            if (!$editionService->isInvoiceEnabled($editionId)) {
                return false;
            }

            // Skip if edition has no price
            $price = $editionService->getPrice($editionId);
            if (!$price || $price <= 0) {
                return false;
            }
        }

        // Skip if quote already exists for this user/edition
        $quoteService = $this->getQuoteService();
        if ($quoteService) {
            $existingByItem = $quoteService->findQuote([
                QuoteService::FIELD_USER_ID => $userId,
                QuoteService::FIELD_ITEM_TYPE => 'edition',
                QuoteService::FIELD_ITEM_ID => $editionId,
            ]);
            if ($existingByItem) {
                return false;
            }
        }

        return true;
    }

    /**
     * Handle enrollment cancellation - cancel quote if free cancellation
     *
     * Business rules:
     * - Only cancel quote if free_cancellation is true (>14 days before start)
     * - Within 14 days: quote remains to be invoiced (swap allowed but not cancel)
     *
     * @param int $userId WordPress user ID
     * @param int $editionId Edition post ID
     * @param int $registrationId Registration ID
     * @param array $data Cancellation data including 'free_cancellation' and 'quote_id'
     */
    public function onEnrollmentCancelled(int $userId, int $editionId, int $registrationId, array $data): void
    {
        // Only cancel quote on free cancellation
        $freeCancellation = $data['free_cancellation'] ?? false;
        if (!$freeCancellation) {
            return;
        }

        // Get quote ID from cancellation data or lookup
        $quoteId = $data['quote_id'] ?? null;

        if (!$quoteId) {
            // Try to find the quote
            $quoteService = $this->getQuoteService();
            if (!$quoteService) {
                return;
            }

            $quote = $quoteService->findQuote([
                QuoteService::FIELD_USER_ID => $userId,
                QuoteService::FIELD_ITEM_TYPE => 'edition',
                QuoteService::FIELD_ITEM_ID => $editionId,
            ]);

            if (!$quote) {
                return;
            }

            $quoteId = $quote['id'];
        }

        // Cancel the quote
        $quoteService = $this->getQuoteService();
        if (!$quoteService) {
            return;
        }

        $result = $quoteService->cancelQuote($quoteId, __('Inschrijving geannuleerd (gratis annulering)', 'stride'));

        if (is_wp_error($result)) {
            if (function_exists('ntdst_log')) {
                ntdst_log()->warning('Failed to cancel quote on enrollment cancellation', [
                    'user_id' => $userId,
                    'edition_id' => $editionId,
                    'registration_id' => $registrationId,
                    'quote_id' => $quoteId,
                    'error' => $result->get_error_message(),
                ]);
            }
        }
    }

    // ========================================
    // SERVICE RESOLUTION (lazy-loaded)
    // ========================================

    /**
     * Get SubscriberService instance
     */
    private function getSubscriberService(): ?SubscriberService
    {
        if ($this->subscriberService === null) {
            $this->subscriberService = $this->resolveService(SubscriberService::class);
        }
        return $this->subscriberService;
    }

    /**
     * Get CourseService instance
     */
    private function getCourseService(): ?CourseService
    {
        if ($this->courseService === null) {
            $this->courseService = $this->resolveService(CourseService::class);
        }
        return $this->courseService;
    }

    /**
     * Get EditionService instance
     */
    private function getEditionService(): ?EditionService
    {
        if ($this->editionService === null) {
            $this->editionService = $this->resolveService(EditionService::class);
        }
        return $this->editionService;
    }

    /**
     * Get RegistrationRepository instance
     */
    private function getRegistrationRepository(): ?RegistrationRepository
    {
        if ($this->registrationRepository === null) {
            $this->registrationRepository = $this->resolveService(RegistrationRepository::class);
        }
        return $this->registrationRepository;
    }

    /**
     * Get QuoteService instance
     */
    private function getQuoteService(): ?QuoteService
    {
        if ($this->quoteService === null) {
            $this->quoteService = $this->resolveService(QuoteService::class);
        }
        return $this->quoteService;
    }

    /**
     * Get VoucherService instance
     */
    private function getVoucherService(): ?VoucherService
    {
        if ($this->voucherService === null) {
            $this->voucherService = $this->resolveService(VoucherService::class);
        }
        return $this->voucherService;
    }

    /**
     * Resolve service from DI container
     */
    private function resolveService(string $class): ?object
    {
        if (function_exists('ntdst_get')) {
            try {
                $service = ntdst_get($class);
                if ($service instanceof $class) {
                    return $service;
                }
            } catch (\Exception $e) {
                // Service not available
            }
        }
        return null;
    }
}
