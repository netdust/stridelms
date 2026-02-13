<?php

namespace ntdst\Stride\invoicing\Helpers;

defined('ABSPATH') || exit;

use ntdst\Stride\invoicing\QuoteService;
use ntdst\Stride\invoicing\Support\CurrencyFormatter;

/**
 * Quote Audit Logger
 *
 * Manages audit trail and notes for quotes.
 * Provides consistent logging across all quote operations.
 *
 * This is a stateless helper class - instantiated where needed.
 *
 * @package stride\services\invoicing\Helpers
 */
class QuoteAuditLogger
{
    // Note types
    public const TYPE_ADMIN = 'admin';
    public const TYPE_CUSTOMER = 'customer';
    public const TYPE_SYSTEM = 'system';

    /**
     * Add a note to a quote
     *
     * @param int $quoteId Quote post ID
     * @param string $message Note message
     * @param string $type Note type (admin, customer, system)
     * @param string|null $author Author name (auto-detected if null)
     * @return bool Success
     */
    public function addNote(int $quoteId, string $message, string $type = self::TYPE_ADMIN, ?string $author = null): bool
    {
        $model = $this->getModel();
        if (!$model) {
            return false;
        }

        $post = $model->find($quoteId);
        if (!$post) {
            return false;
        }

        $notes = $post->fields[QuoteService::FIELD_NOTES] ?? [];

        // Determine author
        if ($author === null) {
            if ($type === self::TYPE_SYSTEM) {
                $author = 'System';
            } else {
                $currentUser = wp_get_current_user();
                $author = $currentUser->display_name ?: 'Unknown';
            }
        }

        $notes[] = [
            'type' => $type,
            'content' => $message,
            'author' => $author,
            'date' => current_time('mysql'),
        ];

        $result = $model->update($quoteId, [
            QuoteService::FIELD_NOTES => $notes,
        ]);

        return !is_wp_error($result);
    }

    /**
     * Add admin note
     *
     * @param int $quoteId Quote post ID
     * @param string $message Note message
     * @return bool Success
     */
    public function addAdminNote(int $quoteId, string $message): bool
    {
        return $this->addNote($quoteId, $message, self::TYPE_ADMIN);
    }

    /**
     * Add customer-visible note
     *
     * @param int $quoteId Quote post ID
     * @param string $message Note message
     * @return bool Success
     */
    public function addCustomerNote(int $quoteId, string $message): bool
    {
        return $this->addNote($quoteId, $message, self::TYPE_CUSTOMER);
    }

    /**
     * Add system note (automated actions)
     *
     * @param int $quoteId Quote post ID
     * @param string $message Note message
     * @return bool Success
     */
    public function addSystemNote(int $quoteId, string $message): bool
    {
        return $this->addNote($quoteId, $message, self::TYPE_SYSTEM, 'System');
    }

    /**
     * Get all notes for a quote
     *
     * @param int $quoteId Quote post ID
     * @param string|null $type Filter by type
     * @return array Notes array
     */
    public function getNotes(int $quoteId, ?string $type = null): array
    {
        $model = $this->getModel();
        if (!$model) {
            return [];
        }

        $post = $model->find($quoteId);
        if (!$post) {
            return [];
        }

        $notes = $post->fields[QuoteService::FIELD_NOTES] ?? [];

        if ($type !== null) {
            $notes = array_filter($notes, fn($note) => ($note['type'] ?? '') === $type);
        }

        return array_values($notes);
    }

    /**
     * Get customer-visible notes only
     *
     * @param int $quoteId Quote post ID
     * @return array Notes array
     */
    public function getCustomerNotes(int $quoteId): array
    {
        return $this->getNotes($quoteId, self::TYPE_CUSTOMER);
    }

    /**
     * Update notes array (replace all notes)
     *
     * @param int $quoteId Quote post ID
     * @param array $notes Notes array
     * @return bool Success
     */
    public function updateNotes(int $quoteId, array $notes): bool
    {
        $model = $this->getModel();
        if (!$model) {
            return false;
        }

        // Sanitize notes
        $cleanNotes = [];
        foreach ($notes as $note) {
            if (!empty($note['_deleted'])) {
                continue;
            }

            $cleanNotes[] = [
                'type' => sanitize_text_field($note['type'] ?? self::TYPE_ADMIN),
                'content' => sanitize_textarea_field($note['content'] ?? ''),
                'author' => sanitize_text_field($note['author'] ?? ''),
                'date' => sanitize_text_field($note['date'] ?? current_time('mysql')),
            ];
        }

        $result = $model->update($quoteId, [
            QuoteService::FIELD_NOTES => $cleanNotes,
        ]);

        return !is_wp_error($result);
    }

