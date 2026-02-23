<?php
/**
 * Course Single Template
 *
 * Custom landing page for LearnDash courses with hero, progress, and lesson accordion.
 * Uses LearnDashHelper for clean access to all LearnDash features.
 *
 * @package stride
 */

defined('ABSPATH') || exit;

use Stride\Integrations\LearnDash\LearnDashHelper;

// Course data
$courseId = get_the_ID();
$courseTitle = get_the_title();
$courseDescription = get_the_excerpt() ?: wp_trim_words(get_the_content(), 30);
$thumbnail = get_the_post_thumbnail_url($courseId, 'large');

// User data
$userId = get_current_user_id();
$isLoggedIn = is_user_logged_in();

// LearnDash data via helper
$hasAccess = LearnDashHelper::hasAccess($courseId, $userId);
$accessMode = LearnDashHelper::getAccessMode($courseId);
$progress = LearnDashHelper::getProgress($courseId, $userId);
$lessons = LearnDashHelper::getLessons($courseId, $userId);
$lessonCount = count($lessons);
$certificateLink = LearnDashHelper::getCertificateLink($courseId, $userId);
$courseMaterials = LearnDashHelper::getCourseMaterials($courseId);
$hasCertificate = LearnDashHelper::hasCertificate($courseId);
$priceInfo = LearnDashHelper::getCoursePrice($courseId);

// Get the appropriate CTA
$cta = LearnDashHelper::getCourseAction($courseId, $userId);

// Course type detection (check if has sessions - classroom vs online)
$isOnline = true;
$editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);
$editions = $editionService->getEditionsForCourse($courseId);
if (!empty($editions)) {
    $sessionService = ntdst_get(\Stride\Modules\Edition\SessionService::class);
    foreach ($editions as $edition) {
        $sessions = $sessionService->getSessionsForEdition($edition['id'] ?? $edition->ID);
        foreach ($sessions as $session) {
            if (in_array($session['type'] ?? '', ['in_person', 'webinar'], true)) {
                $isOnline = false;
                break 2;
            }
        }
    }
}
$courseTypeLabel = $isOnline ? __('E-learning', 'stride') : __('Klassikaal', 'stride');

// Access mode label for display
$accessModeLabels = [
    LearnDashHelper::MODE_OPEN => __('Open', 'stride'),
    LearnDashHelper::MODE_FREE => __('Gratis', 'stride'),
    LearnDashHelper::MODE_PAYNOW => LearnDashHelper::formatPrice($priceInfo),
    LearnDashHelper::MODE_SUBSCRIBE => __('Abonnement', 'stride'),
    LearnDashHelper::MODE_CLOSED => __('Op aanvraag', 'stride'),
];
?>

