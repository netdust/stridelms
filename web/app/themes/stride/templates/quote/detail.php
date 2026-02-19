<?php
/**
 * Quote Detail Template
 *
 * Displays a single quote with sidepanel layout:
 * - Main panel: User info, billing info, actions
 * - Side panel: Quote details, line items, totals
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
        <a href="<?php echo esc_url(home_url('/mijn-account/mijn-offertes/')); ?>" class="uk-button uk-button-default">
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
        <a href="<?php echo esc_url(home_url('/mijn-account/mijn-offertes/')); ?>" class="uk-button uk-button-default">
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
        <a href="<?php echo esc_url(home_url('/mijn-account/mijn-offertes/')); ?>" class="uk-button uk-button-default">
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
$discount = $quote['discount_money'] ?? null;
$tax = $quote['tax_money'] ?? null;
$total = $quote['total_money'] ?? null;
$validUntil = $quote['valid_until'] ?? '';
$quoteDate = $quote['post_date'] ?? '';
$editionId = (int) ($quote['edition_id'] ?? 0);
$voucherCode = $quote['voucher_code'] ?? '';

// Get edition/course info
$editionTitle = '';
if ($editionId > 0) {
    $courseId = $editionService->getCourseId($editionId);
    $editionTitle = $courseId ? get_the_title($courseId) : get_the_title($editionId);
}

// User data
$quoteUser = get_user_by('ID', $quoteUserId);
$userName = $quoteUser ? trim($quoteUser->first_name . ' ' . $quoteUser->last_name) : '';
if (empty($userName)) {
    $userName = $quoteUser->display_name ?? '';
}
$userEmail = $quoteUser->user_email ?? '';
$userPhone = get_user_meta($quoteUserId, 'phone', true) ?: get_user_meta($quoteUserId, 'billing_phone', true);

// Status badge
$statusClass = match ($status) {
    QuoteStatus::Draft => 'stride-label-soft-secondary',
    QuoteStatus::Sent => 'stride-label-soft-warning',
    QuoteStatus::Exported => 'stride-label-soft-success',
    QuoteStatus::Cancelled => 'stride-label-soft-danger',
};

// Validity check
$validDate = $validUntil ? strtotime($validUntil) : null;
$isExpired = $validDate && $validDate < time();
$daysLeft = $validDate ? ceil(($validDate - time()) / DAY_IN_SECONDS) : 0;
?>

<div class="stride-quote-detail">
    <!-- Header -->
    <header class="stride-page-header uk-margin-bottom">
        <a href="<?php echo esc_url(home_url('/mijn-account/mijn-offertes/')); ?>" class="stride-page-header__back">
            <span uk-icon="icon: arrow-left; ratio: 0.8"></span>
            <?php esc_html_e('Mijn offertes', 'stride'); ?>
        </a>
        <div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap" style="gap: 10px;">
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

    <!-- Main Layout: 2 columns -->
    <div class="uk-grid uk-grid-medium" uk-grid>
        <!-- Main Panel: User & Billing Info -->
        <div class="uk-width-1-1 uk-width-2-3@m">
            <!-- User Information -->
            <div class="stride-card uk-margin-bottom">
                <div class="stride-card-header">
                    <h2 class="stride-card-title">
                        <span uk-icon="icon: user"></span>
                        <?php esc_html_e('Klantgegevens', 'stride'); ?>
                    </h2>
                </div>
                <div class="uk-padding">
                    <div class="uk-grid uk-grid-small" uk-grid>
                        <div class="uk-width-1-2@s">
                            <strong><?php esc_html_e('Naam', 'stride'); ?></strong>
                            <p class="uk-margin-remove"><?php echo esc_html($userName ?: '-'); ?></p>
                        </div>
                        <div class="uk-width-1-2@s">
                            <strong><?php esc_html_e('E-mailadres', 'stride'); ?></strong>
                            <p class="uk-margin-remove"><?php echo esc_html($userEmail ?: '-'); ?></p>
                        </div>
                        <?php if ($userPhone): ?>
                        <div class="uk-width-1-2@s">
                            <strong><?php esc_html_e('Telefoon', 'stride'); ?></strong>
                            <p class="uk-margin-remove"><?php echo esc_html($userPhone); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Billing Information -->
            <?php
            $organisation = $billing['organisation'] ?? $billing['organization'] ?? '';
            $billingEmail = $billing['email'] ?? $userEmail ?: '';
            $vatNumber = $billing['vat_number'] ?? '';
            $glnNumber = $billing['gln_number'] ?? '';
            $billingAddress = $billing['address'] ?? '';
            $postalCode = $billing['postal_code'] ?? $billing['postal'] ?? '';
            $billingCity = $billing['city'] ?? '';
            ?>
            <div class="stride-card uk-margin-bottom" id="billing-card" data-editing="false">
                <div class="stride-card-header">
                    <h2 class="stride-card-title">
                        <span uk-icon="icon: file-text"></span>
                        <?php esc_html_e('Facturatiegegevens', 'stride'); ?>
                    </h2>
                    <?php if ($isEditable): ?>
                        <button type="button" class="uk-button uk-button-text" id="billing-edit-btn">
                            <span uk-icon="icon: pencil; ratio: 0.8"></span>
                            <?php esc_html_e('Wijzigen', 'stride'); ?>
                        </button>
                    <?php endif; ?>
                </div>
                <div class="uk-padding">
                    <form id="billing-form">
                        <?php wp_nonce_field('stride_quote_update', 'billing_nonce'); ?>
                        <input type="hidden" name="action" value="stride_update_quote">
                        <input type="hidden" name="quote_id" value="<?php echo esc_attr($quoteId); ?>">

                        <div class="uk-grid uk-grid-small" uk-grid>
                            <div class="uk-width-1-1">
                                <label class="uk-form-label"><?php esc_html_e('Organisatie', 'stride'); ?></label>
                                <p class="uk-margin-remove billing-view"><?php echo esc_html($organisation ?: '-'); ?></p>
                                <input type="text" name="billing[organisation]" class="uk-input billing-edit uk-hidden"
                                       value="<?php echo esc_attr($organisation); ?>">
                            </div>
                            <div class="uk-width-1-2@s">
                                <label class="uk-form-label"><?php esc_html_e('Facturatie e-mail', 'stride'); ?></label>
                                <p class="uk-margin-remove billing-view"><?php echo esc_html($billingEmail ?: '-'); ?></p>
                                <input type="email" name="billing[email]" class="uk-input billing-edit uk-hidden"
                                       value="<?php echo esc_attr($billingEmail); ?>">
                            </div>
                            <div class="uk-width-1-2@s">
                                <label class="uk-form-label"><?php esc_html_e('BTW-nummer', 'stride'); ?></label>
                                <p class="uk-margin-remove billing-view"><?php echo esc_html($vatNumber ?: '-'); ?></p>
                                <input type="text" name="billing[vat_number]" class="uk-input billing-edit uk-hidden"
                                       value="<?php echo esc_attr($vatNumber); ?>">
                            </div>
                            <div class="uk-width-1-2@s">
                                <label class="uk-form-label"><?php esc_html_e('GLN-nummer', 'stride'); ?></label>
                                <p class="uk-margin-remove billing-view"><?php echo esc_html($glnNumber ?: '-'); ?></p>
                                <input type="text" name="billing[gln_number]" class="uk-input billing-edit uk-hidden"
                                       value="<?php echo esc_attr($glnNumber); ?>">
                            </div>
                            <div class="uk-width-1-1">
                                <label class="uk-form-label"><?php esc_html_e('Adres', 'stride'); ?></label>
                                <p class="uk-margin-remove billing-view"><?php echo esc_html($billingAddress ?: '-'); ?></p>
                                <input type="text" name="billing[address]" class="uk-input billing-edit uk-hidden"
                                       value="<?php echo esc_attr($billingAddress); ?>">
                            </div>
                            <div class="uk-width-1-2@s">
                                <label class="uk-form-label"><?php esc_html_e('Postcode', 'stride'); ?></label>
                                <p class="uk-margin-remove billing-view"><?php echo esc_html($postalCode ?: '-'); ?></p>
                                <input type="text" name="billing[postal_code]" class="uk-input billing-edit uk-hidden"
                                       value="<?php echo esc_attr($postalCode); ?>">
                            </div>
                            <div class="uk-width-1-2@s">
                                <label class="uk-form-label"><?php esc_html_e('Plaats', 'stride'); ?></label>
                                <p class="uk-margin-remove billing-view"><?php echo esc_html($billingCity ?: '-'); ?></p>
                                <input type="text" name="billing[city]" class="uk-input billing-edit uk-hidden"
                                       value="<?php echo esc_attr($billingCity); ?>">
                            </div>
                        </div>

                        <div class="uk-margin-top billing-edit uk-hidden">
                            <button type="submit" class="uk-button uk-button-primary uk-button-small" id="billing-save-btn">
                                <?php esc_html_e('Opslaan', 'stride'); ?>
                            </button>
                            <button type="button" class="uk-button uk-button-default uk-button-small" id="billing-cancel-btn">
                                <?php esc_html_e('Annuleren', 'stride'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Course Info -->
            <?php if ($editionId > 0): ?>
            <div class="stride-card uk-margin-bottom">
                <div class="stride-card-header">
                    <h2 class="stride-card-title">
                        <span uk-icon="icon: album"></span>
                        <?php esc_html_e('Cursus', 'stride'); ?>
                    </h2>
                </div>
                <div class="uk-padding">
                    <h3 class="uk-h4 uk-margin-remove-top"><?php echo esc_html($editionTitle ?: __('Cursusinschrijving', 'stride')); ?></h3>
                    <a href="<?php echo esc_url(get_permalink($editionId)); ?>" class="uk-button uk-button-text uk-margin-small-top">
                        <?php esc_html_e('Bekijk cursus', 'stride'); ?>
                        <span uk-icon="icon: arrow-right; ratio: 0.8"></span>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Actions (mobile: at bottom) -->
            <div class="uk-hidden@m uk-margin-top">
                <?php stride_render_quote_actions($isEditable, $quoteId, $editionId); ?>
            </div>
        </div>

        <!-- Side Panel: Quote Details -->
        <div class="uk-width-1-1 uk-width-1-3@m">
            <div class="stride-card stride-card--sticky" uk-sticky="offset: 100; bottom: true; media: @m">
                <div class="stride-card-header">
                    <h2 class="stride-card-title">
                        <?php esc_html_e('Overzicht', 'stride'); ?>
                    </h2>
                </div>
                <div class="uk-padding">
                    <!-- Line Items -->
                    <div class="stride-quote-items">
                        <?php if (!empty($items)): ?>
                            <?php foreach ($items as $item): ?>
                                <div class="stride-quote-item">
                                    <div class="stride-quote-item__title">
                                        <?php echo esc_html($item['title'] ?? $editionTitle); ?>
                                    </div>
                                    <div class="stride-quote-item__details">
                                        <span><?php echo esc_html($item['quantity'] ?? 1); ?>x</span>
                                        <span>
                                            <?php
                                            $itemTotal = ($item['total'] ?? $item['unit_price'] ?? 0) / 100;
                                            echo '€ ' . number_format($itemTotal, 2, ',', '.');
                                            ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="stride-quote-item">
                                <div class="stride-quote-item__title">
                                    <?php echo esc_html($editionTitle ?: __('Cursusinschrijving', 'stride')); ?>
                                </div>
                                <div class="stride-quote-item__details">
                                    <span>1x</span>
                                    <span><?php echo $subtotal ? esc_html($subtotal->format()) : '€ 0,00'; ?></span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <hr class="uk-margin-small">

                    <!-- Totals -->
                    <div class="stride-quote-totals">
                        <div class="stride-quote-total-row">
                            <span><?php esc_html_e('Subtotaal', 'stride'); ?></span>
                            <span><?php echo $subtotal ? esc_html($subtotal->format()) : '€ 0,00'; ?></span>
                        </div>
                        <?php if ($discount && $discount->inCents() > 0): ?>
                            <div class="stride-quote-total-row stride-quote-total-row--discount">
                                <span>
                                    <?php esc_html_e('Korting', 'stride'); ?>
                                    <?php if ($voucherCode): ?>
                                        <small class="uk-text-muted">(<?php echo esc_html($voucherCode); ?>)</small>
                                    <?php endif; ?>
                                </span>
                                <span>- <?php echo esc_html($discount->format()); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="stride-quote-total-row">
                            <span><?php esc_html_e('BTW (21%)', 'stride'); ?></span>
                            <span><?php echo $tax ? esc_html($tax->format()) : '€ 0,00'; ?></span>
                        </div>
                        <div class="stride-quote-total-row stride-quote-total-row--total">
                            <span><?php esc_html_e('Totaal', 'stride'); ?></span>
                            <span><?php echo $total ? esc_html($total->format()) : '€ 0,00'; ?></span>
                        </div>
                    </div>

                    <?php if ($isEditable): ?>
                    <!-- Voucher Code Input -->
                    <div class="uk-margin-top">
                        <form id="voucher-form" class="uk-form-stacked">
                            <input type="hidden" name="action" value="stride_apply_voucher">
                            <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('stride_quote_update')); ?>">
                            <input type="hidden" name="quote_id" value="<?php echo esc_attr($quoteId); ?>">
                            <label class="uk-form-label"><?php esc_html_e('Kortingscode', 'stride'); ?></label>
                            <div class="uk-grid-small" uk-grid>
                                <div class="uk-width-expand">
                                    <input type="text" name="voucher_code" class="uk-input uk-form-small"
                                           placeholder="<?php esc_attr_e('Code', 'stride'); ?>">
                                </div>
                                <div class="uk-width-auto">
                                    <button type="submit" class="uk-button uk-button-secondary uk-button-small" id="apply-voucher-btn">
                                        <?php esc_html_e('Toepassen', 'stride'); ?>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>

                    <?php if ($validUntil && $isEditable): ?>
                        <div class="uk-margin-top uk-text-small <?php echo $isExpired ? 'uk-text-danger' : 'uk-text-muted'; ?>">
                            <?php if ($isExpired): ?>
                                <span uk-icon="icon: warning; ratio: 0.8"></span>
                                <?php esc_html_e('Verlopen op', 'stride'); ?> <?php echo esc_html(date_i18n('j M Y', $validDate)); ?>
                            <?php else: ?>
                                <span uk-icon="icon: clock; ratio: 0.8"></span>
                                <?php esc_html_e('Geldig tot', 'stride'); ?> <?php echo esc_html(date_i18n('j M Y', $validDate)); ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Actions (desktop: in sidebar) -->
                    <div class="uk-visible@m uk-margin-medium-top">
                        <?php stride_render_quote_actions($isEditable, $quoteId, $editionId); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
/**
 * Render quote action buttons
 */
