<?php

namespace ntdst\Stride\invoicing\Helpers;

defined('ABSPATH') || exit;

use ntdst\Stride\invoicing\QuoteService;
use ntdst\Stride\invoicing\QuotePDFGenerator;
use ntdst\Stride\invoicing\Support\QuoteConfig;
use ntdst\Stride\invoicing\Support\CurrencyFormatter;
use WP_Error;

/**
 * Quote Email Service
 *
 * Handles email sending for quotes.
 * Coordinates with PDF generator and audit logger.
 *
 * This is a helper class - instantiated where needed, no hook registration.
 *
 * @package stride\services\invoicing\Helpers
 */
class QuoteEmailService
{
    private QuoteAuditLogger $auditLogger;
    private ?QuotePDFGenerator $pdfGenerator;

    /**
     * Constructor
     */
    public function __construct(
        ?QuoteAuditLogger $auditLogger = null,
        ?QuotePDFGenerator $pdfGenerator = null
    ) {
        $this->auditLogger = $auditLogger ?? new QuoteAuditLogger();
        $this->pdfGenerator = $pdfGenerator ?? $this->resolveService(QuotePDFGenerator::class);
    }

    /**
     * Send quote email to customer
     *
     * @param int $quoteId Quote post ID
     * @param string $to Recipient email
     * @param string $cc CC email (optional)
     * @return true|WP_Error
     */
    public function send(int $quoteId, string $to, string $cc = ''): true|WP_Error
    {
        $model = $this->getModel();
        if (!$model) {
            return new WP_Error('no_model', __('DataManager niet beschikbaar.', 'stride'));
        }

        $post = $model->find($quoteId);
        if (!$post) {
            return new WP_Error('quote_not_found', __('Offerte niet gevonden.', 'stride'));
        }

        $quote = $this->formatQuoteData($post);

        // Ensure PDF exists
        if (empty($quote['pdf_path']) || !file_exists($quote['pdf_path'])) {
            // Try to generate PDF
            if ($this->pdfGenerator) {
                $pdfResult = $this->pdfGenerator->generate($quoteId);
                if (is_wp_error($pdfResult)) {
                    return new WP_Error('pdf_failed', __('PDF kon niet worden gegenereerd.', 'stride'));
                }
                // Refresh quote data
                $post = $model->find($quoteId);
                $quote = $this->formatQuoteData($post);
            }
        }

        // Build email
        $emailSettings = QuoteConfig::getEmailSettings();
        $subject = sprintf('%s %s', $emailSettings['subject'], $quote['number']);

        // Get email body
        $body = $this->buildEmailBody($quote);

        // Build headers
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            sprintf('From: %s <%s>', $emailSettings['from_name'], $emailSettings['from_email']),
        ];

        if (!empty($cc) && is_email($cc)) {
            $headers[] = sprintf('Cc: %s', $cc);
        }

        // Attachments
        $attachments = [];
        if (!empty($quote['pdf_path']) && file_exists($quote['pdf_path'])) {
            $attachments[] = $quote['pdf_path'];
        }

        // Send email
        $sent = wp_mail($to, $subject, $body, $headers, $attachments);

        if (!$sent) {
            return new WP_Error('email_failed', __('E-mail kon niet worden verzonden.', 'stride'));
        }

        // Update quote status and metadata
        $recipients = $to;
        if ($cc) {
            $recipients .= ', ' . $cc;
        }

        $updateData = [
            QuoteService::FIELD_LAST_SENT_TO => $recipients,
        ];

        // Update status to sent if still draft
        if ($quote['status'] === QuoteService::STATUS_DRAFT) {
            $updateData[QuoteService::FIELD_STATUS] = QuoteService::STATUS_SENT;
            $updateData[QuoteService::FIELD_SENT_AT] = current_time('mysql');
        }

        $model->update($quoteId, $updateData);

        // Log the action
        $this->auditLogger->logEmailSent($quoteId, $recipients);

        // Fire hook
        do_action('stride/quote/sent', $quoteId, $quote);

        return true;
    }

    /**
     * Build email HTML body
     *
     * @param array $quote Quote data
     * @return string HTML email body
     */
    private function buildEmailBody(array $quote): string
    {
        $company = QuoteConfig::getCompanyDetails();
        $billing = $quote['billing'] ?? [];

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { margin-bottom: 30px; }
                .details { margin-bottom: 20px; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2><?php echo esc_html(sprintf(__('Offerte %s', 'stride'), $quote['number'])); ?></h2>
                </div>

                <div class="details">
                    <p><?php echo esc_html(sprintf(
                        __('Beste %s,', 'stride'),
                        $billing['organisation'] ?: $billing['email'] ?? ''
                    )); ?></p>

                    <p><?php esc_html_e('Hierbij ontvangt u onze offerte in bijlage.', 'stride'); ?></p>

                    <p>
                        <strong><?php esc_html_e('Offerte nummer:', 'stride'); ?></strong> <?php echo esc_html($quote['number']); ?><br>
                        <strong><?php esc_html_e('Totaal bedrag:', 'stride'); ?></strong> <?php echo esc_html(CurrencyFormatter::format($quote['total'], 'EUR', false)); ?><br>
                        <strong><?php esc_html_e('Geldig tot:', 'stride'); ?></strong> <?php echo esc_html(date_i18n('j F Y', strtotime($quote['valid_until']))); ?>
                    </p>

                    <p><?php esc_html_e('Heeft u vragen? Neem gerust contact met ons op.', 'stride'); ?></p>
                </div>

                <div class="footer">
                    <p>
                        <?php echo esc_html($company['name']); ?><br>
                        <?php if (!empty($company['address'])): ?>
                            <?php echo esc_html($company['address']); ?><br>
                        <?php endif; ?>
                        <?php if (!empty($company['postal_code']) || !empty($company['city'])): ?>
                            <?php echo esc_html(trim($company['postal_code'] . ' ' . $company['city'])); ?><br>
                        <?php endif; ?>
                        <?php if (!empty($company['email'])): ?>
                            <?php echo esc_html($company['email']); ?><br>
                        <?php endif; ?>
                        <?php if (!empty($company['phone'])): ?>
                            <?php echo esc_html($company['phone']); ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Format quote data from post
     *
     * @param object $post Post object with fields
     * @return array Quote data
     */
    private function formatQuoteData(object $post): array
    {
        return [
            'id' => $post->ID,
            'number' => $post->fields[QuoteService::FIELD_QUOTE_NUMBER] ?? '',
            'status' => $post->fields[QuoteService::FIELD_STATUS] ?? QuoteService::STATUS_DRAFT,
            'total' => (float) ($post->fields[QuoteService::FIELD_TOTAL] ?? 0),
            'valid_until' => $post->fields[QuoteService::FIELD_VALID_UNTIL] ?? '',
            'billing' => $post->fields[QuoteService::FIELD_BILLING] ?? [],
            'pdf_path' => $post->fields[QuoteService::FIELD_PDF_PATH] ?? '',
        ];
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
                // Fall through
            }
        }

        if (class_exists($class)) {
            return new $class();
        }

        return null;
    }
}
