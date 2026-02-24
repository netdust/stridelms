<?php
/**
 * Course Detail Template (E-learning)
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

get_header();

$courseId = get_the_ID();
$course = get_post($courseId);

// Get course meta
$duration = get_post_meta($courseId, '_ld_course_duration', true) ?: '';
$price = get_post_meta($courseId, '_ld_course_price', true) ?: 0;
$price_type = get_post_meta($courseId, '_ld_course_price_type', true) ?: 'free';

// Check if user has access
$hasAccess = false;
if (is_user_logged_in() && function_exists('sfwd_lms_has_access')) {
    $hasAccess = sfwd_lms_has_access($courseId, get_current_user_id());
}

// Get course features/what you'll learn
$features = [
    __('Volledige online toegang', 'stridence'),
    __('Leer op je eigen tempo', 'stridence'),
    __('Certificaat na voltooiing', 'stridence'),
    __('Toegang tot alle materialen', 'stridence'),
];
?>

<main class="str-main">
    <div class="str-container">
        <article class="str-detail">
            <div class="str-detail__grid">
                <div class="str-detail__main">
                    <!-- Hero -->
                    <div class="str-detail__hero">
                        <?php if (has_post_thumbnail()): ?>
                            <?php the_post_thumbnail('large', ['class' => 'str-detail__hero-image']); ?>
                        <?php else: ?>
                            <div class="str-detail__hero-placeholder">
                                <?php stridence_icon('laptop', '', 64); ?>
                            </div>
                        <?php endif; ?>

                        <div class="str-detail__badges">
                            <span class="str-type-badge str-type-badge--elearning">
                                <?php esc_html_e('E-learning', 'stridence'); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Header -->
                    <header class="str-detail__header">
                        <h1 class="str-detail__title"><?php the_title(); ?></h1>

                        <div class="str-detail__meta">
                            <?php if ($duration): ?>
                                <span class="str-detail__meta-item">
                                    <?php stridence_icon('clock', '', 20); ?>
                                    <?php echo esc_html($duration); ?>
                                </span>
                            <?php endif; ?>

                            <span class="str-detail__meta-item">
                                <?php stridence_icon('laptop', '', 20); ?>
                                <?php esc_html_e('Online cursus', 'stridence'); ?>
                            </span>

                            <span class="str-detail__meta-item">
                                <?php stridence_icon('award', '', 20); ?>
                                <?php esc_html_e('Certificaat', 'stridence'); ?>
                            </span>
                        </div>
                    </header>

                    <!-- Content -->
                    <div class="str-detail__content">
                        <?php the_content(); ?>
                    </div>

                    <!-- Course curriculum (if LearnDash) -->
                    <?php if (function_exists('learndash_get_course_steps')): ?>
                        <?php
                        $steps = learndash_get_course_steps($courseId);
                        if (!empty($steps)):
                        ?>
                        <section class="str-curriculum">
                            <h2><?php esc_html_e('Cursusinhoud', 'stridence'); ?></h2>
                            <div class="str-curriculum__list">
                                <?php foreach ($steps as $stepId):
                                    $step = get_post($stepId);
                                    if (!$step) continue;
                                    $stepType = get_post_type($step);
                                ?>
                                    <div class="str-curriculum__item">
                                        <span class="str-curriculum__icon">
                                            <?php if ($stepType === 'sfwd-lessons'): ?>
                                                <?php stridence_icon('book', '', 18); ?>
                                            <?php elseif ($stepType === 'sfwd-quiz'): ?>
                                                <?php stridence_icon('check-circle', '', 18); ?>
                                            <?php else: ?>
                                                <?php stridence_icon('file-text', '', 18); ?>
                                            <?php endif; ?>
                                        </span>
                                        <span class="str-curriculum__title">
                                            <?php echo esc_html($step->post_title); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Sidebar -->
                <aside class="str-detail__sidebar">
                    <div class="str-sidebar-card">
                        <?php if ($price_type !== 'free' && $price > 0): ?>
                            <div class="str-sidebar-card__price">
                                €<?php echo esc_html(number_format((float)$price, 2, ',', '.')); ?>
                                <span class="str-sidebar-card__price-note"><?php esc_html_e('excl. BTW', 'stridence'); ?></span>
                            </div>
                        <?php else: ?>
                            <div class="str-sidebar-card__price">
                                <?php esc_html_e('Gratis', 'stridence'); ?>
                            </div>
                        <?php endif; ?>

                        <div class="str-sidebar-card__cta">
                            <?php if ($hasAccess): ?>
                                <a href="<?php the_permalink(); ?>" class="str-btn str-btn--primary str-btn--block str-btn--lg">
                                    <?php stridence_icon('play', '', 20); ?>
                                    <?php esc_html_e('Doorgaan met leren', 'stridence'); ?>
                                </a>
                            <?php elseif (is_user_logged_in()): ?>
                                <a href="<?php the_permalink(); ?>" class="str-btn str-btn--primary str-btn--block str-btn--lg">
                                    <?php esc_html_e('Inschrijven', 'stridence'); ?>
                                </a>
                            <?php else: ?>
                                <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="str-btn str-btn--primary str-btn--block str-btn--lg">
                                    <?php esc_html_e('Inloggen om in te schrijven', 'stridence'); ?>
                                </a>
                            <?php endif; ?>
                        </div>

                        <ul class="str-sidebar-card__features">
                            <?php foreach ($features as $feature): ?>
                                <li class="str-sidebar-card__feature">
                                    <?php stridence_icon('check', '', 18); ?>
                                    <?php echo esc_html($feature); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </aside>
            </div>
        </article>
    </div>
</main>

<?php get_footer(); ?>
