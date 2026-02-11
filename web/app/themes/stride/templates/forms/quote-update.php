<?php
/**
 * Quote Update Form Template
 *
 * Allows users to update billing information on their quotes.
 *
 * Variables available:
 * - $quote: Quote data array containing:
 *   - id, number, status
 *   - billing: Array with organisation, address, city, postal_code, vat_number, gln_number
 *   - order_number, voucher_code
 *   - total_formatted
 *   - course: Array with title
 *
 * @package stride
 */

defined('ABSPATH') || exit;

$billing = $quote['billing'] ?? [];
$nonce = wp_create_nonce('stride_quote_update');
?>

<div class="stride-quote-update">
    <div class="stride-quote-header">
        <h2><?php esc_html_e('Offerte bijwerken', 'stride'); ?></h2>
        <div class="stride-quote-meta">
            <span class="stride-quote-number">
                <strong><?php esc_html_e('Offertenummer:', 'stride'); ?></strong>
                <?php echo esc_html($quote['number']); ?>
            </span>
            <?php if (!empty($quote['course']['title'])): ?>
            <span class="stride-quote-course">
                <strong><?php esc_html_e('Cursus:', 'stride'); ?></strong>
                <?php echo esc_html($quote['course']['title']); ?>
            </span>
            <?php endif; ?>
            <span class="stride-quote-total">
                <strong><?php esc_html_e('Totaal:', 'stride'); ?></strong>
                <?php echo esc_html($quote['total_formatted']); ?>
            </span>
        </div>
    </div>

    <form class="stride-quote-update-form" method="post" id="stride-quote-update-form">
        <input type="hidden" name="action" value="stride_update_quote">
        <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
        <input type="hidden" name="quote_id" value="<?php echo esc_attr($quote['id']); ?>">

        <!-- Organization Section -->
        <fieldset class="stride-form-section">
            <legend><?php esc_html_e('Organisatie & Facturatie', 'stride'); ?></legend>

            <div class="stride-form-row">
                <label for="company_name"><?php esc_html_e('Organisatienaam', 'stride'); ?></label>
                <input type="text"
                       id="company_name"
                       name="company_name"
                       value="<?php echo esc_attr($billing['organisation'] ?? ''); ?>"
                       placeholder="<?php esc_attr_e('Naam van uw organisatie', 'stride'); ?>">
            </div>

            <div class="stride-form-row">
                <label for="vat_number"><?php esc_html_e('BTW-nummer', 'stride'); ?></label>
                <input type="text"
                       id="vat_number"
                       name="vat_number"
                       value="<?php echo esc_attr($billing['vat_number'] ?? ''); ?>"
                       placeholder="BE0123456789"
                       pattern="[A-Za-z]{2}[A-Za-z0-9]+"
                       data-vat-lookup="true">
                <small class="stride-form-help">
                    <?php esc_html_e('Na invullen worden bedrijfsgegevens automatisch opgehaald.', 'stride'); ?>
                </small>
                <div class="stride-vat-result" style="display: none;"></div>
            </div>

            <div class="stride-form-row">
                <label for="address"><?php esc_html_e('Adres', 'stride'); ?></label>
                <input type="text"
                       id="address"
                       name="address"
                       value="<?php echo esc_attr($billing['address'] ?? ''); ?>"
                       placeholder="<?php esc_attr_e('Straat en huisnummer', 'stride'); ?>">
            </div>

            <div class="stride-form-row stride-form-row--split">
                <div class="stride-form-col">
                    <label for="postal_code"><?php esc_html_e('Postcode', 'stride'); ?></label>
                    <input type="text"
                           id="postal_code"
                           name="postal_code"
                           value="<?php echo esc_attr($billing['postal_code'] ?? ''); ?>"
                           placeholder="1000">
                </div>
                <div class="stride-form-col stride-form-col--wide">
                    <label for="city"><?php esc_html_e('Stad', 'stride'); ?></label>
                    <input type="text"
                           id="city"
                           name="city"
                           value="<?php echo esc_attr($billing['city'] ?? ''); ?>"
                           placeholder="<?php esc_attr_e('Stad', 'stride'); ?>">
                </div>
            </div>

            <div class="stride-form-row">
                <label for="gln_number"><?php esc_html_e('GLN/Peppol-nummer', 'stride'); ?> <span class="stride-optional">(<?php esc_html_e('optioneel', 'stride'); ?>)</span></label>
                <input type="text"
                       id="gln_number"
                       name="gln_number"
                       value="<?php echo esc_attr($billing['gln_number'] ?? ''); ?>"
                       placeholder="<?php esc_attr_e('13-cijferig GLN-nummer', 'stride'); ?>">
                <small class="stride-form-help">
                    <?php esc_html_e('Vereist voor e-facturatie naar overheidsinstellingen.', 'stride'); ?>
                </small>
            </div>
        </fieldset>

        <!-- Order References Section -->
        <fieldset class="stride-form-section">
            <legend><?php esc_html_e('Bestelgegevens', 'stride'); ?></legend>

            <div class="stride-form-row">
                <label for="order_number"><?php esc_html_e('Bestelnummer / PO-nummer', 'stride'); ?> <span class="stride-optional">(<?php esc_html_e('optioneel', 'stride'); ?>)</span></label>
                <input type="text"
                       id="order_number"
                       name="order_number"
                       value="<?php echo esc_attr($quote['order_number'] ?? ''); ?>"
                       placeholder="<?php esc_attr_e('Uw interne bestelnummer', 'stride'); ?>">
                <small class="stride-form-help">
                    <?php esc_html_e('Dit nummer wordt op de factuur vermeld.', 'stride'); ?>
                </small>
            </div>

            <div class="stride-form-row">
                <label for="voucher_code"><?php esc_html_e('Vouchercode', 'stride'); ?> <span class="stride-optional">(<?php esc_html_e('optioneel', 'stride'); ?>)</span></label>
                <input type="text"
                       id="voucher_code"
                       name="voucher_code"
                       value="<?php echo esc_attr($quote['voucher_code'] ?? ''); ?>"
                       placeholder="<?php esc_attr_e('VOUCHER123', 'stride'); ?>">
                <small class="stride-form-help">
                    <?php esc_html_e('Heeft u een voucher? Voer de code hier in.', 'stride'); ?>
                </small>
            </div>
        </fieldset>

        <!-- Form Actions -->
        <div class="stride-form-actions">
            <button type="submit" class="stride-btn stride-btn--primary">
                <span class="stride-btn-text"><?php esc_html_e('Gegevens opslaan', 'stride'); ?></span>
                <span class="stride-btn-loading" style="display: none;">
                    <span class="stride-spinner"></span>
                    <?php esc_html_e('Bezig met opslaan...', 'stride'); ?>
                </span>
            </button>
        </div>

        <!-- Messages -->
        <div class="stride-form-messages" role="alert" aria-live="polite"></div>
    </form>
