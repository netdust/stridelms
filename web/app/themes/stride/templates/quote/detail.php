<?php
/**
 * Quote Detail Template
 *
 * Displays a single quote with line items, billing info, and status.
 * Allows editing billing info for draft quotes.
 *
 * @package stride
 */

defined('ABSPATH') || exit;

use Stride\Domain\QuoteStatus;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Edition\EditionService;

// Get services
$quoteService = ntdst_get(QuoteService::class);
$editionService = ntdst_get(EditionService::class);

// Get quote ID from query parameter
$quoteId = (int) ($_GET['quote_id'] ?? 0);

// User info
$user = wp_get_current_user();
$userId = $user->ID;

// Check if logged in
if (!is_user_logged_in()) {
    ?>
    <div class="stride-quote-detail uk-width-xlarge uk-margin-auto">
        <div class="stride-card uk-text-center uk-padding-large">
            <div class="stride-empty-state__icon uk-margin-bottom">
                <span uk-icon="icon: lock; ratio: 2"></span>
            </div>
            <h2><?php esc_html_e('Log in om je offerte te bekijken', 'stride'); ?></h2>
            <p class="uk-text-muted uk-margin-bottom">
                <?php esc_html_e('Je moet ingelogd zijn om je offertes te bekijken.', 'stride'); ?>
            </p>
            <a href="<?php echo esc_url(wp_login_url(add_query_arg('quote_id', $quoteId, home_url('/offerte/')))); ?>" class="uk-button uk-button-primary uk-button-large">
                <?php esc_html_e('Inloggen', 'stride'); ?>
            </a>
        </div>
    </div>
    <?php
    return;
}

// Validate quote ID
if (!$quoteId) {
    ?>
    <div class="stride-quote-detail uk-width-xlarge uk-margin-auto">
        <div class="uk-alert uk-alert-danger">
            <?php esc_html_e('Geen offerte geselecteerd.', 'stride'); ?>
        </div>
        <a href="<?php echo esc_url(home_url('/mijn-account/offertes/')); ?>" class="uk-button uk-button-default">
            <?php esc_html_e('Terug naar mijn offertes', 'stride'); ?>
        </a>
    </div>
    <?php
    return;
}

// Get quote data
$quote = $quoteService->getQuote($quoteId);
if (!$quote || is_wp_error($quote)) {
    ?>
    <div class="stride-quote-detail uk-width-xlarge uk-margin-auto">
        <div class="uk-alert uk-alert-danger">
            <?php esc_html_e('Offerte niet gevonden.', 'stride'); ?>
        </div>
        <a href="<?php echo esc_url(home_url('/mijn-account/offertes/')); ?>" class="uk-button uk-button-default">
            <?php esc_html_e('Terug naar mijn offertes', 'stride'); ?>
        </a>
    </div>
    <?php
    return;
}

// Check ownership
$quoteUserId = (int) ($quote['user_id'] ?? 0);
if ($quoteUserId !== $userId && !current_user_can('manage_options')) {
    ?>
    <div class="stride-quote-detail uk-width-xlarge uk-margin-auto">
        <div class="uk-alert uk-alert-danger">
            <?php esc_html_e('Je hebt geen toegang tot deze offerte.', 'stride'); ?>
        </div>
        <a href="<?php echo esc_url(home_url('/mijn-account/offertes/')); ?>" class="uk-button uk-button-default">
            <?php esc_html_e('Terug naar mijn offertes', 'stride'); ?>
        </a>
    </div>
    <?php
    return;
}

// Quote data
$quoteNumber = $quote['quote_number'] ?? sprintf('Q%d', $quoteId);
$status = $quote['status_enum'] ?? QuoteStatus::Draft;
$isEditable = $status->isEditable();
$items = $quote['items'] ?? [];
$billing = $quote['billing'] ?? [];
$subtotal = $quote['subtotal_money'] ?? null;
$tax = $quote['tax_money'] ?? null;
$total = $quote['total_money'] ?? null;
$validUntil = $quote['valid_until'] ?? '';
$quoteDate = $quote['post_date'] ?? '';
$editionId = (int) ($quote['edition_id'] ?? 0);

