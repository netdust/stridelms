<?php
/**
 * Stride Tools index page.
 *
 * Variables in scope:
 *   $items — list of menu items registered via `stride_tools_menu_items` filter.
 */

defined('ABSPATH') || exit;

/** @var array $items */
$items = $items ?? [];
?>
<div class="wrap stride-tools-index">
    <?php
    stride_tool_header(
        'Stride Tools',
        'Beheer cross-cutting tools: mail, audit, autorisatie, LTI en meer.',
    );
?>

    <?php if (empty($items)): ?>
        <p class="stride-tools-empty">
            <?php esc_html_e('Geen tools geregistreerd.', 'stride'); ?>
        </p>
    <?php else: ?>
        <div class="stride-tools-grid">
            <?php foreach ($items as $item):
                $url   = admin_url('admin.php?page=' . urlencode($item['slug']));
                $label = (string) ($item['label'] ?? '');
                $desc  = (string) ($item['description'] ?? '');
                $icon  = (string) ($item['icon'] ?? 'dashicons-admin-generic');
                ?>
                <a class="stride-tool-card" href="<?php echo esc_url($url); ?>">
                    <div class="stride-tool-card__icon">
                        <span class="dashicons <?php echo esc_attr($icon); ?>"></span>
                    </div>
                    <div class="stride-tool-card__body">
                        <h2 class="stride-tool-card__title"><?php echo esc_html($label); ?></h2>
                        <?php if ($desc !== ''): ?>
                            <p class="stride-tool-card__desc"><?php echo esc_html($desc); ?></p>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
