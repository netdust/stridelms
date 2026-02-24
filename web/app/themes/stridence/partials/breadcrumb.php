<?php
/**
 * Breadcrumb Partial
 *
 * Renders a breadcrumb navigation with Home link and chevron separators.
 *
 * @param array $args {
 *     @type array $items Array of breadcrumb items, each with 'label' and 'url' keys.
 *                        Last item is shown as plain text (current page).
 * }
 */

defined('ABSPATH') || exit;

$items = $args['items'] ?? [];

// Return early if no items
if (empty($items)) {
    return;
}

$last_index = count($items) - 1;
?>
<nav aria-label="<?php esc_attr_e('Breadcrumb', 'stridence'); ?>" class="text-sm text-text-muted mb-6">
    <ol class="flex items-center flex-wrap gap-1">
        <li>
            <a href="<?php echo esc_url(home_url('/')); ?>" class="hover:text-primary">Home</a>
        </li>
        <?php foreach ($items as $index => $item): ?>
            <li class="flex items-center gap-1">
                <?php echo stridence_icon('chevron-right', 'w-3 h-3'); ?>
                <?php if ($index === $last_index): ?>
                    <span class="text-text"><?php echo esc_html($item['label']); ?></span>
                <?php else: ?>
                    <a href="<?php echo esc_url($item['url']); ?>" class="hover:text-primary"><?php echo esc_html($item['label']); ?></a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