// Get edition/course info
$editionTitle = '';
if ($editionId > 0) {
    $courseId = $editionService->getCourseId($editionId);
    $editionTitle = $courseId ? get_the_title($courseId) : get_the_title($editionId);
}

// Status badge
$statusClass = match ($status) {
    QuoteStatus::Draft => 'stride-label-soft-secondary',
    QuoteStatus::Sent => 'stride-label-soft-warning',
    QuoteStatus::Exported => 'stride-label-soft-success',
    QuoteStatus::Cancelled => 'stride-label-soft-danger',
};
?>

<div class="stride-quote-detail uk-width-xlarge uk-margin-auto">
        <!-- Header -->
        <header class="stride-page-header uk-margin-bottom">
            <a href="<?php echo esc_url(home_url('/mijn-account/offertes/')); ?>" class="stride-page-header__back">
                <span uk-icon="icon: arrow-left; ratio: 0.8"></span>
                <?php esc_html_e('Mijn offertes', 'stride'); ?>
            </a>
            <div class="uk-flex uk-flex-between uk-flex-middle">
                <h1 class="stride-page-header__title uk-margin-remove">
                    <?php esc_html_e('Offerte', 'stride'); ?> <?php echo esc_html($quoteNumber); ?>
                </h1>
                <span class="uk-label <?php echo esc_attr($statusClass); ?>">
                    <?php echo esc_html($status->label()); ?>
                </span>
            </div>
            <?php if ($quoteDate): ?>
                <p class="stride-page-header__subtitle">
                    <?php echo esc_html(date_i18n('j F Y', strtotime($quoteDate))); ?>
                </p>
            <?php endif; ?>
        </header>

        <?php if ($status === QuoteStatus::Exported): ?>
            <div class="uk-alert uk-alert-success uk-margin-bottom">
                <span uk-icon="icon: check"></span>
                <?php esc_html_e('Deze offerte is verwerkt en doorgestuurd naar de boekhouding.', 'stride'); ?>
            </div>
        <?php elseif ($status === QuoteStatus::Cancelled): ?>
            <div class="uk-alert uk-alert-danger uk-margin-bottom">
                <span uk-icon="icon: ban"></span>
                <?php esc_html_e('Deze offerte is geannuleerd.', 'stride'); ?>
            </div>
        <?php endif; ?>

        <!-- Quote Details -->
        <div class="stride-card uk-margin-bottom">
            <div class="stride-card-header">
                <h2 class="stride-card-title">
                    <span uk-icon="icon: file-text"></span>
                    <?php esc_html_e('Offerte details', 'stride'); ?>
                </h2>
            </div>
            <div class="uk-padding">
                <!-- Line Items -->
                <table class="uk-table uk-table-divider uk-table-middle">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Omschrijving', 'stride'); ?></th>
                            <th class="uk-text-right uk-width-small"><?php esc_html_e('Aantal', 'stride'); ?></th>
                            <th class="uk-text-right uk-width-small"><?php esc_html_e('Bedrag', 'stride'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($items)): ?>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <?php echo esc_html($item['title'] ?? $editionTitle); ?>
                                        <?php if (!empty($item['description'])): ?>
                                            <br><small class="uk-text-muted"><?php echo esc_html($item['description']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="uk-text-right"><?php echo esc_html($item['quantity'] ?? 1); ?></td>
                                    <td class="uk-text-right">
                                        <?php
                                        $itemTotal = ($item['total'] ?? $item['unit_price'] ?? 0) / 100;
                                        echo '€ ' . number_format($itemTotal, 2, ',', '.');
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td><?php echo esc_html($editionTitle ?: __('Cursusinschrijving', 'stride')); ?></td>
                                <td class="uk-text-right">1</td>
                                <td class="uk-text-right"><?php echo $subtotal ? esc_html($subtotal->format()) : '€ 0,00'; ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="uk-text-right"><?php esc_html_e('Subtotaal', 'stride'); ?></td>
                            <td class="uk-text-right"><?php echo $subtotal ? esc_html($subtotal->format()) : '€ 0,00'; ?></td>
                        </tr>
                        <tr>
                            <td colspan="2" class="uk-text-right"><?php esc_html_e('BTW (21%)', 'stride'); ?></td>
                            <td class="uk-text-right"><?php echo $tax ? esc_html($tax->format()) : '€ 0,00'; ?></td>
                        </tr>
                        <tr class="uk-text-bold" style="font-size: 1.1em;">
                            <td colspan="2" class="uk-text-right"><?php esc_html_e('Totaal', 'stride'); ?></td>
                            <td class="uk-text-right"><?php echo $total ? esc_html($total->format()) : '€ 0,00'; ?></td>
                        </tr>
                    </tfoot>
                </table>

                <?php if ($isEditable): ?>
                <!-- Voucher Code Input -->
                <div class="uk-margin-medium-top uk-padding-small uk-background-muted" style="border-radius: 4px;">
                    <h4 class="uk-h5 uk-margin-small-bottom"><?php esc_html_e('Kortingscode', 'stride'); ?></h4>
                    <form id="voucher-form" class="uk-form-stacked">
                        <input type="hidden" name="action" value="stride_apply_voucher">
                        <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('stride_quote_update')); ?>">
                        <input type="hidden" name="quote_id" value="<?php echo esc_attr($quoteId); ?>">
                        <div class="uk-grid-small" uk-grid>
                            <div class="uk-width-expand">
                                <input type="text" name="voucher_code" class="uk-input"
                                       placeholder="<?php esc_attr_e('Voer kortingscode in', 'stride'); ?>">
                            </div>
                            <div class="uk-width-auto">
                                <button type="submit" class="uk-button uk-button-secondary" id="apply-voucher-btn">
                                    <?php esc_html_e('Toepassen', 'stride'); ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <?php if ($validUntil && $isEditable): ?>
                    <?php
                    $validDate = strtotime($validUntil);
                    $isExpired = $validDate && $validDate < time();
                    $daysLeft = $validDate ? ceil(($validDate - time()) / DAY_IN_SECONDS) : 0;
                    ?>
                    <div class="uk-margin-top uk-text-small <?php echo $isExpired ? 'uk-text-danger' : 'uk-text-muted'; ?>">
                        <?php if ($isExpired): ?>
                            <span uk-icon="icon: warning; ratio: 0.8"></span>
                            <?php esc_html_e('Deze offerte is verlopen op', 'stride'); ?> <?php echo esc_html(date_i18n('j F Y', $validDate)); ?>
                        <?php else: ?>
                            <span uk-icon="icon: clock; ratio: 0.8"></span>
                            <?php esc_html_e('Geldig tot', 'stride'); ?> <?php echo esc_html(date_i18n('j F Y', $validDate)); ?>
                            (<?php printf(esc_html(_n('nog %d dag', 'nog %d dagen', $daysLeft, 'stride')), $daysLeft); ?>)
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Billing Information -->
        <div class="stride-card uk-margin-bottom">
            <div class="stride-card-header">
                <h2 class="stride-card-title">
                    <span uk-icon="icon: user"></span>
                    <?php esc_html_e('Facturatiegegevens', 'stride'); ?>
                </h2>
                <?php if ($isEditable): ?>
                    <button type="button" class="uk-button uk-button-text" uk-toggle="target: #edit-billing-modal">
                        <span uk-icon="icon: pencil; ratio: 0.8"></span>
                        <?php esc_html_e('Wijzigen', 'stride'); ?>
                    </button>
                <?php endif; ?>
            </div>
            <div class="uk-padding">
                <div class="uk-grid uk-grid-small uk-child-width-1-2@s" uk-grid>
                    <?php $organisation = $billing['organisation'] ?? $billing['organization'] ?? ''; ?>
                    <?php if (!empty($organisation)): ?>
                        <div class="uk-width-1-1">
                            <strong><?php esc_html_e('Organisatie', 'stride'); ?></strong>
                            <p class="uk-margin-remove"><?php echo esc_html($organisation); ?></p>
                        </div>
                    <?php endif; ?>
                    <div>
                        <strong><?php esc_html_e('E-mailadres', 'stride'); ?></strong>
                        <p class="uk-margin-remove"><?php echo esc_html($billing['email'] ?? '-'); ?></p>
                    </div>
                    <?php if (!empty($billing['vat_number'])): ?>
                        <div>
                            <strong><?php esc_html_e('BTW-nummer', 'stride'); ?></strong>
                            <p class="uk-margin-remove"><?php echo esc_html($billing['vat_number']); ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($billing['gln_number'])): ?>
                        <div>
                            <strong><?php esc_html_e('GLN-nummer', 'stride'); ?></strong>
                            <p class="uk-margin-remove"><?php echo esc_html($billing['gln_number']); ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($billing['address'])): ?>
                        <?php $postal = $billing['postal_code'] ?? $billing['postal'] ?? ''; ?>
                        <div class="uk-width-1-1">
                            <strong><?php esc_html_e('Adres', 'stride'); ?></strong>
                            <p class="uk-margin-remove">
                                <?php echo esc_html($billing['address']); ?><br>
                                <?php if (!empty($postal) || !empty($billing['city'])): ?>
                                    <?php echo esc_html(trim($postal . ' ' . ($billing['city'] ?? ''))); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap" uk-grid>
            <div>
                <a href="<?php echo esc_url(home_url('/mijn-account/offertes/')); ?>" class="uk-button uk-button-default">
                    <span uk-icon="icon: arrow-left"></span>
                    <?php esc_html_e('Terug', 'stride'); ?>
                </a>
            </div>

            <div class="uk-flex uk-flex-middle uk-flex-wrap" style="gap: 10px;">
                <!-- PDF Download Button -->
                <button type="button" class="uk-button uk-button-default" id="download-pdf-btn">
                    <span uk-icon="icon: download"></span>
                    <?php esc_html_e('Download PDF', 'stride'); ?>
                </button>

                <?php if ($isEditable): ?>
                <!-- Cancel Enrollment Button -->
                <button type="button" class="uk-button uk-button-danger" uk-toggle="target: #cancel-modal">
                    <?php esc_html_e('Inschrijving annuleren', 'stride'); ?>
                </button>
                <?php endif; ?>

                <?php if ($editionId > 0): ?>
                <a href="<?php echo esc_url(get_permalink($editionId)); ?>" class="uk-button uk-button-text">
                    <?php esc_html_e('Bekijk cursus', 'stride'); ?>
                    <span uk-icon="icon: arrow-right"></span>
                </a>
                <?php endif; ?>
            </div>
        </div>
