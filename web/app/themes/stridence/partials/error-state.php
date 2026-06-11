<?php
/**
 * Error State Partial
 *
 * Centered error card with icon, title, message and primary action button.
 *
 * @param array $args {
 *     @type string $icon         Icon slug for stridence_icon() (default: "alert-circle")
 *     @type string $title        Error heading
 *     @type string $message      Body text
 *     @type string $action_label Button label (default: "Opnieuw proberen")
 *     @type string $action_url   Button URL
 * }
 */
defined('ABSPATH') || exit;

$icon         = $args['icon'] ?? 'alert-circle';
$title        = $args['title'] ?? '';
$message      = $args['message'] ?? '';
$action_label = $args['action_label'] ?? __('Opnieuw proberen', 'stridence');
$action_url   = $args['action_url'] ?? '';
?>
<div class="container py-8 lg:py-12">
    <div class="text-center py-10 px-6 max-w-lg mx-auto">
        <div class="w-14 h-14 mx-auto mb-4 rounded-full bg-badge-full-bg text-badge-full-text flex items-center justify-center font-extrabold text-[22px] leading-none">
            <?php echo stridence_icon($icon, 'w-[22px] h-[22px] text-badge-full-text'); ?>
        </div>

        <h2 class="font-heading font-bold text-[16px] leading-snug text-text mb-2"><?php echo esc_html($title); ?></h2>

        <?php if ($message) : ?>
            <p class="text-[13px] text-text-muted max-w-[420px] mx-auto mb-6 leading-relaxed"><?php echo esc_html($message); ?></p>
        <?php endif; ?>

        <?php if ($action_url) : ?>
            <a href="<?php echo esc_url($action_url); ?>" class="btn-primary"><?php echo esc_html($action_label); ?></a>
        <?php endif; ?>
    </div>
</div>
