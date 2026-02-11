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
 *   - valid_until, valid_until_date
 *   - created_at, created_date
 *   - billing: Array with organisation, address, city, postal_code, vat_number, gln_number, email
 *   - order_number, voucher_code
 *   - user: Array with name, email
 *   - course: Array with title
 *   - company: Array with name, address, city, postal_code, country, vat, email, phone, bank_account
 *
 * @package stride
 */

defined('ABSPATH') || exit;

$billing = $quote['billing'] ?? [];
$company = $quote['company'] ?? [];
$user = $quote['user'] ?? [];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title><?php echo esc_html(sprintf(__('Offerte %s', 'stride'), $quote['number'])); ?></title>
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
                        <h1><?php echo esc_html($company['name']); ?></h1>
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
                        <div class="document-number"><?php echo esc_html($quote['number']); ?></div>
                        <div class="document-dates">
                            <div><span class="label"><?php esc_html_e('Datum:', 'stride'); ?></span> <?php echo esc_html($quote['created_date']); ?></div>
                            <div><span class="label"><?php esc_html_e('Geldig tot:', 'stride'); ?></span> <?php echo esc_html($quote['valid_until_date']); ?></div>
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
                            <?php if (!empty($billing['organisation'])): ?>
                                <strong><?php echo esc_html($billing['organisation']); ?></strong>
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
                        <?php if (!empty($quote['order_number']) || !empty($billing['gln_number'])): ?>
                            <div class="address-label"><?php esc_html_e('Referenties', 'stride'); ?></div>
                            <div class="address-content">
                                <?php if (!empty($quote['order_number'])): ?>
                                    <div><strong><?php esc_html_e('Bestelnummer:', 'stride'); ?></strong> <?php echo esc_html($quote['order_number']); ?></div>
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
                    <?php foreach ($quote['items'] as $item): ?>
                    <tr>
                        <td class="item-description"><?php echo esc_html($item['title'] ?? ''); ?></td>
                        <td class="amount"><?php echo esc_html($item['quantity'] ?? 1); ?></td>
                        <td class="amount"><?php echo esc_html($this->formatCurrency($item['unit_price'] ?? 0)); ?></td>
                        <td class="amount"><?php echo esc_html($this->formatCurrency($item['total'] ?? 0)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Totals -->
        <div class="totals-section">
            <table class="totals-table">
                <tr>
                    <td class="label"><?php esc_html_e('Subtotaal', 'stride'); ?></td>
                    <td class="value"><?php echo esc_html($quote['subtotal_formatted']); ?></td>
                </tr>
                <tr>
                    <td class="label"><?php echo esc_html(sprintf(__('BTW (%s%%)', 'stride'), $quote['tax_rate'])); ?></td>
                    <td class="value"><?php echo esc_html($quote['tax_formatted']); ?></td>
                </tr>
                <tr class="total-row">
                    <td class="label"><?php esc_html_e('Totaal', 'stride'); ?></td>
                    <td class="value"><?php echo esc_html($quote['total_formatted']); ?></td>
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
                    <?php echo esc_html($quote['number']); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Notes -->
        <div class="notes-section">
            <p class="valid-until">
                <?php echo esc_html(sprintf(
                    __('Deze offerte is geldig tot %s. Bij vragen kunt u contact met ons opnemen via %s.', 'stride'),
                    $quote['valid_until_date'],
                    $company['email']
                )); ?>
            </p>
        </div>

        <!-- Footer -->
        <div class="footer">
            <span class="footer-content">
                <?php echo esc_html($company['name']); ?>
                <?php if (!empty($company['email'])): ?>
                    &nbsp;|&nbsp;<?php echo esc_html($company['email']); ?>
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
