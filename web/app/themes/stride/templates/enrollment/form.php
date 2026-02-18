<?php
/**
 * Enrollment Form Template
 *
 * Multi-step enrollment form for edition registration.
 *
 * Steps:
 * 1. Participant - Self or colleague enrollment
 * 2. Sessions - Session selection (if required)
 * 3. Invoice - Organization and billing data
 * 4. Confirmation - Review and submit
 *
 * @var int $edition_id
 * @var array $edition
 * @var WP_Post $course
 * @var float|null $price
 * @var float|null $price_non_member
 * @var array $sessions
 * @var array $session_slots
 * @var bool $requires_session_selection
 * @var string|null $selection_deadline
 * @var string|null $start_date
 * @var string|null $end_date
 * @var string|null $venue
 * @var WP_User $user
 * @var array|null $user_profile
 * @var string $nonce
 * @var string $ajax_url
 *
 * @package stride
 */

defined('ABSPATH') || exit;

// Format dates
$startDateFormatted = $start_date ? date_i18n('j F Y', strtotime($start_date)) : '';
$endDateFormatted = $end_date ? date_i18n('j F Y', strtotime($end_date)) : '';
$dateRange = $startDateFormatted;
if ($endDateFormatted && $endDateFormatted !== $startDateFormatted) {
    $dateRange = $startDateFormatted . ' - ' . $endDateFormatted;
}

// Format price
$priceFormatted = $price !== null ? number_format($price, 2, ',', '.') : '0,00';

// Pre-fill user data
$firstName = $user_profile['first_name'] ?? $user->first_name ?? '';
$lastName = $user_profile['last_name'] ?? $user->last_name ?? '';
$email = $user->user_email ?? '';
$phone = $user_profile['phone'] ?? '';
$company = $user_profile['company'] ?? '';

// Step count
$totalSteps = $requires_session_selection ? 4 : 3;
?>

