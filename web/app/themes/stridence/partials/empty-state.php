<?php
/**
 * Empty State Partial
 *
 * Renders a centered empty state with icon, title, message, and optional CTA.
 *
 * @param array $args {
 *     @type string $icon    Icon name (default: "search")
 *     @type string $title   Heading text (required)
 *     @type string $message Description text (optional)
 *     @type string $action  Button label (optional)
 *     @type string $url     Button URL (optional, button shown only when BOTH action and url are set)
 *     @type bool   $band    When true, renders in-band catalog variant with bg-surface-alt wrapper (default: false)
 * }
 */

defined('ABSPATH') || exit;

$icon    = $args['icon'] ?? 'search';
$title   = $args['title'] ?? '';
$message = $args['message'] ?? '';
$action  = $args['action'] ?? '';
$url     = $args['url'] ?? '';
$band    = !empty($args['band']);

// Button only shows if both action AND url provided
$show_button = !empty($action) && !empty($url);

if ($band) {
    // Catalog in-band variant: filled alt-surface band, more vertical padding
    $wrapper_class = 'bg-surface-alt rounded-[16px] py-16 px-6 flex flex-col items-center text-center';
} else {
    // Default: plain centered column, transparent background
    $wrapper_class = 'text-center py-12 px-4';
}
?>
<div class="<?php echo esc_attr($wrapper_class); ?>">
    <div class="w-14 h-14 mx-auto mb-4 rounded-full bg-surface-card shadow-card flex items-center justify-center">
        <?php echo stridence_icon($icon, 'w-[22px] h-[22px] text-text-faint'); ?>
    </div>

    <h3 class="font-heading font-bold text-[16px] leading-snug text-text mb-2"><?php echo esc_html($title); ?></h3>

    <?php if ($message) : ?>
        <p class="text-[13px] text-text-muted max-w-[420px] mx-auto mb-4 leading-relaxed"><?php echo esc_html($message); ?></p>
    <?php endif; ?>

    <?php if ($show_button) : ?>
        <a href="<?php echo esc_url($url); ?>" class="btn-ghost"><?php echo esc_html($action); ?></a>
    <?php endif; ?>
</div>
