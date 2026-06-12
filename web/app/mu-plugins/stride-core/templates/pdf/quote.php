<?php
/**
 * Quote PDF Template
 *
 * Variables available:
 * - $quote: Enriched quote data array containing:
 *   - id, number, status, user_id, course_id
 *   - items: Array of line items
 *   - subtotal, tax, total (raw values)
 *   - subtotal_formatted, tax_formatted, total_formatted
 *   - tax_rate
 *   - discount, discount_formatted
 *   - valid_until, valid_until_date
 *   - created_at, created_date
 *   - billing: Array with organisation, address, city, postal_code, vat_number, gln_number, email
 *   - order_number, voucher_code
 *   - user: Array with name, email
 *   - course: Array with title
 *   - company: Array with name, address, city, postal_code, country, vat, email, phone, bank_account
 * - $formatCurrency: Closure (int $cents) => string, e.g. "€ 45,00"
 *
 * @package stride
 */

defined('ABSPATH') || exit;

// Defensive: Ensure $quote is an array
if (!isset($quote) || !is_array($quote)) {
    echo '<p>Error: Invalid quote data</p>';
    return;
}

// Extract and validate sub-arrays with defaults
$billing = is_array($quote['billing'] ?? null) ? $quote['billing'] : [];
$company = is_array($quote['company'] ?? null) ? $quote['company'] : [];
$user = is_array($quote['user'] ?? null) ? $quote['user'] : [];
$items = is_array($quote['items'] ?? null) ? $quote['items'] : [];

// Ensure critical values have defaults
$quoteNumber = $quote['number'] ?? __('N/A', 'stride');
$createdDate = $quote['created_date'] ?? date('d-m-Y');
$validUntilDate = $quote['valid_until_date'] ?? '';
$taxRate = $quote['tax_rate'] ?? 21;
$subtotalFormatted = $quote['subtotal_formatted'] ?? '€ 0,00';
$taxFormatted = $quote['tax_formatted'] ?? '€ 0,00';
$totalFormatted = $quote['total_formatted'] ?? '€ 0,00';
$orderNumber = $quote['order_number'] ?? '';
$companyName = $company['name'] ?? __('VAD', 'stride');
$companyEmail = $company['email'] ?? '';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title><?php echo esc_html(sprintf(__('Offerte %s', 'stride'), $quoteNumber)); ?></title>
    <style>
        /* Reset & Base */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 9pt;
            line-height: 1.5;
            color: #1a1a1a;
            background: #fff;
        }

        /* Container */
        .page {
            padding: 25mm 20mm 20mm 20mm;
        }

        /* Header */
        .header {
            margin-bottom: 15mm;
        }

        .header-table {
            width: 100%;
            border-collapse: collapse;
        }

        .company-logo {
            width: 60%;
            vertical-align: top;
        }

        .company-logo h1 {
            font-size: 18pt;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 2mm;
        }

        .company-details {
            font-size: 8pt;
            color: #666;
            line-height: 1.4;
        }

        .document-info {
            width: 40%;
            vertical-align: top;
            text-align: right;
        }

        .document-type {
            font-size: 24pt;
            font-weight: bold;
            color: #3498db;
            margin-bottom: 3mm;
        }

        .document-number {
            font-size: 12pt;
            color: #333;
            margin-bottom: 5mm;
        }

        .document-dates {
            font-size: 9pt;
        }

        .document-dates .label {
            color: #666;
        }

        /* Addresses */
        .addresses {
            margin-bottom: 10mm;
        }

        .addresses-table {
            width: 100%;
            border-collapse: collapse;
        }

        .address-cell {
            width: 50%;
            vertical-align: top;
            padding-right: 10mm;
        }

        .address-label {
            font-size: 7pt;
            text-transform: uppercase;
            letter-spacing: 0.5pt;
            color: #888;
            margin-bottom: 2mm;
            border-bottom: 0.5pt solid #ddd;
            padding-bottom: 1mm;
        }

        .address-content {
            font-size: 9pt;
            line-height: 1.6;
        }

        .address-content strong {
            font-size: 10pt;
            display: block;
            margin-bottom: 1mm;
        }

        .reference-info {
            margin-top: 3mm;
            padding-top: 3mm;
            border-top: 0.5pt solid #eee;
            font-size: 8pt;
        }

        /* Items Table */
        .items-section {
            margin-bottom: 10mm;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
        }

        .items-table th {
            background: #f8f9fa;
            border: 0.5pt solid #dee2e6;
            padding: 3mm 2mm;
            text-align: left;
            font-weight: bold;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 0.3pt;
            color: #495057;
        }

        .items-table th.amount {
            text-align: right;
        }

        .items-table td {
            border: 0.5pt solid #dee2e6;
            padding: 3mm 2mm;
            vertical-align: top;
        }

        .items-table td.amount {
            text-align: right;
            white-space: nowrap;
        }

        .item-description {
            font-weight: normal;
        }

        /* Totals */
        .totals-section {
            margin-bottom: 10mm;
        }

        .totals-table {
            width: 200pt;
            margin-left: auto;
            border-collapse: collapse;
            font-size: 9pt;
        }

        .totals-table td {
            padding: 2mm 0;
        }

        .totals-table .label {
            text-align: left;
            color: #666;
        }

        .totals-table .value {
            text-align: right;
            padding-left: 10mm;
        }

        .totals-table .total-row {
            border-top: 1pt solid #333;
            font-weight: bold;
            font-size: 11pt;
        }

        .totals-table .total-row td {
            padding-top: 3mm;
        }

        /* Payment Info */
        .payment-section {
            background: #f8f9fa;
            padding: 5mm;
            margin-bottom: 10mm;
            border-radius: 2mm;
        }

        .payment-section h3 {
            font-size: 10pt;
            margin-bottom: 3mm;
            color: #333;
        }

        .payment-details {
            font-size: 9pt;
        }

        .payment-details .row {
            margin-bottom: 1mm;
        }

        .payment-details .label {
            color: #666;
            display: inline-block;
            width: 80pt;
        }

        /* Notes */
        .notes-section {
            margin-bottom: 10mm;
            font-size: 8pt;
            color: #666;
        }

        .valid-until {
            font-style: italic;
        }

        /* Footer */
        .footer {
            position: fixed;
            bottom: 15mm;
            left: 20mm;
            right: 20mm;
            border-top: 0.5pt solid #ddd;
            padding-top: 3mm;
            font-size: 7pt;
            color: #888;
            text-align: center;
        }

        .footer-content {
            display: block;
        }
    </style>
