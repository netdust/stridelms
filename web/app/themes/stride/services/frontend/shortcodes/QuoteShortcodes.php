<?php

namespace stride\services\frontend\shortcodes;

defined('ABSPATH') || exit;

use stride\services\frontend\DashboardService;

/**
 * Quote-related shortcodes.
 *
 * - [stride_my_quotes] - User's quotes list
 * - [stride_quote_update] - Quote update form
 */
final class QuoteShortcodes
{
    private ?DashboardService $dashboardService;

    public function __construct(?DashboardService $dashboardService = null)
    {
        $this->dashboardService = $dashboardService ?? $this->resolveService(DashboardService::class);
    }

    /**
     * Register shortcodes
     */
    public function register(): void
    {
        add_shortcode('stride_my_quotes', [$this, 'renderMyQuotes']);
        add_shortcode('stride_quote_update', [$this, 'renderQuoteUpdateForm']);
    }

    /**
     * Resolve service from DI container
     */
    private function resolveService(string $class): ?object
    {
        if (function_exists('ntdst_get')) {
            try {
                return ntdst_get($class);
            } catch (\Exception $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * Get template path
     */
    private function getTemplatePath(string $template): string
    {
        return get_stylesheet_directory() . '/templates/' . $template;
    }

    /**
     * Render a template with data
     */
    private function renderTemplate(string $template, array $data = []): string
    {
        $templatePath = $this->getTemplatePath($template);

        if (!file_exists($templatePath)) {
            if (current_user_can('manage_options')) {
                return '<div class="uk-alert uk-alert-warning">Template not found: ' . esc_html($template) . '</div>';
            }
            return '';
        }

        // Extract data for template access
        extract($data, EXTR_SKIP);

        ob_start();
        include $templatePath;
        return ob_get_clean();
    }

    /**
     * Check if user is logged in and redirect/show message if not
     */
    private function requireLogin(): ?string
    {
        if (is_user_logged_in()) {
            return null;
        }

        return $this->renderTemplate('dashboard/login-required.php', [
            'login_url' => wp_login_url(get_permalink()),
            'register_url' => wp_registration_url(),
        ]);
    }

    // ========================================
    // SHORTCODE HANDLERS
    // ========================================

    /**
     * [stride_my_quotes] - User's quotes
     */
    public function renderMyQuotes(array $atts = []): string
    {
        $loginRequired = $this->requireLogin();
        if ($loginRequired !== null) {
            return $loginRequired;
        }

        $userId = get_current_user_id();

        $data = [
            'user_id' => $userId,
            'quotes' => $this->dashboardService->getUserQuotes($userId),
            'dashboard_service' => $this->dashboardService,
        ];

        return $this->renderTemplate('dashboard/quotes.php', $data);
    }

    /**
     * [stride_quote_update quote="123"] - Quote update form
     */
    public function renderQuoteUpdateForm(array $atts = []): string
    {
        $loginRequired = $this->requireLogin();
        if ($loginRequired !== null) {
            return $loginRequired;
        }

        $atts = shortcode_atts(['quote' => 0], $atts);
        $quoteId = (int) ($atts['quote'] ?: ($_GET['quote'] ?? 0));

        if (!$quoteId) {
            return '<div class="uk-alert uk-alert-warning">' . esc_html__('Geen offerte opgegeven.', 'stride') . '</div>';
        }

        $quoteService = $this->resolveService(\Stride\Modules\Invoicing\QuoteService::class);
        if (!$quoteService) {
            return '<div class="uk-alert uk-alert-danger">' . esc_html__('Service niet beschikbaar.', 'stride') . '</div>';
        }

        $quote = $quoteService->getQuote($quoteId);
        if (is_wp_error($quote)) {
            return '<div class="uk-alert uk-alert-warning">' . esc_html__('Offerte niet gevonden.', 'stride') . '</div>';
        }

        // Verify ownership
        $userId = get_current_user_id();
        if ((int) ($quote['user_id'] ?? 0) !== $userId) {
            return '<div class="uk-alert uk-alert-danger">' . esc_html__('Je hebt geen toegang tot deze offerte.', 'stride') . '</div>';
        }

        // Only draft quotes can be updated
        $status = $quote['status_enum'] ?? $quote['status'] ?? '';
        if ($status !== 'draft' && $status !== \Stride\Domain\QuoteStatus::Draft) {
            return '<div class="uk-alert uk-alert-warning">' . esc_html__('Deze offerte kan niet meer worden bijgewerkt.', 'stride') . '</div>';
        }

        $data = [
            'quote_id' => $quoteId,
            'quote' => $quote,
            'user' => wp_get_current_user(),
            'nonce' => wp_create_nonce('stride_quote_update'),
            'ajax_url' => admin_url('admin-ajax.php'),
        ];

        return $this->renderTemplate('quote/update-form.php', $data);
    }
}
