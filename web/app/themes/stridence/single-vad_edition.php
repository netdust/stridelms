<?php
/**
 * Edition Detail Template
 *
 * Single template for scheduled course editions (vad_edition post type).
 * Two-column layout with session list and sticky enrollment card.
 *
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;

$edition_id = get_the_ID();

// Get services
$editionService = ntdst_get(EditionService::class);
$sessionService = ntdst_get(SessionService::class);

// Get edition data via service
$edition = $editionService->getEdition($edition_id);
if (is_wp_error($edition)) {
    get_template_part('partials/empty-state', null, [
        'icon'    => 'alert-circle',
        'title'   => __('Editie niet gevonden', 'stridence'),
        'message' => __('Deze editie bestaat niet of is verwijderd.', 'stridence'),
        'action'  => __('Naar opleidingen', 'stridence'),
        'url'     => get_post_type_archive_link('sfwd-courses'),
    ]);
    return;
}

// Get edition fields via service
$course_id  = $editionService->getCourseId($edition_id);
$course     = $course_id ? get_post($course_id) : null;
$status     = $editionService->getStatus($edition_id);
$price      = $editionService->getPrice($edition_id);
$can_enroll = $editionService->canEnroll($edition_id);
$capacity   = $editionService->getCapacity($edition_id);

// Get raw meta fields via Data Manager
// Note: These could be added to EditionService if needed frequently
$editionModel = ntdst_data()->get('vad_edition');
$start_date   = $editionModel->getMeta($edition_id, 'start_date', '');
$venue        = $editionModel->getMeta($edition_id, 'venue', '');
$spots        = $editionModel->getMeta($edition_id, 'spots_remaining');

// Get sessions via SessionService
$sessions = $sessionService->getSessionsForEdition($edition_id);

// Breadcrumb items
$breadcrumbs = [
    ['label' => __('Opleidingen', 'stridence'), 'url' => get_post_type_archive_link('sfwd-courses')],
];

if ($course) {
    $breadcrumbs[] = ['label' => get_the_title($course), 'url' => get_permalink($course)];
}

$breadcrumbs[] = ['label' => $start_date ? stride_format_date($start_date) : get_the_title()];

get_header();
?>

<article <?php post_class('pb-12 lg:pb-16'); ?>>
    <!-- Header Section -->
    <div class="bg-surface-alt border-b border-border">
        <div class="container py-8 lg:py-12">
            <?php
            get_template_part('partials/breadcrumb', null, [
                'items' => $breadcrumbs,
            ]);
            ?>

            <div class="flex flex-wrap items-start gap-4 mb-4">
                <h1 class="font-heading text-3xl lg:text-4xl font-bold text-text flex-1">
                    <?php echo $course ? esc_html(get_the_title($course)) : the_title(); ?>
                </h1>
                <?php
                get_template_part('partials/badge-status', null, [
                    'status' => $status->value,
                    'spots'  => $spots,
                ]);
                ?>
            </div>

            <div class="flex flex-wrap gap-6 text-text-muted">
                <?php if ($start_date) : ?>
                    <span class="flex items-center gap-2">
                        <?php echo stridence_icon('calendar', 'w-5 h-5'); ?>
                        <?php echo esc_html(stride_format_date($start_date)); ?>
                    </span>
                <?php endif; ?>

                <?php if ($venue) : ?>
                    <span class="flex items-center gap-2">
                        <?php echo stridence_icon('map-pin', 'w-5 h-5'); ?>
                        <?php echo esc_html($venue); ?>
                    </span>
                <?php endif; ?>

                <?php if (!$price->isZero()) : ?>
                    <span class="flex items-center gap-2 font-semibold text-text">
                        <?php echo stridence_icon('receipt', 'w-5 h-5 text-text-muted'); ?>
                        <?php echo esc_html($price->format()); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Two Column Layout -->
    <div class="container py-8 lg:py-12">
        <div class="grid lg:grid-cols-3 gap-8 lg:gap-12">
            <!-- Main Content (2/3) -->
            <div class="lg:col-span-2 space-y-8">
                <!-- Sessions Section -->
                <section>
                    <h2 class="font-heading text-2xl font-bold text-text mb-4">
                        <?php esc_html_e('Sessies', 'stridence'); ?>
                    </h2>

                    <?php if (!empty($sessions)) : ?>
                        <div class="card divide-y divide-border">
                            <?php foreach ($sessions as $session) : ?>
                                <?php
                                get_template_part('partials/session-row', null, [
                                    'session'    => (object) $session,
                                    'attendance' => null,
                                ]);
                                ?>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <div class="card p-6 text-center text-text-muted">
                            <?php esc_html_e('Sessiedetails worden binnenkort gepubliceerd.', 'stridence'); ?>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- Course Description -->
                <?php if ($course) : ?>
                    <section>
                        <h2 class="font-heading text-2xl font-bold text-text mb-4">
                            <?php esc_html_e('Over deze opleiding', 'stridence'); ?>
                        </h2>
                        <div class="prose-stride max-w-none">
                            <?php echo apply_filters('the_content', $course->post_content); ?>
                        </div>
                    </section>
                <?php endif; ?>
            </div>

            <!-- Sidebar (1/3) - Enrollment Card -->
            <div class="lg:col-span-1">
                <div class="card p-6 sticky top-24">
                    <h3 class="font-heading font-semibold text-lg mb-4">
                        <?php esc_html_e('Inschrijven', 'stridence'); ?>
                    </h3>

                    <div class="space-y-3 mb-6">
                        <div class="flex justify-between">
                            <span class="text-text-muted"><?php esc_html_e('Prijs', 'stridence'); ?></span>
                            <span class="font-semibold">
                                <?php
                                if (!$price->isZero()) {
                                    echo esc_html($price->format());
                                } else {
                                    esc_html_e('Op aanvraag', 'stridence');
                                }
                                ?>
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-text-muted"><?php esc_html_e('Locatie', 'stridence'); ?></span>
                            <span><?php echo $venue ? esc_html($venue) : '-'; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-text-muted"><?php esc_html_e('Startdatum', 'stridence'); ?></span>
                            <span><?php echo $start_date ? esc_html(stride_format_date($start_date)) : '-'; ?></span>
                        </div>
                        <?php if ($spots !== null && $spots !== '' && $can_enroll) : ?>
                            <div class="flex justify-between">
                                <span class="text-text-muted"><?php esc_html_e('Beschikbaar', 'stridence'); ?></span>
                                <span>
                                    <?php
                                    printf(
                                        esc_html(_n('%d plaats', '%d plaatsen', (int) $spots, 'stridence')),
                                        (int) $spots
                                    );
                                    ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($can_enroll) : ?>
                        <a href="<?php echo esc_url(stride_enrollment_url($edition_id)); ?>" class="btn-primary w-full text-center">
                            <?php esc_html_e('Nu inschrijven', 'stridence'); ?>
                        </a>
                    <?php else : ?>
                        <button type="button" class="btn-secondary w-full text-center opacity-50 cursor-not-allowed" disabled>
                            <?php esc_html_e('Niet beschikbaar', 'stridence'); ?>
                        </button>
                        <?php if ($course_id) : ?>
                            <a href="<?php echo esc_url(stride_interest_url($course_id)); ?>" class="btn-ghost w-full text-center mt-3 block">
                                <?php esc_html_e('Interesse melden', 'stridence'); ?>
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Sticky CTA (hidden on lg+) -->
    <?php if ($can_enroll) : ?>
        <div class="fixed bottom-0 left-0 right-0 bg-surface border-t border-border p-4 lg:hidden z-40 safe-area-bottom">
            <a href="<?php echo esc_url(stride_enrollment_url($edition_id)); ?>" class="btn-primary w-full text-center">
                <?php esc_html_e('Nu inschrijven', 'stridence'); ?>
            </a>
        </div>
    <?php endif; ?>
</article>

<?php get_footer(); ?>
