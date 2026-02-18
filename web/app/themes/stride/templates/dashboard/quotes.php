<?php
/**
 * My Quotes Template
 *
 * User's quotes listing with status and download options.
 *
 * @var int $user_id
 * @var array $quotes
 * @var DashboardService $dashboard_service
 *
 * @package stride
 */

defined('ABSPATH') || exit;
?>

<div class="stride-dashboard">
    <div class="uk-container">
        <!-- Page Header -->
        <div class="stride-dashboard-header uk-margin-medium-bottom">
            <h1 class="uk-h2 uk-margin-remove-bottom">
                <?php esc_html_e('Mijn Offertes', 'stride'); ?>
            </h1>
            <p class="uk-text-muted uk-margin-small-top">
                <?php esc_html_e('Bekijk en download je offertes.', 'stride'); ?>
            </p>
        </div>

        <?php if (!empty($quotes)): ?>
            <!-- Quotes Table -->
            <div class="stride-card">
                <div class="uk-overflow-auto">
                    <table class="stride-quote-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Offertenummer', 'stride'); ?></th>
                                <th><?php esc_html_e('Datum', 'stride'); ?></th>
                                <th><?php esc_html_e('Items', 'stride'); ?></th>
                                <th><?php esc_html_e('Totaal', 'stride'); ?></th>
                                <th><?php esc_html_e('Status', 'stride'); ?></th>
                                <th><?php esc_html_e('Geldig tot', 'stride'); ?></th>
                                <th class="uk-text-right"><?php esc_html_e('Acties', 'stride'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($quotes as $quote): ?>
                                <?php
                                $statusBadgeClass = match ($quote['status']) {
                                    'sent' => 'stride-badge-sent',
                                    'exported' => 'stride-badge-paid',
                                    default => 'stride-badge-draft',
                                };
                                ?>
                                <tr>
                                    <td>
                                        <span class="stride-quote-number">
                                            <?php echo esc_html($quote['quote_number']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo esc_html(date_i18n('j F Y', strtotime($quote['created_at']))); ?>
                                    </td>
                                    <td>
                                        <?php echo esc_html($quote['item_count']); ?>
                                        <?php echo esc_html(_n('item', 'items', $quote['item_count'], 'stride')); ?>
                                    </td>
                                    <td>
                                        <span class="stride-quote-amount">
                                            <?php echo esc_html($quote['total_formatted']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="stride-badge <?php echo esc_attr($statusBadgeClass); ?>">
                                            <?php echo esc_html($quote['status_label']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($quote['valid_until_formatted']): ?>
                                            <?php if ($quote['is_expired']): ?>
                                                <span class="uk-text-danger">
                                                    <?php echo esc_html($quote['valid_until_formatted']); ?>
                                                    <span uk-icon="icon: warning; ratio: 0.8"></span>
                                                </span>
                                            <?php else: ?>
                                                <?php echo esc_html($quote['valid_until_formatted']); ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="uk-text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="uk-text-right">
                                        <?php if ($quote['pdf_url']): ?>
                                            <a href="<?php echo esc_url($quote['pdf_url']); ?>"
                                               class="uk-button uk-button-default uk-button-small"
                                               target="_blank">
                                                <span uk-icon="icon: download; ratio: 0.8"></span>
                                                <?php esc_html_e('PDF', 'stride'); ?>
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($quote['status'] === 'draft'): ?>
                                            <a href="<?php echo esc_url(home_url('/offerte-bijwerken/?quote=' . $quote['id'])); ?>"
                                               class="uk-button uk-button-primary uk-button-small">
                                                <span uk-icon="icon: pencil; ratio: 0.8"></span>
                                                <?php esc_html_e('Bijwerken', 'stride'); ?>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Quote Info -->
            <div class="uk-margin-medium-top">
                <div class="uk-alert uk-alert-primary" uk-alert>
                    <span uk-icon="icon: info"></span>
                    <?php esc_html_e('Offertes worden automatisch aangemaakt bij inschrijving. Na ontvangst van uw betaling wordt uw inschrijving definitief.', 'stride'); ?>
                </div>
            </div>
        <?php else: ?>
            <div class="stride-card">
                <div class="stride-empty-state">
                    <span class="stride-empty-state-icon" uk-icon="icon: file-text; ratio: 3"></span>
                    <h3 class="stride-empty-state-title">
                        <?php esc_html_e('Geen offertes', 'stride'); ?>
                    </h3>
                    <p class="stride-empty-state-text">
                        <?php esc_html_e('Je hebt nog geen offertes. Offertes worden aangemaakt wanneer je je inschrijft voor een cursus.', 'stride'); ?>
                    </p>
                    <a href="<?php echo esc_url(home_url('/cursussen/')); ?>" class="uk-button uk-button-primary">
                        <?php esc_html_e('Bekijk cursusaanbod', 'stride'); ?>
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Back to Dashboard -->
        <div class="uk-margin-medium-top">
            <a href="<?php echo esc_url(home_url('/mijn-account/')); ?>" class="uk-link-muted">
                <span uk-icon="icon: arrow-left; ratio: 0.8"></span>
                <?php esc_html_e('Terug naar dashboard', 'stride'); ?>
            </a>
        </div>
    </div>
</div>
