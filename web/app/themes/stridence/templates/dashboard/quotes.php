<?php
/**
 * My Quotes Dashboard Page
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

get_header();

$current_page = 'quotes';
$userId = get_current_user_id();

// Get user's quotes
$quotes = [];
// Integration with QuoteService would populate this

include get_stylesheet_directory() . '/templates/partials/dashboard-layout.php';
?>

<header class="str-dashboard__header">
    <h1 class="str-dashboard__title"><?php esc_html_e('Mijn offertes', 'stridence'); ?></h1>
    <p class="str-dashboard__subtitle">
        <?php esc_html_e('Overzicht van je offertes en betalingen', 'stridence'); ?>
    </p>
</header>

<?php if (!empty($quotes)): ?>
    <div class="str-quotes-table">
        <table class="str-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Nummer', 'stridence'); ?></th>
                    <th><?php esc_html_e('Datum', 'stridence'); ?></th>
                    <th><?php esc_html_e('Omschrijving', 'stridence'); ?></th>
                    <th><?php esc_html_e('Bedrag', 'stridence'); ?></th>
                    <th><?php esc_html_e('Status', 'stridence'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($quotes as $quote): ?>
                    <tr>
                        <td><?php echo esc_html($quote['number']); ?></td>
                        <td><?php echo esc_html($quote['date']); ?></td>
                        <td><?php echo esc_html($quote['description']); ?></td>
                        <td>€<?php echo esc_html(number_format($quote['amount'], 2, ',', '.')); ?></td>
                        <td>
                            <span class="str-badge str-badge--<?php echo esc_attr($quote['status_class']); ?>">
                                <?php echo esc_html($quote['status_label']); ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?php echo esc_url($quote['url']); ?>" class="str-btn str-btn--ghost str-btn--sm">
                                <?php stridence_icon('download', '', 16); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div class="str-empty-state">
        <?php stridence_icon('file-text', '', 48); ?>
        <h2><?php esc_html_e('Geen offertes', 'stridence'); ?></h2>
        <p><?php esc_html_e('Je hebt nog geen offertes ontvangen.', 'stridence'); ?></p>
    </div>
<?php endif; ?>

<?php
include get_stylesheet_directory() . '/templates/partials/dashboard-layout-close.php';
get_footer();
?>
