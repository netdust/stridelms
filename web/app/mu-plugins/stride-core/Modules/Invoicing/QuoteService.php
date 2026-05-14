<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing;

use Stride\Contracts\EditionQueryInterface;
use Stride\Domain\Money;
use Stride\Domain\QuoteStatus;
use Stride\Infrastructure\AbstractService;
use Stride\Modules\Invoicing\Helpers\QuoteCalculator;
use WP_Error;
use WP_Post;

/**
 * Quote business logic.
 */
final class QuoteService extends AbstractService
{
    private VoucherService $voucherService;

    public function __construct(
        private readonly QuoteRepository $repository,
        private readonly EditionQueryInterface $editions,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Quote Service',
            'description' => 'Manages quotes and invoices',
            'priority' => 20,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'invoicing';
    }

    protected function init(): void
    {
        QuoteCPT::register();
        VoucherCPT::register();

        // Register sub-components as singletons
        $voucherRepo = ntdst_get(VoucherRepository::class);
        $this->voucherService = new VoucherService(
            $voucherRepo,
            new Helpers\VoucherScopeValidator(),
            new Helpers\VoucherProrater(),
            ntdst_get(\Stride\Modules\Edition\SessionService::class),
        );
        ntdst_set(VoucherService::class, fn() => $this->voucherService);

        // Admin UI (registers own hooks in constructor)
        new Admin\QuoteAdminController(
            $this,
            $this->repository,
            $this->voucherService,
            ntdst_get(\Stride\Modules\Edition\EditionRepository::class),
        );
        new Admin\VoucherAdminController(
            $this->voucherService,
            $voucherRepo,
        );

        // PDF generator (registers own hooks)
        new QuotePDFGenerator($this);

        // Cancel quote when registration is cancelled
        add_action('stride/registration/cancelled', [$this, 'onRegistrationCancelled']);

        // Update quote modifiers when session selection changes
        add_action('stride/enrollment/task_completed', [$this, 'onSessionSelectionCompleted']);
    }

    /**
     * Handle registration cancellation - cancel associated quote.
     *
     * @param array{registration_id: int, user_id: int, edition_id: int} $data Event data
     */
    public function onRegistrationCancelled(array $data): void
    {
        $registrationId = (int) ($data['registration_id'] ?? 0);
        if (!$registrationId) {
            return;
        }

        // Find quote for this registration
        $quote = $this->getQuoteByRegistration($registrationId);

        if (!$quote) {
            // No quote exists for this registration
            return;
        }

        $quoteId = (int) $quote['id'];
        $status = QuoteStatus::tryFrom($quote['status'] ?? '');

        // Don't cancel already cancelled or exported quotes
        if ($status === QuoteStatus::Cancelled) {
            return;
        }

        if ($status === QuoteStatus::Exported) {
            ntdst_log('invoicing')->warning('Registration cancelled but quote already exported', [
                'registration_id' => $registrationId,
                'quote_id' => $quoteId,
            ]);
            return;
        }

        // Cancel the quote (without triggering registration cancellation again)
        $result = $this->repository->updateStatus($quoteId, QuoteStatus::Cancelled);

        if ($result) {
            ntdst_log('invoicing')->info('Quote cancelled due to registration cancellation', [
                'registration_id' => $registrationId,
                'quote_id' => $quoteId,
            ]);
        }
    }

    /**
     * Handle session selection completion — update quote with price modifiers.
     *
     * When a user completes (or re-submits) session selection, this recalculates
     * the quote line items based on selected sessions' price modifiers.
     *
     * @param array{registration_id: int, task_type: string, tasks: array} $data Event data
     */
    public function onSessionSelectionCompleted(array $data): void
    {
        // Only handle session_selection task completions
        if (($data['task_type'] ?? '') !== 'session_selection') {
            return;
        }

        $registrationId = (int) ($data['registration_id'] ?? 0);
        if (!$registrationId) {
            return;
        }

        $sessionIds = $data['tasks']['session_selection']['data']['session_ids'] ?? [];
        if (!is_array($sessionIds)) {
            return;
        }
        $sessionIds = array_map('intval', $sessionIds);

        // Find quote for this registration
        $quote = $this->getQuoteByRegistration($registrationId);

        if (!$quote) {
            ntdst_log('invoicing')->info('No quote found for session selection update', [
                'registration_id' => $registrationId,
            ]);
            return;
        }

        $quoteId = (int) $quote['id'];
        $status = $quote['status_enum'] ?? QuoteStatus::tryFrom($quote['status'] ?? '');

        // Cancelled quotes — silent return
        if ($status === QuoteStatus::Cancelled) {
            return;
        }

        // Get registration to determine edition
        $registration = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class)?->find($registrationId);
        if (!$registration) {
            return;
        }
        $editionId = (int) ($registration->edition_id ?? $registration->fields['edition_id'] ?? 0);
        if (!$editionId) {
            return;
        }

