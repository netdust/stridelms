<?php
/**
 * My Quotes Template
 *
 * User's quotes with tabs: Openstaand, Betaald, Alle.
 * Displays quote cards with amount, status, and actions.
 *
 * @package stride
 */

defined('ABSPATH') || exit;

use Stride\Domain\QuoteStatus;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Edition\EditionService;

// Services - lazy loaded from DI container
$quoteService = ntdst_get(QuoteService::class);
$editionService = ntdst_get(EditionService::class);

// Current user
$user = wp_get_current_user();
$userId = $user->ID;

// Get user quotes
$allQuotes = $quoteService->getUserQuotes($userId);

// Sort quotes by date (most recent first)
usort($allQuotes, function ($a, $b) {
    $dateA = $a['post_date'] ?? '0';
    $dateB = $b['post_date'] ?? '0';
    return strcmp($dateB, $dateA);
});

// Categorize quotes
$pendingQuotes = [];
$paidQuotes = [];

foreach ($allQuotes as $quote) {
    $status = $quote['status_enum'] ?? QuoteStatus::Draft;

    // Pending: Draft or Sent (awaiting payment)
    if (in_array($status, [QuoteStatus::Draft, QuoteStatus::Sent], true)) {
        $pendingQuotes[] = $quote;
    }

    // Paid: Exported (sent to accounting = paid)
    if ($status === QuoteStatus::Exported) {
        $paidQuotes[] = $quote;
    }
}

// Stats
$totalCount = count($allQuotes);
$pendingCount = count($pendingQuotes);
$paidCount = count($paidQuotes);
?>

<div class="stride-my-quotes">
    <!-- Page Header -->
    <header class="stride-page-header">
        <div class="stride-page-header__content">
            <a href="<?php echo esc_url(home_url('/mijn-account/')); ?>" class="stride-page-header__back">
                <span uk-icon="icon: arrow-left; ratio: 0.8"></span>
                <?php esc_html_e('Dashboard', 'stride'); ?>
            </a>
            <h1 class="stride-page-header__title"><?php esc_html_e('Mijn offertes', 'stride'); ?></h1>
            <p class="stride-page-header__subtitle">
                <?php
                if ($totalCount > 0) {
                    printf(
                        esc_html(_n(
                            '%d offerte',
                            '%d offertes',
                            $totalCount,
                            'stride'
                        )),
                        $totalCount
                    );
                } else {
                    esc_html_e('Je hebt nog geen offertes.', 'stride');
                }
                ?>
            </p>
        </div>
    </header>

    <?php if ($totalCount > 0) : ?>
        <!-- Tabs -->
        <ul class="uk-subnav uk-subnav-pill stride-tabs" uk-switcher="animation: uk-animation-fade">
            <li class="uk-active">
                <a href="#">
                    <?php esc_html_e('Openstaand', 'stride'); ?>
                    <span class="stride-tabs__count"><?php echo esc_html($pendingCount); ?></span>
                </a>
            </li>
            <li>
                <a href="#">
                    <?php esc_html_e('Betaald', 'stride'); ?>
                    <span class="stride-tabs__count"><?php echo esc_html($paidCount); ?></span>
                </a>
            </li>
            <li>
                <a href="#">
                    <?php esc_html_e('Alle', 'stride'); ?>
                    <span class="stride-tabs__count"><?php echo esc_html($totalCount); ?></span>
                </a>
            </li>
        </ul>

        <!-- Tab Content -->
        <ul class="uk-switcher uk-margin-medium-top">
            <!-- Pending Quotes Tab -->
            <li>
                <?php if (!empty($pendingQuotes)) : ?>
                    <div class="stride-quotes-list">
                        <?php foreach ($pendingQuotes as $quote) : ?>
                            <?php stride_render_quote_card($quote, $editionService); ?>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <div class="stride-empty-state stride-empty-state--compact">
                        <div class="stride-empty-state__icon">
                            <span uk-icon="icon: check; ratio: 1.5"></span>
                        </div>
                        <h3 class="stride-empty-state__title"><?php esc_html_e('Geen openstaande offertes', 'stride'); ?></h3>
                        <p class="stride-empty-state__description">
                            <?php esc_html_e('Je hebt momenteel geen openstaande offertes.', 'stride'); ?>
                        </p>
                    </div>
                <?php endif; ?>
            </li>

            <!-- Paid Quotes Tab -->
            <li>
                <?php if (!empty($paidQuotes)) : ?>
                    <div class="stride-quotes-list">
                        <?php foreach ($paidQuotes as $quote) : ?>
                            <?php stride_render_quote_card($quote, $editionService); ?>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <div class="stride-empty-state stride-empty-state--compact">
                        <div class="stride-empty-state__icon">
                            <span uk-icon="icon: credit-card; ratio: 1.5"></span>
                        </div>
                        <h3 class="stride-empty-state__title"><?php esc_html_e('Nog geen betaalde offertes', 'stride'); ?></h3>
                        <p class="stride-empty-state__description">
                            <?php esc_html_e('Je betaalde offertes verschijnen hier.', 'stride'); ?>
                        </p>
                    </div>
                <?php endif; ?>
            </li>

            <!-- All Quotes Tab -->
            <li>
                <div class="stride-quotes-list">
                    <?php foreach ($allQuotes as $quote) : ?>
                        <?php stride_render_quote_card($quote, $editionService); ?>
                    <?php endforeach; ?>
                </div>
            </li>
        </ul>

    <?php else : ?>
        <!-- Empty State -->
        <section class="stride-empty-state uk-margin-large-top">
            <div class="stride-empty-state__icon">
                <span uk-icon="icon: file-text; ratio: 2"></span>
            </div>
            <h2 class="stride-empty-state__title"><?php esc_html_e('Nog geen offertes', 'stride'); ?></h2>
            <p class="stride-empty-state__description">
                <?php esc_html_e('Wanneer je je inschrijft voor een cursus ontvang je hier je offerte.', 'stride'); ?>
            </p>
            <div class="stride-empty-state__action">
                <a href="<?php echo esc_url(home_url('/cursussen/')); ?>" class="uk-button uk-button-primary uk-button-large">
                    <?php esc_html_e('Ontdek cursussen', 'stride'); ?>
                </a>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php