<div class="stride-course-single">
    <!-- Hero Section -->
    <section class="stride-course-hero">
        <?php if ($thumbnail): ?>
            <div class="stride-course-hero__image">
                <img src="<?php echo esc_url($thumbnail); ?>" alt="<?php echo esc_attr($courseTitle); ?>">
            </div>
        <?php endif; ?>

        <div class="stride-course-hero__content">
            <div class="stride-course-hero__meta">
                <span class="stride-course-hero__badge stride-course-hero__badge--<?php echo $isOnline ? 'online' : 'classroom'; ?>">
                    <?php echo esc_html($courseTypeLabel); ?>
                </span>
                <?php if ($accessMode !== LearnDashHelper::MODE_OPEN): ?>
                    <span class="stride-course-hero__badge stride-course-hero__badge--price">
                        <?php echo esc_html($accessModeLabels[$accessMode] ?? ''); ?>
                    </span>
                <?php endif; ?>
                <?php if ($lessonCount > 0): ?>
                    <span class="stride-course-hero__lessons">
                        <span uk-icon="icon: clock; ratio: 0.8"></span>
                        <?php printf(_n('%d les', '%d lessen', $lessonCount, 'stride'), $lessonCount); ?>
                    </span>
                <?php endif; ?>
            </div>

            <h1 class="stride-course-hero__title"><?php echo esc_html($courseTitle); ?></h1>

            <?php if ($courseDescription): ?>
                <p class="stride-course-hero__description"><?php echo esc_html($courseDescription); ?></p>
            <?php endif; ?>

            <?php if ($hasAccess && $progress > 0): ?>
                <!-- Progress Card -->
                <div class="stride-course-progress">
                    <div class="stride-course-progress__header">
                        <span class="stride-course-progress__label"><?php esc_html_e('Je voortgang', 'stride'); ?></span>
                        <span class="stride-course-progress__percentage"><?php echo (int) $progress; ?>%</span>
                    </div>
                    <div class="stride-course-progress__bar">
                        <div class="stride-course-progress__fill" style="width: <?php echo (int) $progress; ?>%;"></div>
                    </div>
                    <?php if ($certificateLink): ?>
                        <a href="<?php echo esc_url($certificateLink); ?>" class="stride-course-progress__certificate" target="_blank">
                            <span uk-icon="icon: file-pdf; ratio: 0.9"></span>
                            <?php esc_html_e('Download Certificaat', 'stride'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- CTA Button -->
            <?php if ($cta['url']): ?>
                <div class="stride-course-hero__actions">
                    <a href="<?php echo esc_url($cta['url']); ?>" class="uk-button uk-button-primary uk-button-large stride-course-hero__cta">
                        <?php if ($cta['action'] === 'start' || $cta['action'] === 'continue'): ?>
                            <span uk-icon="icon: play-circle; ratio: 0.9"></span>
                        <?php elseif ($cta['action'] === 'buy' || $cta['action'] === 'subscribe'): ?>
                            <span uk-icon="icon: cart; ratio: 0.9"></span>
                        <?php elseif ($cta['action'] === 'login'): ?>
                            <span uk-icon="icon: sign-in; ratio: 0.9"></span>
                        <?php endif; ?>
                        <?php echo esc_html($cta['label']); ?>
                    </a>

                    <?php if ($cta['show_login'] && !$isLoggedIn): ?>
                        <p class="stride-course-hero__login-hint uk-text-small uk-text-muted uk-margin-small-top">
                            <?php
                            printf(
                                /* translators: %s: login link */
                                esc_html__('Heb je al een account? %s', 'stride'),
                                '<a href="' . esc_url(wp_login_url(get_permalink($courseId))) . '">' . esc_html__('Log in', 'stride') . '</a>'
                            );
                            ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Content Accordions -->
    <section class="stride-course-content uk-container uk-container-small">
        <ul uk-accordion="multiple: true">
            <!-- Lessons -->
            <li class="uk-open">
                <a class="uk-accordion-title" href>
                    <?php printf(esc_html__('Lessen (%d)', 'stride'), $lessonCount); ?>
                </a>
                <div class="uk-accordion-content">
                    <?php if (!empty($lessons)): ?>
                        <ul class="stride-lesson-list">
                            <?php foreach ($lessons as $index => $lesson): ?>
                                <li class="stride-lesson-list__item <?php echo $lesson['completed'] ? 'stride-lesson-list__item--complete' : ''; ?>">
                                    <a href="<?php echo esc_url($lesson['url']); ?>">
                                        <span class="stride-lesson-list__status">
                                            <?php if ($lesson['completed']): ?>
                                                <span uk-icon="icon: check; ratio: 0.8"></span>
                                            <?php else: ?>
                                                <span class="stride-lesson-list__number"><?php echo $index + 1; ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="stride-lesson-list__title"><?php echo esc_html($lesson['title']); ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="uk-text-muted"><?php esc_html_e('Geen lessen beschikbaar.', 'stride'); ?></p>
                    <?php endif; ?>
                </div>
            </li>

            <!-- What You'll Learn -->
            <?php if ($courseMaterials): ?>
                <li>
                    <a class="uk-accordion-title" href>
                        <?php esc_html_e('Wat je leert', 'stride'); ?>
                    </a>
                    <div class="uk-accordion-content">
                        <div class="stride-course-materials">
                            <?php echo wp_kses_post($courseMaterials); ?>
                        </div>
                    </div>
                </li>
            <?php endif; ?>

            <!-- Certificate -->
            <?php if ($hasCertificate): ?>
                <li>
                    <a class="uk-accordion-title" href>
                        <?php esc_html_e('Certificaat', 'stride'); ?>
                    </a>
                    <div class="uk-accordion-content">
                        <div class="stride-certificate-info">
                            <span uk-icon="icon: file-pdf; ratio: 1.2"></span>
                            <div>
                                <p><?php esc_html_e('Na succesvolle afronding ontvang je een certificaat.', 'stride'); ?></p>
                                <?php if ($certificateLink): ?>
                                    <a href="<?php echo esc_url($certificateLink); ?>" class="uk-button uk-button-secondary uk-button-small" target="_blank">
                                        <?php esc_html_e('Download Certificaat', 'stride'); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </li>
            <?php endif; ?>

            <!-- Editions (if classroom course with scheduled sessions) -->
            <?php if (!empty($editions)): ?>
                <li>
                    <a class="uk-accordion-title" href>
                        <?php esc_html_e('Geplande sessies', 'stride'); ?>
                    </a>
                    <div class="uk-accordion-content">
                        <ul class="stride-edition-list">
                            <?php foreach ($editions as $edition):
                                $editionId = $edition['id'] ?? $edition->ID;
                                $editionTitle = $edition['title'] ?? get_the_title($editionId);
                                $startDate = $edition['start_date'] ?? '';
                            ?>
                                <li class="stride-edition-list__item">
                                    <a href="<?php echo esc_url(get_permalink($editionId)); ?>">
                                        <span class="stride-edition-list__title"><?php echo esc_html($editionTitle); ?></span>
                                        <?php if ($startDate): ?>
                                            <span class="stride-edition-list__date">
                                                <?php echo esc_html(date_i18n('j F Y', strtotime($startDate))); ?>
                                            </span>
                                        <?php endif; ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </li>
            <?php endif; ?>
        </ul>
    </section>
</div>