        // Get all sessions for the edition
        $allSessions = ntdst_get(\Stride\Modules\Edition\SessionService::class)?->getSessionsForEdition($editionId) ?? [];

        // Build modifier items from selected sessions
        $modifierItems = $this->buildModifierItems($sessionIds, $allSessions, $editionId);

        // Non-draft quotes: block changes, fire event for manual resolution
        if ($status !== QuoteStatus::Draft) {
            if (!empty($modifierItems)) {
                $modifiers = array_map(fn(array $item) => [
                    'session_id' => $item['id'],
                    'title' => $item['title'],
                    'amount_cents' => $item['unit_price'],
                ], $modifierItems);

                do_action('stride/quote/session_modifier_blocked', [
                    'quote_id' => $quoteId,
                    'registration_id' => $registrationId,
                    'user_id' => (int) ($quote['user_id'] ?? 0),
                    'modifiers' => $modifiers,
                ]);

                ntdst_log('invoicing')->warning('Session modifier change blocked: quote not in draft', [
                    'quote_id' => $quoteId,
                    'registration_id' => $registrationId,
                    'status' => $status->value ?? $status,
                ]);
            }
            return;
        }

        // Get existing items and check if there are current modifiers
        $existingItems = $quote['items'] ?? [];
        if (is_string($existingItems)) {
            $existingItems = json_decode($existingItems, true) ?: [];
        }

        $hadModifiers = !empty(array_filter($existingItems, fn(array $item) => ($item['type'] ?? 'edition') === 'session_modifier'));

        // Nothing to do if no modifiers exist and none to add
        if (!$hadModifiers && empty($modifierItems)) {
            return;
        }

        // Replace modifier items, preserving edition items
        $updatedItems = $this->replaceModifierItems($existingItems, $modifierItems);

        // Recalculate totals from raw cents (supports negative modifiers)
        $subtotalCents = 0;
        foreach ($updatedItems as $item) {
            $subtotalCents += (int) ($item['unit_price'] ?? 0) * (int) ($item['quantity'] ?? 1);
        }

        $discountCents = (int) ($quote['discount'] ?? 0);
        $taxableCents = max(0, $subtotalCents - $discountCents);
        $taxCents = (int) round($taxableCents * 0.21);
        $totalCents = $taxableCents + $taxCents;

        // Update quote
        $this->repository->updateMeta($quoteId, [
            'items' => $updatedItems,
            'subtotal' => $subtotalCents,
            'tax' => $taxCents,
            'total' => $totalCents,
        ]);

        ntdst_log('invoicing')->info('Quote updated with session modifiers', [
            'quote_id' => $quoteId,
            'registration_id' => $registrationId,
            'modifier_count' => count($modifierItems),
            'new_subtotal' => $subtotalCents,
            'new_total' => $totalCents,
        ]);

