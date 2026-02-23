<?php
/**
 * Trajectory Enrollment Sidebar Partial
 *
 * Displays trajectory information and price summary in the enrollment form sidebar.
 *
 * Expected variables (passed from parent template):
 * - $itemId (int) - Trajectory ID
 * - $itemTitle (string) - Trajectory title
 * - $heroImage (string|null) - Hero image URL
 * - $price (Money) - Member price
 * - $totalCourses (int) - Total number of courses
 * - $requiredCourses (int) - Number of required courses
 * - $electiveCourses (int) - Number of elective courses
 * - $enrollmentDeadline (string) - Enrollment deadline date
 *
 * @package stride
 */

defined('ABSPATH') || exit;
?>

<!-- Trajectory Sidebar -->
<div class="stride-course-info-card">
    <?php if (!empty($heroImage)): ?>
        <div class="stride-course-info-image">
            <img src="<?php echo esc_url($heroImage); ?>" alt="<?php echo esc_attr($itemTitle); ?>">
        </div>
    <?php endif; ?>

    <div class="stride-course-info-header">
        <div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap">
            <h3 class="stride-course-info-title uk-margin-remove"><?php echo esc_html($itemTitle); ?></h3>
            <span class="uk-label uk-label-primary uk-margin-small-left"><?php esc_html_e('Traject', 'stride'); ?></span>
        </div>
    </div>

    <div class="stride-course-info-body">
        <ul class="stride-course-info-list">
            <!-- Course Count -->
            <li class="stride-course-info-item">
                <span class="stride-course-info-icon" uk-icon="icon: list; ratio: 0.9"></span>
                <span>
                    <?php printf(
                        esc_html(_n('%d cursus', '%d cursussen', $totalCourses, 'stride')),
                        $totalCourses
                    ); ?>
                </span>
            </li>

            <!-- Required/Elective breakdown -->
            <?php if ($requiredCourses > 0 || $electiveCourses > 0): ?>
                <li class="stride-course-info-item">
                    <span class="stride-course-info-icon" uk-icon="icon: check; ratio: 0.9"></span>
                    <span>
                        <?php if ($requiredCourses > 0): ?>
                            <?php printf(
                                esc_html(_n('%d verplicht', '%d verplicht', $requiredCourses, 'stride')),
                                $requiredCourses
                            ); ?>
                        <?php endif; ?>
                        <?php if ($requiredCourses > 0 && $electiveCourses > 0): ?>
                            &middot;
                        <?php endif; ?>
                        <?php if ($electiveCourses > 0): ?>
                            <?php printf(
                                esc_html(_n('%d keuze', '%d keuze', $electiveCourses, 'stride')),
                                $electiveCourses
                            ); ?>
                        <?php endif; ?>
                    </span>
                </li>
            <?php endif; ?>

            <!-- Enrollment deadline -->
            <?php if (!empty($enrollmentDeadline)): ?>
                <li class="stride-course-info-item">
                    <span class="stride-course-info-icon" uk-icon="icon: clock; ratio: 0.9"></span>
                    <span>
                        <?php esc_html_e('Deadline:', 'stride'); ?>
                        <?php echo esc_html(date_i18n('j F Y', strtotime($enrollmentDeadline))); ?>
                    </span>
                </li>
            <?php endif; ?>
        </ul>

        <hr class="uk-margin-small">

        <!-- Price Summary -->
        <table class="uk-table uk-table-small uk-margin-remove-bottom">
            <tbody>
                <tr>
                    <td><?php esc_html_e('Trajectprijs', 'stride'); ?></td>
                    <td class="uk-text-right" id="line-item-price"><?php echo esc_html($price->format()); ?></td>
                </tr>
                <tr id="discount-row" style="display: none;">
                    <td class="uk-text-success"><?php esc_html_e('Korting', 'stride'); ?></td>
                    <td class="uk-text-right uk-text-success" id="discount-amount">- &euro; 0,00</td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Subtotaal', 'stride'); ?></td>
                    <td class="uk-text-right" id="subtotal"><?php echo esc_html($price->format()); ?></td>
                </tr>
                <tr>
                    <td class="uk-text-muted"><?php esc_html_e('BTW (21%)', 'stride'); ?></td>
                    <td class="uk-text-right uk-text-muted" id="tax-amount"><?php
                        $taxAmount = $price->inCents() * 0.21;
                        echo '&euro; ' . number_format($taxAmount / 100, 2, ',', '.');
                    ?></td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="uk-text-bold" style="font-size: 1.1em;">
                    <td><?php esc_html_e('Totaal', 'stride'); ?></td>
                    <td class="uk-text-right" id="total-amount"><?php
                        $totalAmount = $price->inCents() * 1.21;
                        echo '&euro; ' . number_format($totalAmount / 100, 2, ',', '.');
                    ?></td>
                </tr>
            </tfoot>
        </table>

        <hr class="uk-margin-small">

        <!-- Terms (desktop only) -->
        <div class="uk-visible@m">
            <div class="uk-margin-small-bottom">
                <label class="uk-text-small">
                    <input type="checkbox" form="stride-enrollment-form" name="terms_accepted" value="1" required class="uk-checkbox terms-checkbox">
                    <?php printf(
                        esc_html__('Ik ga akkoord met de %salgemene voorwaarden%s', 'stride'),
                        '<a href="' . esc_url(home_url('/algemene-voorwaarden/')) . '" target="_blank">',
                        '</a>'
                    ); ?> *
                </label>
            </div>

            <div class="uk-margin-bottom">
                <label class="uk-text-small">
                    <input type="checkbox" form="stride-enrollment-form" name="cancellation_accepted" value="1" required class="uk-checkbox cancellation-checkbox">
                    <?php esc_html_e('Ik begrijp dat annulering binnen 14 dagen voor aanvang niet mogelijk is', 'stride'); ?> *
                </label>
            </div>

            <button type="submit" form="stride-enrollment-form" class="uk-button uk-button-primary uk-button-large uk-width-1-1 submit-enrollment">
                <?php esc_html_e('Bevestig inschrijving', 'stride'); ?>
            </button>

            <p class="uk-text-small uk-text-muted uk-text-center uk-margin-small-top">
                <?php esc_html_e('Na inschrijving ontvang je een bevestigingsmail met je offerte.', 'stride'); ?>
            </p>
        </div>
    </div>
</div>
