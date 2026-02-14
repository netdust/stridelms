<?php
/**
 * Course Catalog/Archive Template
 *
 * Public course listing with filters.
 *
 * @var array $courses
 * @var int $total_courses
 * @var int $total_pages
 * @var int $current_page
 * @var string $current_category
 * @var string $current_tag
 * @var string $current_search
 * @var array $categories
 * @var array $tags
 * @var bool $show_filters
 * @var DashboardService $dashboard_service
 *
 * @package stride
 */

defined('ABSPATH') || exit;
?>

<div class="stride-course-catalog">
    <div class="uk-container">
        <!-- Page Header -->
        <div class="stride-dashboard-header uk-margin-medium-bottom">
            <h1 class="uk-h2 uk-margin-remove-bottom">
                <?php esc_html_e('Cursusaanbod', 'stride'); ?>
            </h1>
            <p class="uk-text-muted uk-margin-small-top">
                <?php printf(
                    esc_html(_n('%d cursus gevonden', '%d cursussen gevonden', $total_courses, 'stride')),
                    $total_courses
                ); ?>
            </p>
        </div>

        <?php if ($show_filters): ?>
            <!-- Filters -->
            <div class="stride-card uk-margin-medium-bottom">
                <form method="get" action="" class="uk-grid-small" uk-grid>
                    <!-- Search -->
                    <div class="uk-width-1-3@m">
                        <div class="uk-inline uk-width-1-1">
                            <span class="uk-form-icon" uk-icon="icon: search"></span>
                            <input type="text" name="search" class="uk-input"
                                   placeholder="<?php esc_attr_e('Zoek cursussen...', 'stride'); ?>"
                                   value="<?php echo esc_attr($current_search); ?>">
                        </div>
                    </div>

                    <!-- Category Filter -->
                    <div class="uk-width-1-4@m">
                        <select name="category" class="uk-select">
                            <option value=""><?php esc_html_e('Alle categorieën', 'stride'); ?></option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo esc_attr($category->slug); ?>"
                                    <?php selected($current_category, $category->slug); ?>>
                                    <?php echo esc_html($category->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if (!empty($tags)): ?>
                        <!-- Tag Filter -->
                        <div class="uk-width-1-4@m">
                            <select name="tag" class="uk-select">
                                <option value=""><?php esc_html_e('Alle tags', 'stride'); ?></option>
                                <?php foreach ($tags as $tag): ?>
                                    <option value="<?php echo esc_attr($tag->slug); ?>"
                                        <?php selected($current_tag, $tag->slug); ?>>
                                        <?php echo esc_html($tag->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <!-- Submit -->
                    <div class="uk-width-auto@m">
                        <button type="submit" class="uk-button uk-button-primary">
                            <span uk-icon="icon: search"></span>
                            <?php esc_html_e('Zoeken', 'stride'); ?>
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Courses Grid -->
        <?php if (!empty($courses)): ?>
            <div class="stride-courses-grid" uk-grid="masonry: false" data-uk-grid>
                <?php foreach ($courses as $course): ?>
                    <div class="uk-width-1-3@m uk-width-1-2@s">
                        <?php
                        // Default placeholder
                        $thumbnail = $course['thumbnail'] ?: get_stylesheet_directory_uri() . '/assets/images/course-placeholder.jpg';

                        // Type badge
                        $typeBadgeClass = $course['is_online'] ? 'stride-badge-online' : 'stride-badge-in-person';
                        $typeLabel = $course['is_online'] ? __('Online', 'stride') : __('In-person', 'stride');

                        // For in-person editions, check if no editions available
                        $noEditions = !empty($course['no_editions']);
                        ?>
                        <div class="stride-course-card <?php echo $noEditions ? 'no-editions' : ''; ?>">
                            <!-- Course Image -->
                            <div class="stride-course-card-image" style="background-image: url('<?php echo esc_url($thumbnail); ?>');">
                                <span class="stride-badge <?php echo esc_attr($typeBadgeClass); ?>">
                                    <?php echo esc_html($typeLabel); ?>
                                </span>

                                <?php if ($course['is_cancelled']): ?>
                                    <span class="stride-badge stride-badge-cancelled" style="position: absolute; top: 12px; left: 12px;">
                                        <?php esc_html_e('Geannuleerd', 'stride'); ?>
                                    </span>
                                <?php elseif ($course['is_full']): ?>
                                    <span class="stride-badge stride-badge-pending" style="position: absolute; top: 12px; left: 12px;">
                                        <?php esc_html_e('Volzet', 'stride'); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Course Body -->
                            <div class="stride-course-card-body">
                                <h3 class="stride-course-card-title">
                                    <a href="<?php echo esc_url($course['permalink']); ?>">
                                        <?php echo esc_html($course['title']); ?>
                                    </a>
                                </h3>

                                <?php if (!$course['is_online'] && $course['next_date']): ?>
                                    <!-- In-person edition: show date prominently -->
                                    <div class="stride-course-card-meta">
                                        <span uk-icon="icon: calendar; ratio: 0.8"></span>
                                        <strong><?php echo esc_html(date_i18n('l j F Y', $course['next_date'])); ?></strong>
                                    </div>
                                    <?php if (!empty($course['venue'])): ?>
                                        <div class="stride-course-card-meta">
                                            <span uk-icon="icon: location; ratio: 0.8"></span>
                                            <?php echo esc_html($course['venue']); ?>
                                        </div>
                                    <?php endif; ?>
                                <?php elseif (!$course['is_online'] && $noEditions): ?>
                                    <!-- No editions available -->
                                    <div class="stride-course-card-meta uk-text-muted">
                                        <span uk-icon="icon: calendar; ratio: 0.8"></span>
                                        <?php esc_html_e('Geen geplande data', 'stride'); ?>
                                    </div>
                                <?php elseif ($course['is_online']): ?>
                                    <!-- Online course -->
                                    <div class="stride-course-card-meta">
                                        <span uk-icon="icon: play-circle; ratio: 0.8"></span>
                                        <?php esc_html_e('Direct beschikbaar', 'stride'); ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($course['excerpt'] && $course['is_online']): ?>
                                    <p class="uk-text-small uk-text-muted uk-margin-small-bottom">
                                        <?php echo esc_html(wp_trim_words($course['excerpt'], 15)); ?>
                                    </p>
                                <?php endif; ?>

                                <!-- Card Footer -->
                                <div class="stride-course-card-footer">
                                    <div>
                                        <?php if ($course['price'] !== null): ?>
                                            <span class="uk-text-bold">
                                                <?php echo esc_html('€ ' . number_format($course['price'], 2, ',', '.')); ?>
                                            </span>
                                        <?php elseif ($course['is_online']): ?>
                                            <span class="uk-text-success uk-text-bold">
                                                <?php esc_html_e('Gratis', 'stride'); ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if ($course['available_spots'] !== null && $course['available_spots'] <= 5 && !$course['is_full']): ?>
                                            <span class="uk-text-warning uk-text-small uk-margin-small-left">
                                                <?php printf(
                                                    esc_html(_n('Nog %d plaats', 'Nog %d plaatsen', $course['available_spots'], 'stride')),
                                                    $course['available_spots']
                                                ); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($noEditions): ?>
                                        <span class="uk-button uk-button-default uk-button-small" disabled>
                                            <?php esc_html_e('Binnenkort', 'stride'); ?>
                                        </span>
                                    <?php elseif ($course['is_online']): ?>
                                        <a href="<?php echo esc_url($course['permalink']); ?>"
                                           class="uk-button uk-button-primary uk-button-small">
                                            <?php esc_html_e('Start cursus', 'stride'); ?>
                                        </a>
                                    <?php else: ?>
                                        <a href="<?php echo esc_url($course['permalink']); ?>"
                                           class="uk-button uk-button-primary uk-button-small">
                                            <?php esc_html_e('Inschrijven', 'stride'); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="uk-margin-large-top">
                    <ul class="uk-pagination uk-flex-center" uk-margin>
                        <?php if ($current_page > 1): ?>
                            <li>
                                <a href="<?php echo esc_url(add_query_arg('paged', $current_page - 1)); ?>">
                                    <span uk-pagination-previous></span>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="<?php echo $i === $current_page ? 'uk-active' : ''; ?>">
                                <a href="<?php echo esc_url(add_query_arg('paged', $i)); ?>">
                                    <?php echo esc_html($i); ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($current_page < $total_pages): ?>
                            <li>
                                <a href="<?php echo esc_url(add_query_arg('paged', $current_page + 1)); ?>">
                                    <span uk-pagination-next></span>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="stride-empty-state">
                <span class="stride-empty-state-icon" uk-icon="icon: search; ratio: 3"></span>
                <h3 class="stride-empty-state-title">
                    <?php esc_html_e('Geen cursussen gevonden', 'stride'); ?>
                </h3>
                <p class="stride-empty-state-text">
                    <?php esc_html_e('Probeer andere zoektermen of filters.', 'stride'); ?>
                </p>
                <?php if ($current_search || $current_category || $current_tag): ?>
                    <a href="<?php echo esc_url(strtok($_SERVER['REQUEST_URI'], '?')); ?>" class="uk-button uk-button-default">
                        <?php esc_html_e('Filters wissen', 'stride'); ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