</head>
<body>
    <div class="page">
        <!-- Header -->
        <div class="header">
            <table class="header-table">
                <tr>
                    <td class="company-logo">
                        <?php if (!empty($company['logo_path'])): ?>
                            <img src="<?php echo esc_attr($company['logo_path']); ?>" alt="<?php echo esc_attr($companyName); ?>" style="max-height: 50px; max-width: 180px; margin-bottom: 2mm;">
                        <?php else: ?>
                            <h1><?php echo esc_html($companyName); ?></h1>
                        <?php endif; ?>
                        <div class="company-details">
                            <?php if (!empty($company['address'])): ?>
                                <?php echo esc_html($company['address']); ?><br>
                            <?php endif; ?>
                            <?php if (!empty($company['postal_code']) || !empty($company['city'])): ?>
                                <?php echo esc_html(trim($company['postal_code'] . ' ' . $company['city'])); ?><br>
                            <?php endif; ?>
                            <?php if (!empty($company['country'])): ?>
                                <?php echo esc_html($company['country']); ?><br>
                            <?php endif; ?>
                            <?php if (!empty($company['vat'])): ?>
                                BTW: <?php echo esc_html($company['vat']); ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="document-info">
                        <div class="document-type"><?php esc_html_e('OFFERTE', 'stride'); ?></div>
                        <div class="document-number"><?php echo esc_html($quoteNumber); ?></div>
                        <div class="document-dates">
                            <div><span class="label"><?php esc_html_e('Datum:', 'stride'); ?></span> <?php echo esc_html($createdDate); ?></div>
                            <div><span class="label"><?php esc_html_e('Geldig tot:', 'stride'); ?></span> <?php echo esc_html($validUntilDate); ?></div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Addresses -->
        <div class="addresses">
            <table class="addresses-table">
                <tr>
                    <td class="address-cell">
                        <div class="address-label"><?php esc_html_e('Factuuradres', 'stride'); ?></div>
                        <div class="address-content">
                            <?php if (!empty($billing['company'])): ?>
                                <strong><?php echo esc_html($billing['company']); ?></strong><br>
                            <?php endif; ?>
                            <?php if (!empty($user['name'])): ?>
                                <?php echo esc_html($user['name']); ?><br>
                            <?php endif; ?>
                            <?php if (!empty($billing['address'])): ?>
                                <?php echo esc_html($billing['address']); ?><br>
                            <?php endif; ?>
                            <?php if (!empty($billing['postal_code']) || !empty($billing['city'])): ?>
                                <?php echo esc_html(trim(($billing['postal_code'] ?? '') . ' ' . ($billing['city'] ?? ''))); ?><br>
                            <?php endif; ?>
                            <?php if (!empty($billing['vat_number'])): ?>
                                <div class="reference-info">
                                    BTW: <?php echo esc_html($billing['vat_number']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="address-cell">
                        <?php if (!empty($orderNumber) || !empty($billing['gln_number'])): ?>
                            <div class="address-label"><?php esc_html_e('Referenties', 'stride'); ?></div>
                            <div class="address-content">
                                <?php if (!empty($orderNumber)): ?>
                                    <div><strong><?php esc_html_e('Bestelnummer:', 'stride'); ?></strong> <?php echo esc_html($orderNumber); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($billing['gln_number'])): ?>
                                    <div><strong><?php esc_html_e('GLN:', 'stride'); ?></strong> <?php echo esc_html($billing['gln_number']); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Items -->
        <div class="items-section">
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: 55%;"><?php esc_html_e('Omschrijving', 'stride'); ?></th>
                        <th style="width: 15%;" class="amount"><?php esc_html_e('Aantal', 'stride'); ?></th>
                        <th style="width: 15%;" class="amount"><?php esc_html_e('Prijs', 'stride'); ?></th>
                        <th style="width: 15%;" class="amount"><?php esc_html_e('Totaal', 'stride'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="4"><?php esc_html_e('Geen items', 'stride'); ?></td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($items as $item): ?>
                    <?php if (!is_array($item)) {
                        continue;
                    } ?>
                    <tr>
                        <td class="item-description"><?php echo esc_html($item['title'] ?? ''); ?></td>
                        <td class="amount"><?php echo esc_html($item['quantity'] ?? 1); ?></td>
                        <td class="amount"><?php echo esc_html($formatCurrency($item['unit_price'] ?? 0)); ?></td>
                        <td class="amount"><?php echo esc_html($formatCurrency($item['total'] ?? 0)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Totals -->
        <div class="totals-section">
            <table class="totals-table">
                <tr>
                    <td class="label"><?php esc_html_e('Subtotaal', 'stride'); ?></td>
                    <td class="value"><?php echo esc_html($subtotalFormatted); ?></td>
                </tr>
                <?php if (!empty($quote['discount']) && $quote['discount'] > 0): ?>
                <tr>
                    <td class="label"><?php esc_html_e('Korting', 'stride'); ?></td>
                    <td class="value">-<?php echo esc_html($quote['discount_formatted'] ?? '€ 0,00'); ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td class="label"><?php echo esc_html(sprintf(__('BTW (%s%%)', 'stride'), $taxRate)); ?></td>
                    <td class="value"><?php echo esc_html($taxFormatted); ?></td>
                </tr>
                <tr class="total-row">
                    <td class="label"><?php esc_html_e('Totaal', 'stride'); ?></td>
                    <td class="value"><?php echo esc_html($totalFormatted); ?></td>
                </tr>
            </table>
        </div>

        <!-- Payment Info -->
        <?php if (!empty($company['bank_account'])): ?>
        <div class="payment-section">
            <h3><?php esc_html_e('Betalingsgegevens', 'stride'); ?></h3>
            <div class="payment-details">
                <div class="row">
                    <span class="label"><?php esc_html_e('Rekeningnummer:', 'stride'); ?></span>
                    <?php echo esc_html($company['bank_account']); ?>
                </div>
                <div class="row">
                    <span class="label"><?php esc_html_e('Mededeling:', 'stride'); ?></span>
                    <?php echo esc_html($quoteNumber); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Customer Notes -->
        <?php $customerNotes = $quote['customer_notes'] ?? []; ?>
        <?php if (!empty($customerNotes)): ?>
        <div class="notes-section" style="margin-bottom: 5mm;">
            <h3 style="font-size: 10pt; margin-bottom: 3mm; color: #333;"><?php esc_html_e('Opmerkingen', 'stride'); ?></h3>
            <?php foreach ($customerNotes as $note): ?>
            <p style="font-size: 9pt; margin: 0 0 2mm; color: #444;"><?php echo esc_html($note['content']); ?></p>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Validity -->
        <div class="notes-section">
            <p class="valid-until">
                <?php if (!empty($validUntilDate) && !empty($companyEmail)): ?>
                <?php echo esc_html(sprintf(
                    __('Deze offerte is geldig tot %s. Bij vragen kunt u contact met ons opnemen via %s.', 'stride'),
                    $validUntilDate,
                    $companyEmail,
                )); ?>
                <?php elseif (!empty($validUntilDate)): ?>
                <?php echo esc_html(sprintf(
                    __('Deze offerte is geldig tot %s.', 'stride'),
                    $validUntilDate,
                )); ?>
                <?php endif; ?>
            </p>
        </div>

        <!-- Footer -->
        <div class="footer">
            <span class="footer-content">
                <?php echo esc_html($companyName); ?>
                <?php if (!empty($companyEmail)): ?>
                    &nbsp;|&nbsp;<?php echo esc_html($companyEmail); ?>
                <?php endif; ?>
                <?php if (!empty($company['phone'])): ?>
                    &nbsp;|&nbsp;<?php echo esc_html($company['phone']); ?>
                <?php endif; ?>
                <?php if (!empty($company['vat'])): ?>
                    &nbsp;|&nbsp;BTW <?php echo esc_html($company['vat']); ?>
                <?php endif; ?>
            </span>
        </div>
    </div>
</body>
</html>