function stride_render_quote_actions(bool $isEditable, int $quoteId, int $editionId): void
{
    ?>
    <div class="stride-quote-actions">
        <!-- PDF Download Button -->
        <button type="button" class="uk-button uk-button-default uk-width-1-1 uk-margin-small-bottom" id="download-pdf-btn">
            <span uk-icon="icon: download"></span>
            <?php esc_html_e('Download PDF', 'stride'); ?>
        </button>

        <?php if ($isEditable): ?>
        <!-- Cancel Button -->
        <button type="button" class="uk-button uk-button-danger uk-button-small uk-width-1-1" uk-toggle="target: #cancel-modal">
            <?php esc_html_e('Inschrijving annuleren', 'stride'); ?>
        </button>
        <?php endif; ?>
    </div>
    <?php
}
?>

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

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Inline billing edit handling
    const billingCard = document.getElementById('billing-card');
    const editBtn = document.getElementById('billing-edit-btn');
    const cancelBtn = document.getElementById('billing-cancel-btn');
    const billingForm = document.getElementById('billing-form');
    const saveBtn = document.getElementById('billing-save-btn');

    function toggleBillingEdit(editing) {
        if (!billingCard) return;
        billingCard.dataset.editing = editing;
        const viewEls = billingCard.querySelectorAll('.billing-view');
        const editEls = billingCard.querySelectorAll('.billing-edit');

        viewEls.forEach(el => el.classList.toggle('uk-hidden', editing));
        editEls.forEach(el => el.classList.toggle('uk-hidden', !editing));

        if (editBtn) {
            editBtn.innerHTML = editing
                ? '<span uk-icon="icon: close; ratio: 0.8"></span> <?php echo esc_js(__('Sluiten', 'stride')); ?>'
                : '<span uk-icon="icon: pencil; ratio: 0.8"></span> <?php echo esc_js(__('Wijzigen', 'stride')); ?>';
        }
    }

    if (editBtn) {
        editBtn.addEventListener('click', function() {
            const isEditing = billingCard.dataset.editing === 'true';
            toggleBillingEdit(!isEditing);
        });
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            toggleBillingEdit(false);
        });
    }

    if (billingForm) {
        billingForm.addEventListener('submit', function(e) {
            e.preventDefault();

            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span uk-spinner="ratio: 0.5"></span>';

            const formData = new FormData(billingForm);
            formData.set('nonce', formData.get('billing_nonce'));

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
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    UIkit.notification({
                        message: data.data?.message || '<?php echo esc_js(__('Er is een fout opgetreden', 'stride')); ?>',
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

<!-- PDF download handler -->
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

<style>
/* Quote detail sidepanel styles */
.stride-quote-items {
    margin-bottom: 1rem;
}

.stride-quote-item {
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--stride-border-color, #e5e5e5);
}

.stride-quote-item:last-child {
    border-bottom: none;
}

.stride-quote-item__title {
    font-weight: 500;
    margin-bottom: 0.25rem;
}

.stride-quote-item__details {
    display: flex;
    justify-content: space-between;
    font-size: 0.875rem;
    color: var(--stride-text-muted, #666);
}

.stride-quote-totals {
    margin-top: 1rem;
}

.stride-quote-total-row {
    display: flex;
    justify-content: space-between;
    padding: 0.375rem 0;
    font-size: 0.9375rem;
}

.stride-quote-total-row--discount {
    color: var(--stride-success, #32d296);
}

.stride-quote-total-row--total {
    font-weight: 600;
    font-size: 1.125rem;
    border-top: 2px solid var(--stride-border-color, #e5e5e5);
    padding-top: 0.75rem;
    margin-top: 0.5rem;
}

.stride-quote-actions {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.stride-card--sticky {
    position: sticky;
    top: 100px;
}

@media (max-width: 959px) {
    .stride-card--sticky {
        position: static;
    }
}
</style>