    /**
     * Delete a note by index
     *
     * @param int $quoteId Quote post ID
     * @param int $index Note index
     * @return bool Success
     */
    public function deleteNote(int $quoteId, int $index): bool
    {
        $notes = $this->getNotes($quoteId);

        if (!isset($notes[$index])) {
            return false;
        }

        array_splice($notes, $index, 1);

        return $this->updateNotes($quoteId, $notes);
    }

    /**
     * Log status change
     *
     * @param int $quoteId Quote post ID
     * @param string $oldStatus Previous status
     * @param string $newStatus New status
     */
    public function logStatusChange(int $quoteId, string $oldStatus, string $newStatus): void
    {
        $this->addSystemNote($quoteId, sprintf(
            __('Status gewijzigd: %s → %s', 'stride'),
            $this->getStatusLabel($oldStatus),
            $this->getStatusLabel($newStatus)
        ));
    }

    /**
     * Log lock/unlock action
     *
     * @param int $quoteId Quote post ID
     * @param bool $locked Whether quote is now locked
     */
    public function logLockAction(int $quoteId, bool $locked): void
    {
        $message = $locked
            ? __('Offerte vergrendeld', 'stride')
            : __('Offerte ontgrendeld', 'stride');

        $this->addAdminNote($quoteId, $message);
    }

    /**
     * Log email sent
     *
     * @param int $quoteId Quote post ID
     * @param string $recipients Email recipients
     */
    public function logEmailSent(int $quoteId, string $recipients): void
    {
        $this->addSystemNote($quoteId, sprintf(
            __('Offerte verzonden naar: %s', 'stride'),
            $recipients
        ));
    }

    /**
     * Log voucher applied
     *
     * @param int $quoteId Quote post ID
     * @param string $voucherCode Voucher code
     * @param float $discount Discount amount
     */
    public function logVoucherApplied(int $quoteId, string $voucherCode, float $discount): void
    {
        $this->addAdminNote($quoteId, sprintf(
            __('Voucher toegepast: %s (-%s)', 'stride'),
            $voucherCode,
            $this->formatCurrency($discount)
        ));
    }

    /**
     * Log manual discount applied
     *
     * @param int $quoteId Quote post ID
     * @param float $discount Discount amount
     */
    public function logManualDiscount(int $quoteId, float $discount): void
    {
        $this->addAdminNote($quoteId, sprintf(
            __('Handmatige korting toegepast: -%s', 'stride'),
            $this->formatCurrency($discount)
        ));
    }

    /**
     * Log discount removed
     *
     * @param int $quoteId Quote post ID
     */
    public function logDiscountRemoved(int $quoteId): void
    {
        $this->addAdminNote($quoteId, __('Korting verwijderd', 'stride'));
    }

    /**
     * Log PDF regenerated
     *
     * @param int $quoteId Quote post ID
     */
    public function logPdfRegenerated(int $quoteId): void
    {
        $this->addAdminNote($quoteId, __('PDF opnieuw gegenereerd', 'stride'));
    }

    /**
     * Get status label
     *
     * @param string $status Status code
     * @return string Translated label
     */
    private function getStatusLabel(string $status): string
    {
        return match ($status) {
            QuoteService::STATUS_DRAFT => __('Concept', 'stride'),
            QuoteService::STATUS_SENT => __('Verzonden', 'stride'),
            QuoteService::STATUS_EXPORTED => __('Geëxporteerd', 'stride'),
            default => $status,
        };
    }

    /**
     * Format currency
     *
     * @param float $amount Amount
     * @return string Formatted amount
     */
    private function formatCurrency(float $amount): string
    {
        return CurrencyFormatter::format($amount);
    }

    /**
     * Get DataManager model
     *
     * @return \NTDST_Data_Model|null
     */
    private function getModel(): ?\NTDST_Data_Model
    {
        if (!function_exists('ntdst_data')) {
            return null;
        }

        return ntdst_data()->model(QuoteService::POST_TYPE);
    }
}