</div>

<style>
.stride-quote-update {
    max-width: 600px;
    margin: 0 auto;
}

.stride-quote-header {
    margin-bottom: 2rem;
}

.stride-quote-header h2 {
    margin: 0 0 0.5rem 0;
    font-size: 1.5rem;
}

.stride-quote-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    font-size: 0.9rem;
    color: #666;
}

.stride-quote-meta span {
    display: inline-flex;
    gap: 0.25rem;
}

.stride-form-section {
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    background: #fafafa;
}

.stride-form-section legend {
    font-weight: 600;
    font-size: 1rem;
    padding: 0 0.5rem;
    margin-left: -0.5rem;
}

.stride-form-row {
    margin-bottom: 1rem;
}

.stride-form-row:last-child {
    margin-bottom: 0;
}

.stride-form-row label {
    display: block;
    font-weight: 500;
    margin-bottom: 0.25rem;
}

.stride-optional {
    font-weight: normal;
    color: #888;
    font-size: 0.85em;
}

.stride-form-row input[type="text"] {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: 1rem;
    transition: border-color 0.2s;
}

.stride-form-row input[type="text"]:focus {
    outline: none;
    border-color: #3498db;
    box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
}

.stride-form-help {
    display: block;
    margin-top: 0.25rem;
    font-size: 0.8rem;
    color: #666;
}

.stride-form-row--split {
    display: flex;
    gap: 1rem;
}

.stride-form-col {
    flex: 1;
}

