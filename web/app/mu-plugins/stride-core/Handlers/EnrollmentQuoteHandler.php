<?php

declare(strict_types=1);

namespace Stride\Handlers;

use Stride\Contracts\EditionQueryInterface;
use Stride\Domain\Money;
use Stride\Modules\Invoicing\QuoteService;

/**
 * Creates quotes when users enroll in editions.
 *
 * Listens for stride/registration/created event.
 */
final class EnrollmentQuoteHandler
{
    public function __construct(
        private readonly QuoteService $quotes,
        private readonly EditionQueryInterface $editions,
    ) {
        add_action('stride/registration/created', [$this, 'onRegistrationCreated']);
    }

    /**
     * Handle registration created event.
     *
     * @param array{registration_id: int, user_id: int, edition_id: int, enrolled_by?: int|null} $data
     */
    public function onRegistrationCreated(array $data): void
    {
        $registrationId = $data['registration_id'] ?? 0;
        $userId = $data['user_id'] ?? 0;
        $editionId = $data['edition_id'] ?? 0;
        $enrolledBy = $data['enrolled_by'] ?? null;

        if (!$registrationId || !$userId || !$editionId) {
            return;
        }

        // For colleague enrollments, quote goes to the enrolling user (the one who pays)
        $quoteUserId = $enrolledBy ?: $userId;

        // Check if quote already exists
        $existing = $this->quotes->getQuoteByRegistration($registrationId);
        if ($existing) {
            return;
        }

        // Get edition details
        $edition = get_post($editionId);
        if (!$edition) {
            return;
        }

        // Get price (check if member - simplified for now)
        // Price is based on the attendee's membership status, not the enrolling user
        $price = $this->getEditionPrice($editionId, $userId);

        // Skip free editions
        if ($price->isZero()) {
            return;
        }

        // Build items array
        $items = [
            [
                'title' => $edition->post_title,
                'quantity' => 1,
                'unit_price' => $price,
                'type' => 'edition',
            ],
        ];

        // Get billing info for the user who pays (enrolling user for colleague enrollments)
        $billing = $this->getUserBilling($quoteUserId);

        // Create quote for the billing user
        $this->quotes->createQuote(
            userId: $quoteUserId,
            registrationId: $registrationId,
            editionId: $editionId,
            items: $items,
            billing: $billing,
        );
    }

    /**
     * Get edition price for user.
     */
    private function getEditionPrice(int $editionId, int $userId): Money
    {
        // Check if user is member (simplified - check user meta)
        $isMember = (bool) get_user_meta($userId, 'is_member', true);

        $field = $isMember ? 'price' : 'price_non_member';
        $amount = (float) get_post_meta($editionId, $field, true);

        // Fall back to member price if non-member price not set
        if (!$isMember && $amount === 0.0) {
            $amount = (float) get_post_meta($editionId, 'price', true);
        }

        return Money::eur($amount);
    }

    /**
     * Get user billing information.
     *
     * @return array<string, string>
     */
    private function getUserBilling(int $userId): array
    {
        $user = get_userdata($userId);

        if (!$user) {
            return [];
        }

        return [
            'name' => $user->display_name,
            'email' => $user->user_email,
            'company' => get_user_meta($userId, 'company', true) ?: '',
            'address' => get_user_meta($userId, 'billing_address', true) ?: '',
            'vat_number' => get_user_meta($userId, 'vat_number', true) ?: '',
        ];
    }
}
