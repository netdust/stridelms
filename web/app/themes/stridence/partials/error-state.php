<?php
/**
 * Centered error card with icon, title, message and action link.
 *
 * @param array $args {
 *     @type string $icon         Icon slug for stridence_icon()
 *     @type string $title        Error heading
 *     @type string $message      Body text
 *     @type string $action_label Button label
 *     @type string $action_url   Button URL
 * }
 */
defined('ABSPATH') || exit;

$icon         = $args['icon'] ?? 'alert-circle';
$title        = $args['title'] ?? '';
$message      = $args['message'] ?? '';
$action_label = $args['action_label'] ?? '';
$action_url   = $args['action_url'] ?? '';
?>
<div class="container py-8 lg:py-12">
    <div class="card p-8 text-center max-w-lg mx-auto">
        <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-error/10 flex items-center justify-center">
            <?php echo stridence_icon($icon, 'w-8 h-8 text-error'); ?>
        </div>
        <h2 class="text-lg font-semibold mb-2"><?php echo esc_html($title); ?></h2>
        <p class="text-text-muted mb-6"><?php echo esc_html($message); ?></p>
        <a href="<?php echo esc_url($action_url); ?>" class="btn-primary">
            <?php echo stridence_icon('arrow-left', 'w-4 h-4 mr-2'); ?>
            <?php echo esc_html($action_label); ?>
        </a>
    </div>
</div>