        $this->dispatch('quote/modifiers_applied', [
            'quote_id' => $quoteId,
            'registration_id' => $registrationId,
            'modifier_count' => count($modifierItems),
            'subtotal' => $subtotalCents,
            'total' => $totalCents,
        ]);
    }

    /**
     * Build modifier line items from selected sessions.
     *
     * Only sessions that belong to the edition, have a non-empty slot,
     * and have a non-zero price_modifier produce items.
     *
     * @param int[]   $selectedIds  Session IDs the user selected
     * @param array[] $allSessions  All sessions for the edition
     * @param int     $editionId    The edition to scope to
     * @return array[] Line items with type=session_modifier
     */
    private function buildModifierItems(array $selectedIds, array $allSessions, int $editionId): array
    {
        $selectedSet = array_flip($selectedIds);
        $items = [];

        foreach ($allSessions as $session) {
            $sessionId = (int) $session['id'];

            if (!isset($selectedSet[$sessionId])) {
                continue;
            }

            if ((int) $session['edition_id'] !== $editionId) {
                continue;
            }

            if (empty($session['slot'])) {
                continue;
            }

            $modifier = (int) $session['price_modifier'];
            if ($modifier === 0) {
                continue;
            }

            $items[] = [
                'id' => $sessionId,
                'type' => 'session_modifier',
                'title' => 'Sessie: ' . ($session['title'] ?? ''),
                'quantity' => 1,
                'unit_price' => $modifier,
                'total' => $modifier,
            ];
        }

        return $items;
    }

    /**
     * Replace session_modifier items in existing quote items.
     *
     * Strips all old session_modifier items and appends the new ones.
     * Non-modifier items (edition, etc.) are preserved in original order.
     *
     * @param array[] $existingItems Current quote items
     * @param array[] $newModifiers  New modifier items to append
     * @return array[] Updated items list
     */
    private function replaceModifierItems(array $existingItems, array $newModifiers): array
    {
        $kept = array_filter($existingItems, fn(array $item) => ($item['type'] ?? 'edition') !== 'session_modifier');

        return array_values(array_merge($kept, $newModifiers));
    }

    /**
     * Create a quote for a registration.
     *
     * @param array<array{title: string, quantity: int, unit_price: Money}> $items
     * @param array<string, mixed> $billing
     */
    public function createQuote(
        int $userId,
        int $registrationId,
        int $editionId,
        array $items,
        array $billing = [],
        ?string $voucherCode = null,
        ?Money $discount = null,
    ): int|WP_Error {
        // Calculate totals
        $totals = QuoteCalculator::calculateTotals($items, $discount);

        // Generate quote number
        $quoteNumber = $this->repository->generateQuoteNumber();

        // Get edition title for quote title
        $edition = $this->editions->exists($editionId)
            ? get_post($editionId)
            : null;
        $title = $edition ? $edition->post_title : "Offerte {$quoteNumber}";

        // Format items for storage
        $storedItems = QuoteCalculator::formatItemsForStorage($items);

        // Create quote
        $result = $this->repository->create([
            'title' => $title,
            'user_id' => $userId,
            'registration_id' => $registrationId,
            'edition_id' => $editionId,
            'quote_number' => $quoteNumber,
            'status' => QuoteStatus::Draft->value,
            'items' => $storedItems,
            'subtotal' => $totals['subtotal']->inCents(),
            'discount' => $totals['discount']->inCents(),
            'tax' => $totals['tax']->inCents(),
            'total' => $totals['total']->inCents(),
            'billing' => $billing,
            'voucher_code' => $voucherCode,
            'valid_until' => date('Y-m-d', strtotime('+30 days')),
        ]);

        if (is_wp_error($result)) {
            ntdst_log('invoicing')->error('Quote creation failed', [
                'user_id' => $userId,
                'registration_id' => $registrationId,
                'edition_id' => $editionId,
                'error' => $result->get_error_message(),
            ]);
            return $result;
        }

        $quoteId = $result->ID;

        ntdst_log('invoicing')->info('Quote created', [
            'quote_id' => $quoteId,
            'quote_number' => $quoteNumber,
            'user_id' => $userId,
            'registration_id' => $registrationId,
            'total' => $totals['total']->inCents(),
        ]);

        // Fire event
        $this->dispatch('quote/created', [
            'quote_id' => $quoteId,
            'user_id' => $userId,
            'registration_id' => $registrationId,
            'edition_id' => $editionId,
            'total' => $totals['total']->inCents(),
        ]);

        // Auto-generate PDF
        do_action('stride/quote/regenerate_pdf', $quoteId);

        return $quoteId;
    }

    /**
     * Get quote by ID.
     *
     * @param bool $skipCache Set true after mutations to get fresh data
     */
    public function getQuote(int $quoteId, bool $skipCache = false): array|WP_Error
    {
        $result = $this->repository->find($quoteId, $skipCache);

        if (is_wp_error($result)) {
            return $result;
        }

        return $this->hydrateQuote($result);
    }

    /**
     * Get quotes for a user.
     *
     * @return array<array<string, mixed>>
     */
    public function getUserQuotes(int $userId): array
    {
        $quotes = $this->repository->findByUser($userId);

        return array_map([$this, 'hydrateQuote'], $quotes);
    }

    /**
     * Get quote by registration.
     */
    public function getQuoteByRegistration(int $registrationId): ?array
    {
        $quote = $this->repository->findByRegistration($registrationId);

        return $quote ? $this->hydrateQuote($quote) : null;
    }

    /**
     * Mark quote as sent.
     */
    public function markAsSent(int $quoteId): bool|WP_Error
    {
        $quote = $this->repository->find($quoteId);

        if (is_wp_error($quote)) {
            return $quote;
        }

        $status = QuoteStatus::tryFrom($quote->status ?? '');

        if ($status !== QuoteStatus::Draft) {
            ntdst_log('invoicing')->warning('Cannot send: invalid status', [
                'quote_id' => $quoteId,
                'current_status' => $status?->value ?? 'unknown',
            ]);
            return new WP_Error('invalid_status', 'Only draft quotes can be sent');
        }

        $result = $this->repository->updateStatus($quoteId, QuoteStatus::Sent);

        if ($result) {
            ntdst_log('invoicing')->info('Quote marked as sent', [
                'quote_id' => $quoteId,
            ]);
            $this->dispatch('quote/sent', ['quote_id' => $quoteId]);
        }

        return $result;
    }

    /**
     * Set a quote's `locked` flag.
     *
     * Used as the building block for bulk locking from the edition admin.
     * Idempotent: silently no-ops when the requested state is already set.
     */
    public function setLocked(int $quoteId, bool $locked): bool
    {
        $current = (bool) $this->repository->getField($quoteId, 'locked', false);
        if ($current === $locked) {
            return true;
        }

        $result = $this->repository->updateMeta($quoteId, ['locked' => $locked]);

        if ($result) {
            $this->dispatch($locked ? 'quote/locked' : 'quote/unlocked', [
                'quote_id' => $quoteId,
            ]);
        }

        return $result;
    }

    /**
     * Bulk lock or unlock every quote linked to an edition.
     *
     * Returns a summary of what changed: how many quotes were inspected,
     * how many actually flipped, and how many were already in the target
     * state. The caller (admin AJAX handler) renders this as a status line.
     *
     * @return array{total:int, changed:int, unchanged:int}
     */
    public function bulkSetLockedByEdition(int $editionId, bool $locked): array
    {
        $quotes = $this->repository->findByEdition($editionId);

        $changed = 0;
        foreach ($quotes as $quote) {
            // Repository returns meta nested under 'meta' key; setLocked() will
            // re-read authoritatively so we don't depend on the in-memory shape.
            $quoteId = (int) ($quote['id'] ?? 0);
            if ($quoteId === 0) {
                continue;
            }
            $current = (bool) $this->repository->getField($quoteId, 'locked', false);
            if ($current === $locked) {
                continue;
            }
            $this->setLocked($quoteId, $locked);
            $changed++;
        }

        ntdst_log('invoicing')->info('Bulk lock applied to edition quotes', [
            'edition_id' => $editionId,
            'locked' => $locked,
            'total' => count($quotes),
            'changed' => $changed,
        ]);

        $this->dispatch('quote/bulk_locked', [
            'edition_id' => $editionId,
            'locked' => $locked,
            'changed' => $changed,
        ]);

        return [
            'total' => count($quotes),
            'changed' => $changed,
            'unchanged' => count($quotes) - $changed,
        ];
    }

    /**
     * Cancel quote.
     */
    public function cancel(int $quoteId): bool|WP_Error
    {
        $quote = $this->repository->find($quoteId);

        if (is_wp_error($quote)) {
            return $quote;
        }

        $status = QuoteStatus::tryFrom($quote->status ?? '');

        if ($status === QuoteStatus::Exported) {
            ntdst_log('invoicing')->warning('Cannot cancel: quote exported', [
                'quote_id' => $quoteId,
            ]);
            return new WP_Error('cannot_cancel', 'Exported quotes cannot be cancelled');
        }

        $result = $this->repository->updateStatus($quoteId, QuoteStatus::Cancelled);

        if ($result) {
            ntdst_log('invoicing')->info('Quote cancelled', [
                'quote_id' => $quoteId,
            ]);
            $this->dispatch('quote/cancelled', ['quote_id' => $quoteId]);
        }

        return $result;
    }

    /**
     * Apply a voucher code to a draft quote.
     */
    public function applyVoucher(int $quoteId, string $voucherCode): bool|WP_Error
    {
        $quote = $this->repository->find($quoteId);

        if (is_wp_error($quote)) {
            return $quote;
        }

        // Get meta from dynamic property on WP_Post
        $meta = $quote->meta ?? [];
        $status = QuoteStatus::tryFrom($meta['status'] ?? '');

        if ($status !== QuoteStatus::Draft) {
            ntdst_log('invoicing')->warning('Voucher application failed: invalid status', [
                'quote_id' => $quoteId,
                'current_status' => $status?->value ?? 'unknown',
            ]);
            return new WP_Error('invalid_status', 'Alleen concept-offertes kunnen worden aangepast');
        }

        // Validate and get voucher through VoucherService
        $voucherService = $this->voucherService;
        $editionId = (int) ($meta['edition_id'] ?? 0);
        $voucher = $voucherService->validateVoucher($voucherCode, $editionId);

        if (is_wp_error($voucher)) {
            ntdst_log('invoicing')->error('Voucher application failed', [
                'quote_id' => $quoteId,
                'voucher_code' => $voucherCode,
                'error' => $voucher->get_error_message(),
            ]);
            return $voucher;
        }

        // Calculate discount
        $subtotalCents = (int) ($meta['subtotal'] ?? 0);
        $subtotal = Money::cents($subtotalCents);
        $discount = $voucherService->calculateDiscount($voucher, $subtotal, $editionId > 0 ? $editionId : null);

        // Recalculate totals
        $newSubtotal = $subtotal;
        $newDiscount = $discount;
        $newTax = Money::cents((int) round(($newSubtotal->inCents() - $newDiscount->inCents()) * 0.21));
        $newTotal = Money::cents($newSubtotal->inCents() - $newDiscount->inCents() + $newTax->inCents());

        // Update quote
        $result = $this->repository->updateMeta($quoteId, [
            'voucher_code' => $voucherCode,
            'discount' => $newDiscount->inCents(),
            'tax' => $newTax->inCents(),
            'total' => $newTotal->inCents(),
        ]);

        if (!$result) {
            ntdst_log('invoicing')->error('Voucher application failed', [
                'quote_id' => $quoteId,
                'voucher_code' => $voucherCode,
                'error' => 'Kon offerte niet bijwerken',
            ]);
            return new WP_Error('update_failed', 'Kon offerte niet bijwerken');
        }

        // Redeem the voucher
        $userId = (int) ($meta['user_id'] ?? 0);
        $redemption = $voucherService->redeemVoucher($voucherCode, $userId, $quoteId);

        if (is_wp_error($redemption)) {
            ntdst_log('invoicing')->error('Voucher application failed', [
                'quote_id' => $quoteId,
                'voucher_code' => $voucherCode,
                'error' => $redemption->get_error_message(),
            ]);
            return $redemption;
        }

        ntdst_log('invoicing')->info('Voucher applied to quote', [
            'quote_id' => $quoteId,
            'voucher_code' => $voucherCode,
            'discount' => $newDiscount->inCents(),
        ]);

        $this->dispatch('quote/voucher_applied', [
            'quote_id' => $quoteId,
            'voucher_code' => $voucherCode,
            'discount' => $newDiscount->inCents(),
        ]);

        return true;
    }

    /**
     * Hydrate quote data with Money objects.
     *
     * @param array<string, mixed>|WP_Post $quote
     * @return array<string, mixed>
     */
    private function hydrateQuote(array|WP_Post $quote): array
    {
        // Handle WP_Post with dynamically added meta/fields properties
        if ($quote instanceof WP_Post) {
            $data = (array) $quote;

            // Access dynamic properties directly from the object
            // Prefer 'fields' (formatted/unprefixed) over 'meta' (raw/prefixed)
            if (isset($quote->fields) && is_array($quote->fields)) {
                $data = array_merge($data, $quote->fields);
            } elseif (isset($quote->meta) && is_array($quote->meta)) {
                $data = array_merge($data, $quote->meta);
            }
        } else {
            $data = $quote;

            // Flatten fields to top level if present (NTDST_Data_Model returns formatted fields)
            // Prefer 'fields' (formatted/unprefixed) over 'meta' for legacy batch query results
            if (isset($data['fields']) && is_array($data['fields'])) {
                $data = array_merge($data, $data['fields']);
            } elseif (isset($data['meta']) && is_array($data['meta'])) {
                $data = array_merge($data, $data['meta']);
            }
        }

        // Convert cents to Money objects
        $data['subtotal_money'] = Money::cents((int) ($data['subtotal'] ?? 0));
        $data['discount_money'] = Money::cents((int) ($data['discount'] ?? 0));
        $data['tax_money'] = Money::cents((int) ($data['tax'] ?? 0));
        $data['total_money'] = Money::cents((int) ($data['total'] ?? 0));

        // Parse status
        $data['status_enum'] = QuoteStatus::tryFrom($data['status'] ?? '') ?? QuoteStatus::Draft;

        return $data;
    }
}
