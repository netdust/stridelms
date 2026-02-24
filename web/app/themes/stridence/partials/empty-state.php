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
 *     @type string $url     Button URL (optional)
 * }
 */

defined('ABSPATH') || exit;

$icon    = $args['icon'] ?? 'search';
$title   = $args['title'] ?? '';
$message = $args['message'] ?? '';
$action  = $args['action'] ?? '';
$url     = $args['url'] ?? '';

// Button only shows if both action AND url provided
$show_button = !empty($action) && !empty($url);

?>
<div class="text-center py-12 px-4">
    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-surface-alt flex items-center justify-center">
        <?php echo stridence_icon($icon, 'w-8 h-8 text-text-muted'); ?>
    </div>

    <h3 class="font-heading font-semibold text-lg text-text mb-2"><?php echo esc_html($title); ?></h3>

    <?php if ($message): ?>
        <p class="text-text-muted max-w-md mx-auto mb-6"><?php echo esc_html($message); ?></p>
    <?php endif; ?>

    <?php if ($show_button): ?>
        <a href="<?php echo esc_url($url); ?>" class="btn-primary"><?php echo esc_html($action); ?></a>
    <?php endif; ?>
</div>