<div class="stride-enrollment-form">
    <div class="uk-container uk-container-small">
        <!-- Header -->
        <header class="stride-enrollment-header uk-margin-medium-bottom">
            <nav class="uk-margin-bottom">
                <ul class="uk-breadcrumb">
                    <li><a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Home', 'stride'); ?></a></li>
                    <li><a href="<?php echo esc_url(home_url('/cursussen/')); ?>"><?php esc_html_e('Cursussen', 'stride'); ?></a></li>
                    <?php if ($course): ?>
                        <li><a href="<?php echo esc_url(get_permalink($course->ID)); ?>"><?php echo esc_html($course->post_title); ?></a></li>
                    <?php endif; ?>
                    <li><span><?php esc_html_e('Inschrijven', 'stride'); ?></span></li>
                </ul>
            </nav>

            <h1 class="uk-h2 uk-margin-remove-bottom">
                <?php esc_html_e('Inschrijven', 'stride'); ?>
            </h1>
            <p class="uk-text-lead uk-text-muted uk-margin-small-top">
                <?php echo esc_html($course ? $course->post_title : $edition['title']); ?>
                <?php if ($dateRange): ?>
                    <span class="uk-text-small"> - <?php echo esc_html($dateRange); ?></span>
                <?php endif; ?>
            </p>
        </header>

        <!-- Step Indicator -->
        <div class="stride-step-indicator uk-margin-medium-bottom">
            <div class="stride-steps">
                <div class="stride-step active" data-step="1">
                    <span class="stride-step-number">1</span>
                    <span class="stride-step-label"><?php esc_html_e('Deelnemer', 'stride'); ?></span>
                </div>
                <?php if ($requires_session_selection): ?>
                    <div class="stride-step" data-step="2">
                        <span class="stride-step-number">2</span>
                        <span class="stride-step-label"><?php esc_html_e('Sessies', 'stride'); ?></span>
                    </div>
                <?php endif; ?>
                <div class="stride-step" data-step="<?php echo $requires_session_selection ? '3' : '2'; ?>">
                    <span class="stride-step-number"><?php echo $requires_session_selection ? '3' : '2'; ?></span>
                    <span class="stride-step-label"><?php esc_html_e('Facturatie', 'stride'); ?></span>
                </div>
                <div class="stride-step" data-step="<?php echo $requires_session_selection ? '4' : '3'; ?>">
                    <span class="stride-step-number"><?php echo $requires_session_selection ? '4' : '3'; ?></span>
                    <span class="stride-step-label"><?php esc_html_e('Bevestiging', 'stride'); ?></span>
                </div>
            </div>
        </div>

        <!-- Form -->
        <form id="stride-enrollment-form" class="stride-card">
            <input type="hidden" name="edition_id" value="<?php echo esc_attr($edition_id); ?>">
            <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
            <input type="hidden" name="requires_session_selection" value="<?php echo $requires_session_selection ? '1' : '0'; ?>">

            <!-- Step 1: Participant -->
            <div class="stride-form-step" data-step="1">
                <h2 class="uk-h4 uk-margin-medium-bottom">
                    <?php esc_html_e('Wie schrijf je in?', 'stride'); ?>
                </h2>

                <!-- Enrollment Type -->
                <div class="uk-margin-medium-bottom">
                    <div class="stride-enrollment-type-options">
                        <label class="stride-enrollment-type-option selected">
                            <input type="radio" name="enrollment_type" value="self" checked>
                            <div class="stride-enrollment-type-content">
                                <span uk-icon="icon: user; ratio: 1.2"></span>
                                <div>
                                    <strong><?php esc_html_e('Ik schrijf mezelf in', 'stride'); ?></strong>
                                    <span class="uk-text-muted uk-text-small uk-display-block">
                                        <?php esc_html_e('Voor je eigen deelname', 'stride'); ?>
                                    </span>
                                </div>
                            </div>
                        </label>
                        <label class="stride-enrollment-type-option">
                            <input type="radio" name="enrollment_type" value="colleague">
                            <div class="stride-enrollment-type-content">
                                <span uk-icon="icon: users; ratio: 1.2"></span>
                                <div>
                                    <strong><?php esc_html_e('Ik schrijf een collega in', 'stride'); ?></strong>
                                    <span class="uk-text-muted uk-text-small uk-display-block">
                                        <?php esc_html_e('Voor iemand anders', 'stride'); ?>
                                    </span>
                                </div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Participant Data -->
                <fieldset class="uk-fieldset">
                    <legend class="uk-legend uk-h5"><?php esc_html_e('Gegevens deelnemer', 'stride'); ?></legend>

                    <div class="uk-grid uk-grid-small uk-child-width-1-2@s" uk-grid>
                        <div>
                            <label class="uk-form-label" for="first_name">
                                <?php esc_html_e('Voornaam', 'stride'); ?> <span class="uk-text-danger">*</span>
                            </label>
                            <input type="text" id="first_name" name="first_name"
                                   class="uk-input" required
                                   value="<?php echo esc_attr($firstName); ?>">
                        </div>
                        <div>
                            <label class="uk-form-label" for="last_name">
                                <?php esc_html_e('Achternaam', 'stride'); ?> <span class="uk-text-danger">*</span>
                            </label>
                            <input type="text" id="last_name" name="last_name"
                                   class="uk-input" required
                                   value="<?php echo esc_attr($lastName); ?>">
                        </div>
                    </div>

                    <div class="uk-margin">
                        <label class="uk-form-label" for="email">
                            <?php esc_html_e('E-mailadres', 'stride'); ?> <span class="uk-text-danger">*</span>
                        </label>
                        <input type="email" id="email" name="email"
                               class="uk-input" required
                               value="<?php echo esc_attr($email); ?>"
                               data-self-email="<?php echo esc_attr($email); ?>">
                        <p class="uk-text-meta uk-margin-small-top" id="email-hint">
                            <?php esc_html_e('Bevestigingsmail wordt naar dit adres verzonden.', 'stride'); ?>
                        </p>
                    </div>

                    <div class="uk-grid uk-grid-small uk-child-width-1-2@s" uk-grid>
                        <div>
                            <label class="uk-form-label" for="phone">
                                <?php esc_html_e('Telefoonnummer', 'stride'); ?>
                            </label>
                            <input type="tel" id="phone" name="phone"
                                   class="uk-input"
                                   value="<?php echo esc_attr($phone); ?>">
                        </div>
                        <div>
                            <label class="uk-form-label" for="department">
                                <?php esc_html_e('Afdeling', 'stride'); ?>
                            </label>
                            <input type="text" id="department" name="department"
                                   class="uk-input"
                                   value="<?php echo esc_attr($user_profile['department'] ?? ''); ?>">
                        </div>
                    </div>
                </fieldset>
            </div>

            <!-- Step 2: Sessions (conditional) -->
            <?php if ($requires_session_selection): ?>
                <div class="stride-form-step" data-step="2" style="display: none;">
                    <h2 class="uk-h4 uk-margin-medium-bottom">
                        <?php esc_html_e('Selecteer je sessies', 'stride'); ?>
                    </h2>

                    <?php if ($selection_deadline): ?>
                        <div class="uk-alert uk-alert-primary uk-margin-bottom">
                            <span uk-icon="icon: clock"></span>
                            <?php printf(
                                esc_html__('Maak je keuze voor %s.', 'stride'),
                                date_i18n('j F Y', strtotime($selection_deadline))
                            ); ?>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($session_slots as $slot): ?>
                        <?php
                        $slotSessions = array_filter($sessions, fn($s) => ($s['slot'] ?? '') === $slot['slot']);
                        $pickCount = $slot['pick_count'] ?? 1;
                        $inputType = $pickCount === 1 ? 'radio' : 'checkbox';
                        ?>
                        <div class="stride-session-slot uk-margin-medium-bottom">
                            <h4 class="uk-h5 uk-margin-small-bottom">
                                <?php echo esc_html($slot['label']); ?>
                                <span class="uk-text-muted uk-text-small">
                                    (<?php printf(
                                        esc_html(_n('kies %d sessie', 'kies %d sessies', $pickCount, 'stride')),
                                        $pickCount
                                    ); ?>)
                                </span>
                            </h4>

                            <div class="stride-session-options">
                                <?php foreach ($slotSessions as $session): ?>
                                    <label class="stride-session-option">
                                        <input type="<?php echo esc_attr($inputType); ?>"
                                               name="selected_sessions[<?php echo esc_attr($slot['slot']); ?>]<?php echo $pickCount > 1 ? '[]' : ''; ?>"
                                               value="<?php echo esc_attr($session['id']); ?>">
                                        <div class="stride-session-option-content">
                                            <div class="stride-session-option-main">
                                                <strong>
                                                    <?php if (!empty($session['date'])): ?>
                                                        <?php echo esc_html(date_i18n('l j F Y', strtotime($session['date']))); ?>
                                                    <?php else: ?>
                                                        <?php echo esc_html($session['title'] ?? __('Sessie', 'stride')); ?>
                                                    <?php endif; ?>
                                                </strong>
                                                <?php if (!empty($session['start_time'])): ?>
                                                    <div class="uk-text-muted uk-text-small">
                                                        <span uk-icon="icon: clock; ratio: 0.7"></span>
                                                        <?php echo esc_html($session['start_time']); ?>
                                                        <?php if (!empty($session['end_time'])): ?>
                                                            - <?php echo esc_html($session['end_time']); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($session['location'])): ?>
                                                    <div class="uk-text-muted uk-text-small">
                                                        <span uk-icon="icon: location; ratio: 0.7"></span>
                                                        <?php echo esc_html($session['location']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="stride-session-option-check">
                                                <span uk-icon="icon: check"></span>
                                            </div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Step 3 (or 2): Invoice Data -->
            <div class="stride-form-step" data-step="<?php echo $requires_session_selection ? '3' : '2'; ?>" style="display: none;">
                <h2 class="uk-h4 uk-margin-medium-bottom">
                    <?php esc_html_e('Facturatiegegevens', 'stride'); ?>
                </h2>

                <fieldset class="uk-fieldset uk-margin-medium-bottom">
                    <legend class="uk-legend uk-h5"><?php esc_html_e('Organisatie', 'stride'); ?></legend>

                    <div class="uk-margin">
                        <label class="uk-form-label" for="company">
                            <?php esc_html_e('Bedrijf / Organisatie', 'stride'); ?>
                        </label>
                        <input type="text" id="company" name="company"
                               class="uk-input"
                               value="<?php echo esc_attr($company); ?>">
                    </div>

                    <div class="uk-grid uk-grid-small" uk-grid>
                        <div class="uk-width-2-3@s">
                            <label class="uk-form-label" for="vat_number">
                                <?php esc_html_e('BTW-nummer', 'stride'); ?>
                            </label>
                            <div class="uk-inline uk-width-1-1">
                                <input type="text" id="vat_number" name="vat_number"
                                       class="uk-input"
                                       placeholder="BE0123456789">
                            </div>
                        </div>
                        <div class="uk-width-1-3@s uk-flex uk-flex-bottom">
                            <button type="button" id="lookup-vat" class="uk-button uk-button-default uk-width-1-1">
                                <span uk-icon="icon: search; ratio: 0.9"></span>
                                <?php esc_html_e('Opzoeken', 'stride'); ?>
                            </button>
                        </div>
                    </div>

                    <div class="uk-margin">
                        <label class="uk-form-label" for="address">
                            <?php esc_html_e('Adres', 'stride'); ?>
                        </label>
                        <input type="text" id="address" name="address"
                               class="uk-input"
                               value="<?php echo esc_attr($user_profile['address'] ?? ''); ?>">
                    </div>

                    <div class="uk-grid uk-grid-small uk-child-width-1-2@s" uk-grid>
                        <div>
                            <label class="uk-form-label" for="postal_code">
                                <?php esc_html_e('Postcode', 'stride'); ?>
                            </label>
                            <input type="text" id="postal_code" name="postal_code"
                                   class="uk-input"
                                   value="<?php echo esc_attr($user_profile['postal_code'] ?? ''); ?>">
                        </div>
                        <div>
                            <label class="uk-form-label" for="city">
                                <?php esc_html_e('Plaats', 'stride'); ?>
                            </label>
                            <input type="text" id="city" name="city"
                                   class="uk-input"
                                   value="<?php echo esc_attr($user_profile['city'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="uk-margin">
                        <label class="uk-form-label" for="gln_peppol">
                            <?php esc_html_e('GLN / Peppol ID', 'stride'); ?>
                            <span class="uk-text-muted uk-text-small">(<?php esc_html_e('optioneel, voor e-facturatie', 'stride'); ?>)</span>
                        </label>
                        <input type="text" id="gln_peppol" name="gln_peppol"
                               class="uk-input"
                               value="<?php echo esc_attr($user_profile['gln_peppol'] ?? ''); ?>">
                    </div>

                    <div class="uk-margin">
                        <label class="uk-form-label" for="invoice_email">
                            <?php esc_html_e('Factuur e-mailadres', 'stride'); ?>
                        </label>
                        <input type="email" id="invoice_email" name="invoice_email"
                               class="uk-input"
                               value="<?php echo esc_attr($user_profile['invoice_email'] ?? ''); ?>"
                               placeholder="<?php esc_attr_e('Indien anders dan deelnemer e-mail', 'stride'); ?>">
                    </div>
                </fieldset>

                <fieldset class="uk-fieldset uk-margin-medium-bottom">
                    <legend class="uk-legend uk-h5"><?php esc_html_e('Bestelreferentie', 'stride'); ?></legend>

                    <div class="uk-margin">
                        <label class="uk-form-label" for="po_number">
                            <?php esc_html_e('PO / Bestelnummer', 'stride'); ?>
                            <span class="uk-text-muted uk-text-small">(<?php esc_html_e('optioneel', 'stride'); ?>)</span>
                        </label>
                        <input type="text" id="po_number" name="po_number"
                               class="uk-input">
                    </div>
                </fieldset>

                <fieldset class="uk-fieldset">
                    <legend class="uk-legend uk-h5"><?php esc_html_e('Voucher', 'stride'); ?></legend>

                    <div class="uk-grid uk-grid-small" uk-grid>
                        <div class="uk-width-2-3@s">
                            <label class="uk-form-label" for="voucher_code">
                                <?php esc_html_e('Vouchercode', 'stride'); ?>
                            </label>
                            <input type="text" id="voucher_code" name="voucher_code"
                                   class="uk-input"
                                   placeholder="<?php esc_attr_e('Optioneel', 'stride'); ?>">
                        </div>
                        <div class="uk-width-1-3@s uk-flex uk-flex-bottom">
                            <button type="button" id="apply-voucher" class="uk-button uk-button-default uk-width-1-1">
                                <?php esc_html_e('Toepassen', 'stride'); ?>
                            </button>
                        </div>
                    </div>
                    <div id="voucher-result" class="uk-margin-small-top" style="display: none;"></div>
                </fieldset>
            </div>

            <!-- Step 4 (or 3): Confirmation -->
            <div class="stride-form-step" data-step="<?php echo $requires_session_selection ? '4' : '3'; ?>" style="display: none;">
                <h2 class="uk-h4 uk-margin-medium-bottom">
                    <?php esc_html_e('Bevestig je inschrijving', 'stride'); ?>
                </h2>

                <!-- Summary -->
                <div class="stride-confirmation-summary uk-margin-medium-bottom">
                    <h4 class="uk-h5"><?php esc_html_e('Overzicht', 'stride'); ?></h4>

                    <dl class="uk-description-list uk-description-list-divider">
                        <dt><?php esc_html_e('Deelnemer', 'stride'); ?></dt>
                        <dd id="summary-participant">-</dd>

                        <dt><?php esc_html_e('Cursus', 'stride'); ?></dt>
                        <dd><?php echo esc_html($course ? $course->post_title : $edition['title']); ?></dd>

                        <dt><?php esc_html_e('Editie', 'stride'); ?></dt>
                        <dd><?php echo esc_html($dateRange ?: $edition['title']); ?></dd>

                        <?php if ($requires_session_selection): ?>
                            <dt><?php esc_html_e('Sessies', 'stride'); ?></dt>
                            <dd id="summary-sessions">-</dd>
                        <?php endif; ?>
                    </dl>
                </div>

                <!-- Price Summary -->
                <div class="stride-price-summary uk-margin-medium-bottom">
                    <h4 class="uk-h5"><?php esc_html_e('Prijsoverzicht', 'stride'); ?></h4>

                    <table class="uk-table uk-table-small uk-table-justify">
                        <tbody>
                            <tr>
                                <td><?php esc_html_e('Basisprijs', 'stride'); ?></td>
                                <td class="uk-text-right"><?php echo esc_html($priceFormatted); ?></td>
                            </tr>
                            <tr id="voucher-discount-row" style="display: none;">
                                <td><?php esc_html_e('Voucher korting', 'stride'); ?></td>
                                <td class="uk-text-right uk-text-success" id="voucher-discount-amount">- 0,00</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="uk-text-bold">
                                <td><?php esc_html_e('Totaal', 'stride'); ?></td>
                                <td class="uk-text-right" id="total-price"><?php echo esc_html($priceFormatted); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                    <p class="uk-text-muted uk-text-small"><?php esc_html_e('Prijzen excl. BTW', 'stride'); ?></p>
                </div>

                <!-- Cancellation Policy -->
                <div class="stride-cancellation-policy uk-margin-medium-bottom">
                    <h4 class="uk-h5"><?php esc_html_e('Annuleringsvoorwaarden', 'stride'); ?></h4>
                    <ul class="uk-list uk-list-bullet uk-text-small">
                        <li><?php esc_html_e('Gratis annuleren tot 14 dagen voor aanvang', 'stride'); ?></li>
                        <li><?php esc_html_e('Binnen 14 dagen: u kunt een collega in uw plaats laten deelnemen', 'stride'); ?></li>
                        <li><?php esc_html_e('Bij annulering binnen 14 dagen zonder vervanging: 100% van de kosten', 'stride'); ?></li>
                    </ul>
                </div>

                <!-- Terms -->
                <div class="uk-margin">
                    <label>
                        <input type="checkbox" class="uk-checkbox" name="terms_accepted" id="terms_accepted" required>
                        <?php printf(
                            esc_html__('Ik ga akkoord met de %s', 'stride'),
                            '<a href="' . esc_url(home_url('/voorwaarden/')) . '" target="_blank">' . esc_html__('algemene voorwaarden', 'stride') . '</a>'
                        ); ?>
                        <span class="uk-text-danger">*</span>
                    </label>
                </div>
            </div>

            <!-- Form Navigation -->
            <div class="stride-form-navigation uk-margin-large-top">
                <div class="uk-flex uk-flex-between">
                    <button type="button" id="btn-prev" class="uk-button uk-button-default" style="display: none;">
                        <span uk-icon="icon: arrow-left; ratio: 0.9"></span>
                        <?php esc_html_e('Vorige', 'stride'); ?>
                    </button>
                    <div class="uk-margin-auto-left">
                        <button type="button" id="btn-next" class="uk-button uk-button-primary">
                            <?php esc_html_e('Volgende', 'stride'); ?>
                            <span uk-icon="icon: arrow-right; ratio: 0.9"></span>
                        </button>
                        <button type="submit" id="btn-submit" class="uk-button uk-button-primary" style="display: none;">
                            <span uk-icon="icon: check; ratio: 0.9"></span>
                            <?php esc_html_e('Inschrijving bevestigen', 'stride'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
.stride-enrollment-form {
    padding: 40px 0;
}

.stride-step-indicator {
    margin-bottom: 32px;
}

.stride-steps {
    display: flex;
    justify-content: center;
    gap: 8px;
}

.stride-step {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: var(--stride-card-bg, #f8f8f8);
    border-radius: 20px;
    color: var(--stride-text-muted, #999);
    transition: all 0.2s ease;
}

.stride-step.active {
    background: var(--stride-primary, #1e87f0);
    color: white;
}

.stride-step.completed {
    background: var(--stride-success, #32d296);
    color: white;
}

.stride-step-number {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.85rem;
}

.stride-step.active .stride-step-number,
.stride-step.completed .stride-step-number {
    background: rgba(255,255,255,0.3);
}

.stride-step-label {
    font-size: 0.9rem;
    font-weight: 500;
}

@media (max-width: 640px) {
    .stride-step-label {
        display: none;
    }
    .stride-step {
        padding: 8px 12px;
    }
}

.stride-enrollment-type-options {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.stride-enrollment-type-option {
    display: block;
    padding: 16px;
    border: 2px solid var(--stride-border, #e5e5e5);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.stride-enrollment-type-option:hover {
    border-color: var(--stride-primary, #1e87f0);
}

.stride-enrollment-type-option.selected {
    border-color: var(--stride-primary, #1e87f0);
    background: rgba(30, 135, 240, 0.05);
}

.stride-enrollment-type-option input {
    display: none;
}

.stride-enrollment-type-content {
    display: flex;
    align-items: center;
    gap: 16px;
}

.stride-enrollment-type-content [uk-icon] {
    color: var(--stride-primary, #1e87f0);
}

.stride-session-options {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.stride-session-option {
    display: block;
    padding: 16px;
    border: 2px solid var(--stride-border, #e5e5e5);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.stride-session-option:hover {
    border-color: var(--stride-primary, #1e87f0);
    background: rgba(30, 135, 240, 0.05);
}

.stride-session-option.selected {
    border-color: var(--stride-primary, #1e87f0);
    background: rgba(30, 135, 240, 0.1);
}

.stride-session-option input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.stride-session-option-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.stride-session-option-main {
    flex: 1;
}

.stride-session-option-check {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--stride-border, #e5e5e5);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    transition: all 0.2s ease;
}

.stride-session-option.selected .stride-session-option-check {
    background: var(--stride-primary, #1e87f0);
}

.stride-session-option:not(.selected) .stride-session-option-check span {
    opacity: 0;
}

.stride-confirmation-summary,
.stride-price-summary,
.stride-cancellation-policy {
    background: var(--stride-card-bg, #f8f8f8);
    padding: 20px;
    border-radius: 8px;
}

.stride-price-summary table {
    margin-bottom: 0;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('stride-enrollment-form');
    if (!form) return;

    const requiresSessionSelection = form.querySelector('[name="requires_session_selection"]').value === '1';
    const totalSteps = requiresSessionSelection ? 4 : 3;
    let currentStep = 1;
    let voucherDiscount = 0;
    const basePrice = <?php echo json_encode($price ?? 0); ?>;

    const steps = form.querySelectorAll('.stride-form-step');
    const stepIndicators = document.querySelectorAll('.stride-step');
    const btnPrev = document.getElementById('btn-prev');
    const btnNext = document.getElementById('btn-next');
    const btnSubmit = document.getElementById('btn-submit');

    // Enrollment type toggle
    const enrollmentTypes = form.querySelectorAll('[name="enrollment_type"]');
    enrollmentTypes.forEach(radio => {
        radio.addEventListener('change', function() {
            form.querySelectorAll('.stride-enrollment-type-option').forEach(opt => {
                opt.classList.toggle('selected', opt.querySelector('input').checked);
            });

            const emailField = form.querySelector('#email');
            const selfEmail = emailField.dataset.selfEmail;

            if (this.value === 'self') {
                emailField.value = selfEmail;
                emailField.readOnly = true;
            } else {
                emailField.value = '';
                emailField.readOnly = false;
            }
        });
    });

    // Session selection visual updates
    form.querySelectorAll('.stride-session-option input').forEach(input => {
        input.addEventListener('change', function() {
            const slot = this.closest('.stride-session-slot');
            if (!slot) return;

            if (this.type === 'radio') {
                slot.querySelectorAll('.stride-session-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
            }

            const option = this.closest('.stride-session-option');
            if (option) {
                option.classList.toggle('selected', this.checked);
            }
        });
    });

    // Step navigation
    function showStep(step) {
        steps.forEach(s => {
            s.style.display = parseInt(s.dataset.step) === step ? 'block' : 'none';
        });

        stepIndicators.forEach(si => {
            const siStep = parseInt(si.dataset.step);
            si.classList.remove('active', 'completed');
            if (siStep === step) {
                si.classList.add('active');
            } else if (siStep < step) {
                si.classList.add('completed');
            }
        });

        btnPrev.style.display = step > 1 ? 'inline-block' : 'none';
        btnNext.style.display = step < totalSteps ? 'inline-block' : 'none';
        btnSubmit.style.display = step === totalSteps ? 'inline-block' : 'none';

        // Update summary on last step
        if (step === totalSteps) {
            updateSummary();
        }

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function validateStep(step) {
        const stepEl = form.querySelector(`.stride-form-step[data-step="${step}"]`);
        const requiredFields = stepEl.querySelectorAll('[required]');
        let valid = true;

        requiredFields.forEach(field => {
            if (!field.value.trim()) {
                field.classList.add('uk-form-danger');
                valid = false;
            } else {
                field.classList.remove('uk-form-danger');
            }
        });

        if (!valid) {
            UIkit.notification({
                message: '<?php echo esc_js(__('Vul alle verplichte velden in.', 'stride')); ?>',
                status: 'warning',
                pos: 'top-center'
            });
        }

        return valid;
    }

    function updateSummary() {
        const firstName = form.querySelector('#first_name').value;
        const lastName = form.querySelector('#last_name').value;
        const email = form.querySelector('#email').value;

        document.getElementById('summary-participant').textContent =
            `${firstName} ${lastName} (${email})`;

        if (requiresSessionSelection) {
            const selectedSessions = [];
            form.querySelectorAll('.stride-session-option.selected .stride-session-option-main strong').forEach(el => {
                selectedSessions.push(el.textContent.trim());
            });
            document.getElementById('summary-sessions').textContent =
                selectedSessions.length > 0 ? selectedSessions.join(', ') : '-';
        }

        // Update total price
        const total = Math.max(0, basePrice - voucherDiscount);
        document.getElementById('total-price').textContent =
            total.toFixed(2).replace('.', ',');
    }

    btnNext.addEventListener('click', function() {
        if (validateStep(currentStep)) {
            currentStep++;
            showStep(currentStep);
        }
    });

    btnPrev.addEventListener('click', function() {
        currentStep--;
        showStep(currentStep);
    });

    // Voucher validation
    const voucherInput = document.getElementById('voucher_code');
    const applyVoucherBtn = document.getElementById('apply-voucher');
    const voucherResult = document.getElementById('voucher-result');

    applyVoucherBtn.addEventListener('click', function() {
        const code = voucherInput.value.trim();
        if (!code) {
            UIkit.notification({
                message: '<?php echo esc_js(__('Voer een vouchercode in.', 'stride')); ?>',
                status: 'warning',
                pos: 'top-center'
            });
            return;
        }

        applyVoucherBtn.disabled = true;
        applyVoucherBtn.innerHTML = '<span uk-spinner="ratio: 0.6"></span>';

        fetch('<?php echo esc_js($ajax_url); ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'stride_validate_voucher',
                nonce: '<?php echo esc_js($nonce); ?>',
                code: code,
                edition_id: '<?php echo esc_js($edition_id); ?>'
            })
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                voucherDiscount = result.data.discount;
                voucherResult.innerHTML = `
                    <div class="uk-alert uk-alert-success uk-margin-remove">
                        <span uk-icon="icon: check"></span>
                        ${result.data.message}
                    </div>
                `;
                voucherResult.style.display = 'block';

                // Update price display
                document.getElementById('voucher-discount-row').style.display = 'table-row';
                document.getElementById('voucher-discount-amount').textContent =
                    '- ' + result.data.discount_formatted;
            } else {
                voucherResult.innerHTML = `
                    <div class="uk-alert uk-alert-danger uk-margin-remove">
                        <span uk-icon="icon: warning"></span>
                        ${result.data?.message || '<?php echo esc_js(__('Vouchercode ongeldig.', 'stride')); ?>'}
                    </div>
                `;
                voucherResult.style.display = 'block';
                voucherDiscount = 0;
                document.getElementById('voucher-discount-row').style.display = 'none';
            }
        })
        .catch(error => {
            console.error('Voucher validation error:', error);
            UIkit.notification({
                message: '<?php echo esc_js(__('Er ging iets mis.', 'stride')); ?>',
                status: 'danger',
                pos: 'top-center'
            });
        })
        .finally(() => {
            applyVoucherBtn.disabled = false;
            applyVoucherBtn.innerHTML = '<?php echo esc_js(__('Toepassen', 'stride')); ?>';
        });
    });

    // Form submission
    form.addEventListener('submit', function(e) {
        e.preventDefault();

        if (!form.querySelector('#terms_accepted').checked) {
            UIkit.notification({
                message: '<?php echo esc_js(__('Je moet akkoord gaan met de voorwaarden.', 'stride')); ?>',
                status: 'warning',
                pos: 'top-center'
            });
            return;
        }

        const formData = new FormData(form);
        const data = {
            action: 'stride_submit_enrollment'
        };

        // Add form fields
        for (const [key, value] of formData.entries()) {
            if (key.startsWith('selected_sessions')) {
                if (!data.selected_sessions) data.selected_sessions = [];
                data.selected_sessions.push(value);
            } else {
                data[key] = value;
            }
        }

        btnSubmit.disabled = true;
        btnSubmit.innerHTML = '<span uk-spinner="ratio: 0.6"></span> <?php echo esc_js(__('Verwerken...', 'stride')); ?>';

        fetch('<?php echo esc_js($ajax_url); ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data)
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                UIkit.notification({
                    message: result.data.message,
                    status: 'success',
                    pos: 'top-center',
                    timeout: 3000
                });

                // Redirect after success
                setTimeout(() => {
                    window.location.href = result.data.redirect_url || '<?php echo esc_js(home_url('/mijn-account/cursussen/')); ?>';
                }, 1500);
            } else {
                UIkit.notification({
                    message: result.data?.message || '<?php echo esc_js(__('Er ging iets mis.', 'stride')); ?>',
                    status: 'danger',
                    pos: 'top-center'
                });
                btnSubmit.disabled = false;
                btnSubmit.innerHTML = '<span uk-icon="icon: check; ratio: 0.9"></span> <?php echo esc_js(__('Inschrijving bevestigen', 'stride')); ?>';
            }
        })
        .catch(error => {
            console.error('Enrollment error:', error);
            UIkit.notification({
                message: '<?php echo esc_js(__('Er ging iets mis.', 'stride')); ?>',
                status: 'danger',
                pos: 'top-center'
            });
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = '<span uk-icon="icon: check; ratio: 0.9"></span> <?php echo esc_js(__('Inschrijving bevestigen', 'stride')); ?>';
        });
    });

    // Initialize
    showStep(1);
});
</script>
