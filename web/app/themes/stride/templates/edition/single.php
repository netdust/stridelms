<?php
/**
 * Single Edition Page Template
 *
 * Displays a full product-style page for a scheduled course edition.
 *
 * @var int $edition_id
 * @var array $edition
 * @var WP_Post $course
 * @var string $course_content
 * @var array $sessions
 * @var array $session_slots
 * @var array $speakers
 * @var int|null $available_spots
 * @var int|null $capacity
 * @var string $status - open, full, cancelled, postponed, announcement
 * @var float $total_hours
 * @var int $day_count
 * @var float|null $price
 * @var float|null $price_non_member
 * @var string|null $venue
 * @var string|null $start_date
 * @var string|null $end_date
 * @var string|null $selection_deadline
 * @var bool $requires_session_selection
 * @var bool $is_certificate_enabled
 * @var bool $is_invoice_enabled
 * @var bool $is_multi_year
 * @var array|null $action_button
 * @var int $user_id
 * @var DashboardService $dashboard_service
 *
 * @package stride
 */

defined('ABSPATH') || exit;

// Status badge config
$statusBadges = [
    'open' => ['label' => __('Inschrijving open', 'stride'), 'class' => 'stride-badge-success'],
    'full' => ['label' => __('Volzet', 'stride'), 'class' => 'stride-badge-warning'],
    'cancelled' => ['label' => __('Geannuleerd', 'stride'), 'class' => 'stride-badge-danger'],
    'postponed' => ['label' => __('Uitgesteld', 'stride'), 'class' => 'stride-badge-warning'],
    'announcement' => ['label' => __('Binnenkort', 'stride'), 'class' => 'stride-badge-muted'],
    'completed' => ['label' => __('Afgelopen', 'stride'), 'class' => 'stride-badge-muted'],
];
$statusBadge = $statusBadges[$status] ?? $statusBadges['open'];

// Format dates
$startDateFormatted = $start_date ? date_i18n('j F Y', strtotime($start_date)) : null;
$endDateFormatted = $end_date ? date_i18n('j F Y', strtotime($end_date)) : null;
$dateRange = $startDateFormatted;
if ($endDateFormatted && $endDateFormatted !== $startDateFormatted) {
    $dateRange = $startDateFormatted . ' - ' . $endDateFormatted;
}

// Format price
$priceFormatted = $price !== null ? '€ ' . number_format($price, 2, ',', '.') : __('Gratis', 'stride');
$priceNonMemberFormatted = $price_non_member !== null ? '€ ' . number_format($price_non_member, 2, ',', '.') : null;

// Enrollment URL
$enrollmentUrl = home_url('/inschrijven/?edition_id=' . $edition_id);

// Action button
$buttonLabel = $action_button['label'] ?? __('Inschrijven', 'stride');
$buttonUrl = $action_button['url'] ?? $enrollmentUrl;
$buttonStyle = $action_button['style'] ?? 'primary';
$buttonDisabled = $action_button['disabled'] ?? false;
$buttonStyleClass = match ($buttonStyle) {
    'primary' => 'uk-button-primary',
    'success' => 'uk-button-success',
    'warning' => 'uk-button-warning',
    'danger' => 'uk-button-danger',
    'muted' => 'uk-button-default uk-disabled',
    default => 'uk-button-default',
};
?>

