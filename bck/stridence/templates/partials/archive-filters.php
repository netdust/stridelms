<?php
/**
 * Archive Filters Component
 *
 * @package stridence
 *
 * @var string $current_type Current filter type ('all', 'elearning', 'classroom')
 * @var array  $categories   Available categories
 */

defined('ABSPATH') || exit;

$current_type = $current_type ?? 'all';
$base_url = get_post_type_archive_link('sfwd-courses');
?>

<div class="str-filters">
    <button type="button" class="str-filters__toggle str-btn str-btn--secondary" aria-expanded="false">
        <?php stridence_icon('filter', '', 18); ?>
        <?php esc_html_e('Filters', 'stridence'); ?>
    </button>

    <div class="str-filters__panel" hidden>
        <div class="str-filters__group">
            <span class="str-filters__label"><?php esc_html_e('Type', 'stridence'); ?></span>
            <div class="str-filters__options">
                <a href="<?php echo esc_url(home_url('/cursussen/')); ?>"
                   class="str-filters__option <?php echo $current_type === 'all' ? 'str-filters__option--active' : ''; ?>">
                    <?php esc_html_e('Alle', 'stridence'); ?>
                </a>
                <a href="<?php echo esc_url(home_url('/cursussen/e-learning/')); ?>"
                   class="str-filters__option <?php echo $current_type === 'elearning' ? 'str-filters__option--active' : ''; ?>">
                    <?php esc_html_e('E-learning', 'stridence'); ?>
                </a>
                <a href="<?php echo esc_url(home_url('/cursussen/klassikaal/')); ?>"
                   class="str-filters__option <?php echo $current_type === 'classroom' ? 'str-filters__option--active' : ''; ?>">
                    <?php esc_html_e('Klassikaal', 'stridence'); ?>
                </a>
            </div>
        </div>

        <?php if (!empty($categories)): ?>
        <div class="str-filters__group">
            <span class="str-filters__label"><?php esc_html_e('Categorie', 'stridence'); ?></span>
            <div class="str-filters__options">
                <?php foreach ($categories as $cat): ?>
                    <a href="<?php echo esc_url(add_query_arg('category', $cat->slug)); ?>"
                       class="str-filters__option">
                        <?php echo esc_html($cat->name); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
