<?php

declare(strict_types=1);

namespace Stride\Modules\User;

use Stride\Modules\Invoicing\QuoteService;

/**
 * Dashboard shortcode for displaying user quotes.
 */
final class QuotesShortcode
{
    public function __construct(
        private readonly QuoteService $quotes,
    ) {
        add_shortcode('stride_my_quotes', [$this, 'renderMyQuotes']);
    }

    /**
     * Render user's quotes.
     */
    public function renderMyQuotes(array $atts = []): string
    {
        if (!is_user_logged_in()) {
            return '<p>Je moet ingelogd zijn om je offertes te zien.</p>';
        }

        $userId = get_current_user_id();
        $quotes = $this->quotes->getUserQuotes($userId);

        if (empty($quotes)) {
            return '<div class="uk-alert uk-alert-primary">Je hebt nog geen offertes.</div>';
        }

        $output = '<div class="uk-overflow-auto"><table class="uk-table uk-table-divider uk-table-small">';
        $output .= '<thead><tr>';
        $output .= '<th>Nummer</th><th>Cursus</th><th>Totaal</th><th>Status</th><th></th>';
        $output .= '</tr></thead><tbody>';

        foreach ($quotes as $quote) {
            $statusClass = match ($quote['status']) {
                'sent' => 'uk-label-success',
                'exported' => 'uk-label-primary',
                'cancelled' => 'uk-label-danger',
                default => 'uk-label-warning',
            };

            $output .= sprintf(
                '<tr>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td><span class="uk-label %s">%s</span></td>
                    <td><a href="%s" class="uk-button uk-button-small uk-button-default">Bekijk</a></td>
                </tr>',
                esc_html($quote['quote_number'] ?? ''),
                esc_html($quote['post_title'] ?? ''),
                esc_html($quote['total_money']->format()),
                $statusClass,
                esc_html($quote['status_enum']->label()),
                esc_url(add_query_arg('quote_id', $quote['id'] ?? $quote['ID'], home_url('/mijn-account/mijn-offertes/')))
            );
        }

        $output .= '</tbody></table></div>';

        return $output;
    }
}
