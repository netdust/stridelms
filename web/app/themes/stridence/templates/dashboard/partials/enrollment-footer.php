<?php
/**
 * Enrollment Card Footer Partial
 *
 * Renders the footer bar with CTA link, detail link, and optional calendar button.
 *
 * @param array $args {
 *     @type array|null $cta        ['url' => string, 'label' => string] or null
 *     @type string     $detail_url Detail page URL (empty = no link)
 *     @type int        $edition_id Edition ID for calendar button (0 = no calendar)
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$cta       = $args['cta'] ?? null;
$detailUrl = $args['detail_url'] ?? '';
$editionId = (int) ($args['edition_id'] ?? 0);
?>
<div class="flex items-center gap-3 px-4 py-2 border-t border-border bg-surface-alt/60 rounded-b-xl">
    <?php if ($cta && !empty($cta['url'])) : ?>
        <a href="<?php echo esc_url($cta['url']); ?>"
           class="text-xs font-semibold text-primary hover:underline">
            <?php echo esc_html($cta['label']); ?>
        </a>
    <?php endif; ?>
    <?php if ($detailUrl) : ?>
        <a href="<?php echo esc_url($detailUrl); ?>"
           class="text-xs text-text-muted hover:text-text transition-colors">
            <?php esc_html_e('Bekijk', 'stridence'); ?>
        </a>
    <?php endif; ?>
    <?php if ($editionId > 0) : ?>
        <button type="button"
                class="ml-auto text-text-muted hover:text-primary transition-colors cursor-pointer"
                @click="downloadIcal(<?php echo $editionId; ?>)"
                title="<?php esc_attr_e('Toevoegen aan agenda', 'stridence'); ?>">
            <?php echo stridence_icon('calendar', 'w-3.5 h-3.5'); ?>
        </button>
    <?php endif; ?>
</div>
