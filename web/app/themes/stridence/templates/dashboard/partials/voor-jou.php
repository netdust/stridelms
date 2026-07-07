<?php
/**
 * Dashboard "Voor jou" curated links (concept C — additive curation).
 *
 * Renders link cards to WP pages promoted to the user's profile type
 * (UserDashboardService::getForYouPages, wired through getHomeData()['for_you']).
 * Pure surfacing, never gating: pages stay reachable regardless of this section.
 *
 * Gated on non-empty — an empty set renders NOTHING (no empty shell, per flow G).
 *
 * @var array $args {
 *     @type array $links Cards: [['id' => int, 'title' => string, 'url' => string], ...]
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$forYouLinks = $args['links'] ?? [];
if (empty($forYouLinks)) {
    return;
}
?>

<section>
    <h3 class="text-base font-semibold text-text mb-3">
        <?php esc_html_e('Voor jou', 'stridence'); ?>
    </h3>
    <div class="grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] gap-[14px]">
        <?php foreach ($forYouLinks as $link) :
            $title = (string) ($link['title'] ?? '');
            $url   = (string) ($link['url'] ?? '');
            if ($title === '' || $url === '') {
                continue;
            }
            ?>
            <a href="<?php echo esc_url($url); ?>"
               class="flex items-center gap-3 bg-surface-card rounded-[12px] shadow-card p-4 hover:bg-surface transition-colors">
                <span class="w-[38px] h-[38px] rounded-[10px] bg-badge-online-bg text-badge-online-text flex items-center justify-center shrink-0">
                    <?php echo stridence_icon('book-open', 'w-[18px] h-[18px]'); ?>
                </span>
                <span class="flex-1 min-w-0 text-[14px] font-bold text-text truncate">
                    <?php echo esc_html($title); ?>
                </span>
                <?php echo stridence_icon('chevron-right', 'w-4 h-4 text-text-faint shrink-0'); ?>
            </a>
        <?php endforeach; ?>
    </div>
</section>
