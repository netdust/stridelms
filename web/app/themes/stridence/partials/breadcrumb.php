<?php
/**
 * Breadcrumb Partial — Helder Tij
 *
 * Renders a breadcrumb navigation with Home link and `›` separators.
 * 13px, faint text; links darken on hover; current crumb is dark + semibold.
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
<nav aria-label="<?php esc_attr_e('Breadcrumb', 'stridence'); ?>" class="text-[13px] text-text-faint mb-6">
    <ol class="flex items-center flex-wrap gap-2">
        <li>
            <a href="<?php echo esc_url(home_url('/')); ?>" class="hover:text-text transition-colors">Home</a>
        </li>
        <?php foreach ($items as $index => $item): ?>
            <li class="flex items-center gap-2">
                <span class="text-border-strong" aria-hidden="true">&rsaquo;</span>
                <?php if ($index === $last_index): ?>
                    <span class="text-text font-semibold" aria-current="page"><?php echo esc_html($item['label']); ?></span>
                <?php else: ?>
                    <a href="<?php echo esc_url($item['url']); ?>" class="hover:text-text transition-colors"><?php echo esc_html($item['label']); ?></a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