.stride-form-col--wide {
    flex: 2;
}

.stride-form-actions {
    margin-top: 1.5rem;
}

.stride-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 4px;
    font-size: 1rem;
    font-weight: 500;
    cursor: pointer;
    transition: background-color 0.2s;
}

.stride-btn--primary {
    background: #3498db;
    color: #fff;
}

.stride-btn--primary:hover {
    background: #2980b9;
}

.stride-btn--primary:disabled {
    background: #bdc3c7;
    cursor: not-allowed;
}

.stride-spinner {
    display: inline-block;
    width: 1em;
    height: 1em;
    border: 2px solid currentColor;
    border-right-color: transparent;
    border-radius: 50%;
    animation: stride-spin 0.75s linear infinite;
    margin-right: 0.5rem;
}

@keyframes stride-spin {
    to { transform: rotate(360deg); }
}

.stride-form-messages {
    margin-top: 1rem;
    padding: 1rem;
    border-radius: 4px;
    display: none;
}

.stride-form-messages--success {
    display: block;
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.stride-form-messages--error {
    display: block;
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.stride-vat-result {
    margin-top: 0.5rem;
    padding: 0.75rem;
    background: #e8f4fd;
    border-radius: 4px;
    font-size: 0.9rem;
}

.stride-vat-result--invalid {
    background: #fdf2f2;
    color: #c0392b;
}
</style>

<script>
(function() {
    const form = document.getElementById('stride-quote-update-form');
    if (!form) return;

    const submitBtn = form.querySelector('button[type="submit"]');
    const btnText = submitBtn.querySelector('.stride-btn-text');
    const btnLoading = submitBtn.querySelector('.stride-btn-loading');
    const messagesEl = form.querySelector('.stride-form-messages');

    form.addEventListener('submit', async function(e) {
        e.preventDefault();

        // Show loading state
        btnText.style.display = 'none';
        btnLoading.style.display = 'inline-flex';
        submitBtn.disabled = true;
        messagesEl.className = 'stride-form-messages';
        messagesEl.style.display = 'none';

        try {
            const formData = new FormData(form);
            formData.append('action', 'stride_update_quote');

            const response = await fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                messagesEl.textContent = result.data.message || '<?php echo esc_js(__('Gegevens succesvol bijgewerkt.', 'stride')); ?>';
                messagesEl.className = 'stride-form-messages stride-form-messages--success';
            } else {
                messagesEl.textContent = result.data.message || '<?php echo esc_js(__('Er is een fout opgetreden.', 'stride')); ?>';
                messagesEl.className = 'stride-form-messages stride-form-messages--error';
            }
            messagesEl.style.display = 'block';

        } catch (error) {
            messagesEl.textContent = '<?php echo esc_js(__('Verbindingsfout. Probeer het opnieuw.', 'stride')); ?>';
            messagesEl.className = 'stride-form-messages stride-form-messages--error';
            messagesEl.style.display = 'block';
        } finally {
            btnText.style.display = 'inline';
            btnLoading.style.display = 'none';
            submitBtn.disabled = false;
        }
    });

    // VAT lookup on blur
    const vatInput = form.querySelector('[data-vat-lookup]');
    const vatResult = form.querySelector('.stride-vat-result');

    if (vatInput && vatResult) {
        vatInput.addEventListener('blur', async function() {
            const vat = this.value.trim();
            if (!vat || vat.length < 4) {
                vatResult.style.display = 'none';
                return;
            }

            // Basic format validation
            const normalized = vat.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
            if (!/^[A-Z]{2}[A-Z0-9]+$/.test(normalized)) {
                vatResult.textContent = '<?php echo esc_js(__('Ongeldig BTW-nummer formaat. Gebruik landcode gevolgd door nummer (bijv. BE0123456789).', 'stride')); ?>';
                vatResult.className = 'stride-vat-result stride-vat-result--invalid';
                vatResult.style.display = 'block';
                return;
            }

            vatResult.textContent = '<?php echo esc_js(__('BTW-nummer wordt gevalideerd...', 'stride')); ?>';
            vatResult.className = 'stride-vat-result';
            vatResult.style.display = 'block';
        });
    }
})();
</script>