</div>

<?php if ($isEditable): ?>
<!-- Cancel Confirmation Modal -->
<div id="cancel-modal" uk-modal>
    <div class="uk-modal-dialog uk-modal-body">
        <h2 class="uk-modal-title"><?php esc_html_e('Inschrijving annuleren', 'stride'); ?></h2>
        <p><?php esc_html_e('Weet je zeker dat je deze inschrijving wilt annuleren? Dit kan niet ongedaan worden gemaakt.', 'stride'); ?></p>
        <form id="cancel-form">
            <input type="hidden" name="action" value="stride_cancel_quote">
            <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('stride_quote_update')); ?>">
            <input type="hidden" name="quote_id" value="<?php echo esc_attr($quoteId); ?>">
            <p class="uk-text-right uk-margin-remove-bottom">
                <button type="button" class="uk-button uk-button-default uk-modal-close">
                    <?php esc_html_e('Terug', 'stride'); ?>
                </button>
                <button type="submit" class="uk-button uk-button-danger" id="confirm-cancel-btn">
                    <?php esc_html_e('Ja, annuleren', 'stride'); ?>
                </button>
            </p>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($isEditable): ?>
<!-- Edit Billing Modal -->
<div id="edit-billing-modal" uk-modal>
    <div class="uk-modal-dialog">
        <button class="uk-modal-close-default" type="button" uk-close></button>
        <div class="uk-modal-header">
            <h2 class="uk-modal-title"><?php esc_html_e('Facturatiegegevens wijzigen', 'stride'); ?></h2>
        </div>
        <form id="edit-billing-form" method="post">
            <?php wp_nonce_field('stride_quote_update', 'nonce'); ?>
            <input type="hidden" name="action" value="stride_update_quote">
            <input type="hidden" name="quote_id" value="<?php echo esc_attr($quoteId); ?>">

            <div class="uk-modal-body">
                <div class="uk-grid uk-grid-small uk-child-width-1-2@s" uk-grid>
                    <div class="uk-width-1-1">
                        <label class="uk-form-label" for="edit_billing_organisation"><?php esc_html_e('Organisatie', 'stride'); ?></label>
                        <input type="text" id="edit_billing_organisation" name="billing[organisation]" class="uk-input"
                               value="<?php echo esc_attr($billing['organisation'] ?? $billing['organization'] ?? ''); ?>">
                    </div>
                    <div class="uk-width-1-1">
                        <label class="uk-form-label" for="edit_billing_email"><?php esc_html_e('E-mailadres', 'stride'); ?> *</label>
                        <input type="email" id="edit_billing_email" name="billing[email]" class="uk-input"
                               value="<?php echo esc_attr($billing['email'] ?? ''); ?>" required>
                    </div>
                    <div>
                        <label class="uk-form-label" for="edit_billing_vat"><?php esc_html_e('BTW-nummer', 'stride'); ?></label>
                        <input type="text" id="edit_billing_vat" name="billing[vat_number]" class="uk-input"
                               value="<?php echo esc_attr($billing['vat_number'] ?? ''); ?>">
                    </div>
                    <div>
                        <label class="uk-form-label" for="edit_billing_gln"><?php esc_html_e('GLN-nummer', 'stride'); ?></label>
                        <input type="text" id="edit_billing_gln" name="billing[gln_number]" class="uk-input"
                               value="<?php echo esc_attr($billing['gln_number'] ?? ''); ?>">
                    </div>
                    <div class="uk-width-1-1">
                        <label class="uk-form-label" for="edit_billing_address"><?php esc_html_e('Adres', 'stride'); ?></label>
                        <input type="text" id="edit_billing_address" name="billing[address]" class="uk-input"
                               value="<?php echo esc_attr($billing['address'] ?? ''); ?>">
                    </div>
                    <div>
                        <label class="uk-form-label" for="edit_billing_postal"><?php esc_html_e('Postcode', 'stride'); ?></label>
                        <input type="text" id="edit_billing_postal" name="billing[postal_code]" class="uk-input"
                               value="<?php echo esc_attr($billing['postal_code'] ?? $billing['postal'] ?? ''); ?>">
                    </div>
                    <div>
                        <label class="uk-form-label" for="edit_billing_city"><?php esc_html_e('Plaats', 'stride'); ?></label>
                        <input type="text" id="edit_billing_city" name="billing[city]" class="uk-input"
                               value="<?php echo esc_attr($billing['city'] ?? ''); ?>">
                    </div>
                </div>
            </div>
            <div class="uk-modal-footer uk-text-right">
                <button class="uk-button uk-button-default uk-modal-close" type="button">
                    <?php esc_html_e('Annuleren', 'stride'); ?>
                </button>
                <button class="uk-button uk-button-primary" type="submit" id="save-billing-btn">
                    <?php esc_html_e('Opslaan', 'stride'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Edit billing form handling
    const form = document.getElementById('edit-billing-form');
    const saveBtn = document.getElementById('save-billing-btn');

    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span uk-spinner="ratio: 0.5"></span>';

            const formData = new FormData(form);

            fetch(strideConfig.ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    UIkit.notification({
                        message: '<?php echo esc_js(__('Gegevens opgeslagen', 'stride')); ?>',
                        status: 'success',
                        pos: 'top-center'
                    });
                    // Reload to show updated data
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    UIkit.notification({
                        message: data.data.message || '<?php echo esc_js(__('Er is een fout opgetreden', 'stride')); ?>',
                        status: 'danger',
                        pos: 'top-center'
                    });
                    saveBtn.disabled = false;
                    saveBtn.textContent = '<?php echo esc_js(__('Opslaan', 'stride')); ?>';
                }
            })
            .catch(() => {
                UIkit.notification({
                    message: '<?php echo esc_js(__('Er is een fout opgetreden', 'stride')); ?>',
                    status: 'danger',
                    pos: 'top-center'
                });
                saveBtn.disabled = false;
                saveBtn.textContent = '<?php echo esc_js(__('Opslaan', 'stride')); ?>';
            });
        });
    }

    // Voucher form handling
    const voucherForm = document.getElementById('voucher-form');
    const applyVoucherBtn = document.getElementById('apply-voucher-btn');

    if (voucherForm) {
        voucherForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const voucherInput = voucherForm.querySelector('input[name="voucher_code"]');
            if (!voucherInput.value.trim()) {
                UIkit.notification({
                    message: '<?php echo esc_js(__('Voer een kortingscode in', 'stride')); ?>',
                    status: 'warning',
                    pos: 'top-center'
                });
                return;
            }

            applyVoucherBtn.disabled = true;
            applyVoucherBtn.innerHTML = '<span uk-spinner="ratio: 0.5"></span>';

            const formData = new FormData(voucherForm);

            fetch(strideConfig.ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    UIkit.notification({
                        message: data.data.message || '<?php echo esc_js(__('Kortingscode toegepast', 'stride')); ?>',
                        status: 'success',
                        pos: 'top-center'
                    });
                    // Reload to show updated totals
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    UIkit.notification({
                        message: data.data.message || '<?php echo esc_js(__('Ongeldige kortingscode', 'stride')); ?>',
                        status: 'danger',
                        pos: 'top-center'
                    });
                    applyVoucherBtn.disabled = false;
                    applyVoucherBtn.textContent = '<?php echo esc_js(__('Toepassen', 'stride')); ?>';
                }
            })
            .catch(() => {
                UIkit.notification({
                    message: '<?php echo esc_js(__('Er is een fout opgetreden', 'stride')); ?>',
                    status: 'danger',
                    pos: 'top-center'
                });
                applyVoucherBtn.disabled = false;
                applyVoucherBtn.textContent = '<?php echo esc_js(__('Toepassen', 'stride')); ?>';
            });
        });
    }

    // Cancel form handling
    const cancelForm = document.getElementById('cancel-form');
    const confirmCancelBtn = document.getElementById('confirm-cancel-btn');

    if (cancelForm) {
        cancelForm.addEventListener('submit', function(e) {
            e.preventDefault();

            confirmCancelBtn.disabled = true;
            confirmCancelBtn.innerHTML = '<span uk-spinner="ratio: 0.5"></span>';

            const formData = new FormData(cancelForm);

            fetch(strideConfig.ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    UIkit.notification({
                        message: data.data.message || '<?php echo esc_js(__('Inschrijving geannuleerd', 'stride')); ?>',
                        status: 'success',
                        pos: 'top-center'
                    });
                    // Redirect if provided, otherwise reload
                    if (data.data.redirect_url) {
                        setTimeout(() => window.location.href = data.data.redirect_url, 1000);
                    } else {
                        setTimeout(() => window.location.reload(), 1000);
                    }
                } else {
                    UIkit.notification({
                        message: data.data.message || '<?php echo esc_js(__('Er is een fout opgetreden', 'stride')); ?>',
                        status: 'danger',
                        pos: 'top-center'
                    });
                    confirmCancelBtn.disabled = false;
                    confirmCancelBtn.textContent = '<?php echo esc_js(__('Ja, annuleren', 'stride')); ?>';
                    UIkit.modal('#cancel-modal').hide();
                }
            })
            .catch(() => {
                UIkit.notification({
                    message: '<?php echo esc_js(__('Er is een fout opgetreden', 'stride')); ?>',
                    status: 'danger',
                    pos: 'top-center'
                });
                confirmCancelBtn.disabled = false;
                confirmCancelBtn.textContent = '<?php echo esc_js(__('Ja, annuleren', 'stride')); ?>';
                UIkit.modal('#cancel-modal').hide();
            });
        });
    }
});
</script>
<?php endif; ?>

<!-- PDF download handler (outside isEditable check - available for all quotes) -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const pdfBtn = document.getElementById('download-pdf-btn');
    if (pdfBtn) {
        pdfBtn.addEventListener('click', function() {
            UIkit.notification({
                message: '<?php echo esc_js(__('PDF download komt binnenkort beschikbaar.', 'stride')); ?>',
                status: 'primary',
                pos: 'top-center'
            });
        });
    }
});
</script>