<div class="stride-edition-page">
    <div class="uk-container">
        <!-- Breadcrumb & Header -->
        <header class="stride-edition-header uk-margin-medium-bottom">
            <nav class="uk-margin-bottom">
                <ul class="uk-breadcrumb">
                    <li><a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Home', 'stride'); ?></a></li>
                    <li><a href="<?php echo esc_url(home_url('/cursussen/')); ?>"><?php esc_html_e('Cursussen', 'stride'); ?></a></li>
                    <li><span><?php echo esc_html($dateRange ?: $edition['title']); ?></span></li>
                </ul>
            </nav>

            <div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap" uk-grid>
                <div class="uk-width-expand@m">
                    <h1 class="uk-h2 uk-margin-remove-bottom">
                        <?php echo esc_html($course ? $course->post_title : $edition['title']); ?>
                    </h1>
                    <?php if ($dateRange): ?>
                        <p class="uk-text-lead uk-text-muted uk-margin-small-top">
                            <?php echo esc_html($dateRange); ?>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="uk-width-auto@m">
                    <span class="stride-badge <?php echo esc_attr($statusBadge['class']); ?>">
                        <?php echo esc_html($statusBadge['label']); ?>
                    </span>
                </div>
            </div>
        </header>

        <!-- Hero Bar with Quick Info -->
        <div class="stride-edition-hero-bar uk-margin-medium-bottom">
            <div class="uk-grid uk-grid-small uk-child-width-auto uk-flex-middle" uk-grid>
                <?php if (!empty($edition['type'])): ?>
                    <div>
                        <span class="stride-badge stride-badge-primary">
                            <?php echo esc_html(ucfirst($edition['type'])); ?>
                        </span>
                    </div>
                <?php endif; ?>

                <?php if ($venue): ?>
                    <div>
                        <span uk-icon="icon: location; ratio: 0.9"></span>
                        <span><?php echo esc_html($venue); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($day_count > 0): ?>
                    <div>
                        <span uk-icon="icon: calendar; ratio: 0.9"></span>
                        <span>
                            <?php printf(
                                esc_html(_n('%d dag', '%d dagen', $day_count, 'stride')),
                                $day_count
                            ); ?>
                        </span>
                    </div>
                <?php endif; ?>

                <?php if ($total_hours > 0): ?>
                    <div>
                        <span uk-icon="icon: clock; ratio: 0.9"></span>
                        <span><?php echo esc_html(number_format($total_hours, 1, ',', '.')); ?> <?php esc_html_e('uur', 'stride'); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($capacity !== null && $available_spots !== null && $status === 'open'): ?>
                    <div>
                        <span uk-icon="icon: users; ratio: 0.9"></span>
                        <span class="<?php echo $available_spots <= 3 ? 'uk-text-warning' : ''; ?>">
                            <?php printf(
                                esc_html__('%d van %d plaatsen beschikbaar', 'stride'),
                                $available_spots,
                                $capacity
                            ); ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="uk-grid uk-grid-large" uk-grid>
            <!-- Main Content (2/3) -->
            <div class="uk-width-expand@m">
                <!-- Course Description -->
                <?php if ($course_content): ?>
                    <section class="stride-card uk-margin-bottom">
                        <div class="stride-card-header">
                            <h2 class="stride-card-title">
                                <span uk-icon="icon: info"></span>
                                <?php esc_html_e('Over deze cursus', 'stride'); ?>
                            </h2>
                        </div>
                        <div class="stride-card-body stride-prose">
                            <?php echo wp_kses_post($course_content); ?>
                        </div>
                    </section>
                <?php endif; ?>

                <!-- Session Schedule -->
                <?php if (!empty($sessions)): ?>
                    <section class="stride-card uk-margin-bottom">
                        <div class="stride-card-header">
                            <h2 class="stride-card-title">
                                <span uk-icon="icon: calendar"></span>
                                <?php esc_html_e('Lesrooster', 'stride'); ?>
                            </h2>
                            <?php if ($requires_session_selection && $selection_deadline): ?>
                                <p class="uk-text-muted uk-margin-remove uk-text-small">
                                    <?php printf(
                                        esc_html__('Sessieselectie mogelijk tot %s', 'stride'),
                                        date_i18n('j F Y', strtotime($selection_deadline))
                                    ); ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($session_slots) && count($session_slots) > 1): ?>
                            <!-- Grouped by slots -->
                            <?php foreach ($session_slots as $slot):
                                $slotSessions = array_filter($sessions, fn($s) => ($s['slot'] ?? '') === $slot['slot']);
                                $slotSessionCount = count($slotSessions);
                                $pickCount = $slot['pick_count'] ?? 0;
                                // Only show "kies X" if there's actually a choice
                                $showPickBadge = $pickCount > 0 && $slotSessionCount > 1 && $slotSessionCount > $pickCount;
                            ?>
                                <div class="uk-margin-bottom">
                                    <h4 class="uk-h5 uk-text-muted uk-margin-small-bottom">
                                        <?php echo esc_html($slot['label']); ?>
                                        <?php if ($showPickBadge): ?>
                                            <span class="uk-text-small">
                                                (<?php printf(
                                                    esc_html(_n('kies %d', 'kies %d', $pickCount, 'stride')),
                                                    $pickCount
                                                ); ?>)
                                            </span>
                                        <?php endif; ?>
                                    </h4>
                                    <table class="uk-table uk-table-small uk-table-striped uk-table-hover">
                                        <thead>
                                            <tr>
                                                <th><?php esc_html_e('Datum', 'stride'); ?></th>
                                                <th><?php esc_html_e('Tijd', 'stride'); ?></th>
                                                <th><?php esc_html_e('Locatie', 'stride'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            foreach ($slotSessions as $session):
                                            ?>
                                                <tr>
                                                    <td>
                                                        <?php if (!empty($session['date'])): ?>
                                                            <?php echo esc_html(date_i18n('l j F Y', strtotime($session['date']))); ?>
                                                        <?php else: ?>
                                                            -
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($session['start_time'])): ?>
                                                            <?php echo esc_html($session['start_time']); ?>
                                                            <?php if (!empty($session['end_time'])): ?>
                                                                - <?php echo esc_html($session['end_time']); ?>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            -
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo esc_html($session['location'] ?: $venue ?: '-'); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <!-- Simple table -->
                            <table class="uk-table uk-table-small uk-table-striped uk-table-hover">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Datum', 'stride'); ?></th>
                                        <th><?php esc_html_e('Tijd', 'stride'); ?></th>
                                        <th><?php esc_html_e('Locatie', 'stride'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sessions as $session): ?>
                                        <tr>
                                            <td>
                                                <?php if (!empty($session['date'])): ?>
                                                    <?php echo esc_html(date_i18n('l j F Y', strtotime($session['date']))); ?>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($session['start_time'])): ?>
                                                    <?php echo esc_html($session['start_time']); ?>
                                                    <?php if (!empty($session['end_time'])): ?>
                                                        - <?php echo esc_html($session['end_time']); ?>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo esc_html($session['location'] ?: $venue ?: '-'); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>

                        <!-- Total hours summary -->
                        <?php if ($total_hours > 0): ?>
                            <p class="uk-text-muted uk-margin-small-top">
                                <span uk-icon="icon: clock; ratio: 0.8"></span>
                                <?php printf(
                                    esc_html__('Totaal: %s contacturen', 'stride'),
                                    number_format($total_hours, 1, ',', '.')
                                ); ?>
                            </p>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <!-- Target Group, Prerequisites, Accreditation -->
                <?php if (!empty($edition['target_group']) || !empty($edition['prerequisites']) || !empty($edition['accreditation'])): ?>
                    <section class="stride-card uk-margin-bottom">
                        <div class="stride-card-header">
                            <h2 class="stride-card-title">
                                <span uk-icon="icon: user"></span>
                                <?php esc_html_e('Praktische informatie', 'stride'); ?>
                            </h2>
                        </div>

                        <?php if (!empty($edition['target_group'])): ?>
                            <div class="uk-margin-bottom">
                                <h4 class="uk-h5 uk-margin-small-bottom"><?php esc_html_e('Doelgroep', 'stride'); ?></h4>
                                <div class="stride-prose">
                                    <?php echo wp_kses_post($edition['target_group']); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($edition['prerequisites'])): ?>
                            <div class="uk-margin-bottom">
                                <h4 class="uk-h5 uk-margin-small-bottom"><?php esc_html_e('Vereisten', 'stride'); ?></h4>
                                <div class="stride-prose">
                                    <?php echo wp_kses_post($edition['prerequisites']); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($edition['accreditation'])): ?>
                            <div class="uk-margin-bottom">
                                <h4 class="uk-h5 uk-margin-small-bottom"><?php esc_html_e('Accreditatie', 'stride'); ?></h4>
                                <div class="stride-prose">
                                    <?php echo wp_kses_post($edition['accreditation']); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <!-- Speakers & Trainers -->
                <?php if (!empty($speakers)): ?>
                    <section class="stride-card uk-margin-bottom">
                        <div class="stride-card-header">
                            <h2 class="stride-card-title">
                                <span uk-icon="icon: microphone"></span>
                                <?php esc_html_e('Sprekers & Trainers', 'stride'); ?>
                            </h2>
                        </div>

                        <div class="uk-grid uk-grid-small uk-child-width-1-2@s" uk-grid>
                            <?php foreach ($speakers as $speaker): ?>
                                <div>
                                    <div class="stride-speaker-card">
                                        <div class="stride-speaker-avatar">
                                            <span uk-icon="icon: user; ratio: 1.5"></span>
                                        </div>
                                        <div class="stride-speaker-info">
                                            <strong><?php echo esc_html($speaker['name']); ?></strong>
                                            <?php if (!empty($speaker['role'])): ?>
                                                <span class="uk-text-muted uk-text-small uk-display-block">
                                                    <?php echo esc_html($speaker['role']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
            </div>

            <!-- Sidebar (1/3) -->
            <aside class="uk-width-1-3@m">
                <!-- Enrollment Card -->
                <div class="stride-enrollment-card stride-card uk-margin-bottom">
                    <div class="stride-enrollment-card-header">
                        <div class="stride-enrollment-price">
                            <?php echo esc_html($priceFormatted); ?>
                        </div>
                        <?php if ($price !== null): ?>
                            <div class="stride-enrollment-price-label">
                                <?php esc_html_e('excl. BTW (leden)', 'stride'); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($priceNonMemberFormatted && $price_non_member !== $price): ?>
                            <div class="stride-enrollment-price-alt uk-margin-small-top">
                                <span class="uk-text-muted"><?php esc_html_e('Niet-leden:', 'stride'); ?></span>
                                <strong><?php echo esc_html($priceNonMemberFormatted); ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="stride-enrollment-card-body">
                        <!-- Capacity Bar -->
                        <?php if ($capacity !== null && $available_spots !== null && $status === 'open'): ?>
                            <?php
                            $filledPercentage = $capacity > 0 ? (($capacity - $available_spots) / $capacity) * 100 : 0;
                            $barClass = $available_spots <= 3 ? 'uk-progress-warning' : '';
                            ?>
                            <div class="uk-margin-small-bottom">
                                <div class="uk-flex uk-flex-between uk-text-small uk-margin-xsmall-bottom">
                                    <span><?php esc_html_e('Beschikbaarheid', 'stride'); ?></span>
                                    <span class="<?php echo $available_spots <= 3 ? 'uk-text-warning' : ''; ?>">
                                        <?php echo esc_html($available_spots); ?> / <?php echo esc_html($capacity); ?>
                                    </span>
                                </div>
                                <progress class="uk-progress <?php echo esc_attr($barClass); ?>" value="<?php echo esc_attr($filledPercentage); ?>" max="100"></progress>
                            </div>
                        <?php endif; ?>

                        <!-- Action Button -->
                        <?php if ($buttonUrl && !$buttonDisabled): ?>
                            <a href="<?php echo esc_url($buttonUrl); ?>"
                               class="uk-button <?php echo esc_attr($buttonStyleClass); ?> uk-width-1-1 uk-margin-small-bottom">
                                <?php echo esc_html($buttonLabel); ?>
                            </a>
                        <?php else: ?>
                            <button class="uk-button <?php echo esc_attr($buttonStyleClass); ?> uk-width-1-1 uk-margin-small-bottom" disabled>
                                <?php echo esc_html($buttonLabel); ?>
                            </button>
                        <?php endif; ?>

                        <!-- Selection deadline notice -->
                        <?php if ($requires_session_selection && $selection_deadline): ?>
                            <p class="uk-text-small uk-text-muted uk-margin-small-bottom">
                                <span uk-icon="icon: clock; ratio: 0.8"></span>
                                <?php printf(
                                    esc_html__('Sessiekeuze tot %s', 'stride'),
                                    date_i18n('j F Y', strtotime($selection_deadline))
                                ); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Edition Info Card -->
                <div class="stride-card uk-margin-bottom">
                    <div class="stride-card-header">
                        <h3 class="stride-card-title uk-h5">
                            <?php esc_html_e('Editie details', 'stride'); ?>
                        </h3>
                    </div>
                    <ul class="uk-list uk-list-divider">
                        <?php if ($startDateFormatted): ?>
                            <li>
                                <span class="uk-text-muted"><?php esc_html_e('Start:', 'stride'); ?></span>
                                <span><?php echo esc_html($startDateFormatted); ?></span>
                            </li>
                        <?php endif; ?>
                        <?php if ($endDateFormatted && $endDateFormatted !== $startDateFormatted): ?>
                            <li>
                                <span class="uk-text-muted"><?php esc_html_e('Einde:', 'stride'); ?></span>
                                <span><?php echo esc_html($endDateFormatted); ?></span>
                            </li>
                        <?php endif; ?>
                        <?php if (count($sessions) > 0): ?>
                            <li>
                                <span class="uk-text-muted"><?php esc_html_e('Sessies:', 'stride'); ?></span>
                                <span><?php echo esc_html(count($sessions)); ?></span>
                            </li>
                        <?php endif; ?>
                        <?php if ($total_hours > 0): ?>
                            <li>
                                <span class="uk-text-muted"><?php esc_html_e('Contacturen:', 'stride'); ?></span>
                                <span><?php echo esc_html(number_format($total_hours, 1, ',', '.')); ?></span>
                            </li>
                        <?php endif; ?>
                        <li>
                            <span class="uk-text-muted"><?php esc_html_e('Certificaat:', 'stride'); ?></span>
                            <span>
                                <?php if ($is_certificate_enabled): ?>
                                    <span class="uk-text-success" uk-icon="icon: check"></span>
                                <?php else: ?>
                                    <span class="uk-text-muted" uk-icon="icon: minus"></span>
                                <?php endif; ?>
                            </span>
                        </li>
                        <?php if ($is_multi_year): ?>
                            <li>
                                <span class="uk-text-muted"><?php esc_html_e('Meerjarig:', 'stride'); ?></span>
                                <span class="uk-text-primary" uk-icon="icon: check"></span>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- iCal Export -->
                <?php if (!empty($sessions) && $user_id): ?>
                    <div class="stride-card">
                        <a href="#" data-ical-download="<?php echo esc_attr($edition_id); ?>" class="uk-button uk-button-default uk-width-1-1 uk-button-small">
                            <span uk-icon="icon: calendar; ratio: 0.9"></span>
                            <?php esc_html_e('Toevoegen aan agenda', 'stride'); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </aside>
        </div>
    </div>
</div>

<style>
.stride-edition-page {
    padding: 40px 0;
}

.stride-edition-hero-bar {
    background: var(--stride-card-bg, #f8f8f8);
    padding: 16px 20px;
    border-radius: 8px;
}

.stride-edition-hero-bar [uk-icon] {
    margin-right: 4px;
    opacity: 0.7;
}

.stride-enrollment-card {
    position: sticky;
    top: 20px;
}

.stride-enrollment-card-header {
    background: var(--stride-primary, #1e87f0);
    color: white;
    padding: 24px;
    text-align: center;
    border-radius: 8px 8px 0 0;
    margin: -16px -16px 16px -16px;
}

.stride-enrollment-price {
    font-size: 2rem;
    font-weight: 700;
    line-height: 1.2;
}

.stride-enrollment-price-label {
    font-size: 0.85rem;
    opacity: 0.9;
}

.stride-enrollment-price-alt {
    font-size: 0.9rem;
}

.stride-speaker-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: var(--stride-card-bg, #f8f8f8);
    border-radius: 8px;
}

.stride-speaker-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: var(--stride-border, #e5e5e5);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.stride-speaker-info {
    flex: 1;
    min-width: 0;
}

.stride-prose {
    line-height: 1.7;
}

.stride-prose p:last-child {
    margin-bottom: 0;
}

/* Progress bar colors */
.uk-progress-warning::-webkit-progress-value {
    background-color: #faa05a;
}
.uk-progress-warning::-moz-progress-bar {
    background-color: #faa05a;
}
</style>