/**
 * Render a quote card
 *
 * @param array $quote Quote data from QuoteService
 * @param EditionService $editionService Edition service instance
 */
function stride_render_quote_card(array $quote, EditionService $editionService): void
{
    $quoteId = (int) ($quote['id'] ?? $quote['ID'] ?? 0);
    $quoteNumber = $quote['quote_number'] ?? sprintf('Q%d', $quoteId);
    $status = $quote['status_enum'] ?? QuoteStatus::Draft;
    $totalMoney = $quote['total_money'] ?? null;
    $total = $totalMoney ? $totalMoney->format() : '€ 0,00';
    $validUntil = $quote['valid_until'] ?? '';
    $editionId = (int) ($quote['edition_id'] ?? 0);

    // Get edition/course name
    $title = $quote['post_title'] ?? '';
    if (empty($title) && $editionId > 0) {
        $edition = $editionService->getEdition($editionId);
        if (!is_wp_error($edition)) {
            $title = $edition->post_title ?? '';
        }
    }
    if (empty($title)) {
        $title = __('Cursusinschrijving', 'stride');
    }

    // Quote date
    $quoteDate = $quote['post_date'] ?? '';
    $formattedDate = $quoteDate ? date_i18n('j M Y', strtotime($quoteDate)) : '';

    // Status badge
    $statusLabel = $status->label();
    $statusClass = stride_get_quote_status_class($status);

    // Quote detail URL (placeholder - can be updated when quote detail page exists)
    $detailUrl = add_query_arg(['quote_id' => $quoteId], home_url('/offerte/'));
    ?>
    <div class="stride-quote-card uk-card uk-card-default">
        <div class="stride-quote-card__body">
            <div class="stride-quote-card__header">
                <div class="stride-quote-card__info">
                    <span class="stride-quote-card__number"><?php echo esc_html($quoteNumber); ?></span>
                    <?php if ($formattedDate) : ?>
                        <span class="stride-quote-card__date"><?php echo esc_html($formattedDate); ?></span>
                    <?php endif; ?>
                </div>
                <span class="uk-label <?php echo esc_attr($statusClass); ?>">
                    <?php echo esc_html($statusLabel); ?>
                </span>
            </div>

            <h3 class="stride-quote-card__title"><?php echo esc_html($title); ?></h3>

            <div class="stride-quote-card__footer">
                <div class="stride-quote-card__amount">
                    <span class="stride-quote-card__amount-label"><?php esc_html_e('Totaal', 'stride'); ?></span>
                    <span class="stride-quote-card__amount-value"><?php echo esc_html($total); ?></span>
                </div>

                <div class="stride-quote-card__actions">
                    <?php if ($status === QuoteStatus::Draft) : ?>
                        <a href="<?php echo esc_url($detailUrl); ?>" class="uk-button uk-button-primary uk-button-small">
                            <?php esc_html_e('Bekijken', 'stride'); ?>
                        </a>
                    <?php else : ?>
                        <a href="<?php echo esc_url($detailUrl); ?>" class="uk-button uk-button-default uk-button-small">
                            <?php esc_html_e('Bekijken', 'stride'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($status->isEditable() && $validUntil) : ?>
                <?php
                $validDate = strtotime($validUntil);
                $isExpired = $validDate && $validDate < time();
                $daysLeft = $validDate ? ceil(($validDate - time()) / DAY_IN_SECONDS) : 0;
                ?>
                <?php if ($isExpired) : ?>
                    <div class="stride-quote-card__notice stride-quote-card__notice--danger">
                        <span uk-icon="icon: warning; ratio: 0.8"></span>
                        <?php esc_html_e('Deze offerte is verlopen', 'stride'); ?>
                    </div>
                <?php elseif ($daysLeft <= 7) : ?>
                    <div class="stride-quote-card__notice stride-quote-card__notice--warning">
                        <span uk-icon="icon: clock; ratio: 0.8"></span>
                        <?php
                        printf(
                            esc_html(_n(
                                'Nog %d dag geldig',
                                'Nog %d dagen geldig',
                                $daysLeft,
                                'stride'
                            )),
                            $daysLeft
                        );
                        ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Get CSS class for quote status badge
 *
 * @param QuoteStatus $status Quote status
 * @return string CSS class
 */
function stride_get_quote_status_class(QuoteStatus $status): string
{
    return match ($status) {
        QuoteStatus::Draft => 'stride-label-soft-secondary',
        QuoteStatus::Sent => 'stride-label-soft-warning',
        QuoteStatus::Exported => 'stride-label-soft-success',
        QuoteStatus::Cancelled => 'stride-label-soft-danger',
    };
}
?>
