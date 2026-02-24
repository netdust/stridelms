<?php
/**
 * Dashboard Tab: Offertes (Quotes)
 *
 * Shows user's quotes grouped by status.
 * Uses QuoteService for data access.
 *
 * @param array $args {
 *     @type WP_User $user Current user object
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Domain\QuoteStatus;
use Stride\Modules\Invoicing\QuoteService;

$user    = $args['user'] ?? wp_get_current_user();
$user_id = $user->ID;

// Get quote service
$quoteService = ntdst_get(QuoteService::class);

// Get all user quotes
$quotes = $quoteService->getUserQuotes($user_id);

// Group quotes by status
$active    = [];
$cancelled = [];

foreach ($quotes as $quote) {
    $status = $quote['status_enum'] ?? QuoteStatus::Draft;

    $quote_data = [
        'id'           => (int) ($quote['ID'] ?? $quote['id'] ?? 0),
        'quote_number' => $quote['quote_number'] ?? '',
        'title'        => $quote['post_title'] ?? $quote['title'] ?? '',
        'status'       => $status,
        'status_label' => $status->label(),
        'total'        => $quote['total_money'],
        'subtotal'     => $quote['subtotal_money'],
        'discount'     => $quote['discount_money'],
        'tax'          => $quote['tax_money'],
        'items'        => $quote['items'] ?? [],
        'valid_until'  => $quote['valid_until'] ?? '',
        'created_at'   => $quote['post_date'] ?? '',
        'voucher_code' => $quote['voucher_code'] ?? '',
    ];

    if ($status === QuoteStatus::Cancelled) {
        $cancelled[] = $quote_data;
    } else {
        $active[] = $quote_data;
    }
}

// Sort active by creation date (newest first)
usort($active, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

/**
 * Get badge classes for quote status.
 */
function stridence_quote_status_classes(QuoteStatus $status): string
{
    return match ($status) {
        QuoteStatus::Draft => 'bg-amber-100 text-amber-800',
        QuoteStatus::Sent => 'bg-blue-100 text-blue-800',
        QuoteStatus::Exported => 'bg-green-100 text-green-800',
        QuoteStatus::Cancelled => 'bg-gray-100 text-gray-500',
    };
}
?>

<div class="space-y-8">
    <!-- Active Quotes -->
    <section>
        <h2 class="font-heading text-xl font-bold text-text mb-4">
            <?php esc_html_e('Mijn offertes', 'stridence'); ?>
        </h2>

        <?php if (!empty($active)) : ?>
            <div class="space-y-4">
                <?php foreach ($active as $quote) : ?>
                    <div class="card" x-data="expandable()">
                        <button type="button"
                                class="w-full p-4 flex items-center justify-between gap-4 text-left"
                                @click="toggle()">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-mono text-sm text-text-muted">
                                        <?php echo esc_html($quote['quote_number']); ?>
                                    </span>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo esc_attr(stridence_quote_status_classes($quote['status'])); ?>">
                                        <?php echo esc_html($quote['status_label']); ?>
                                    </span>
                                </div>
                                <h3 class="font-semibold text-text truncate mt-1">
                                    <?php echo esc_html($quote['title']); ?>
                                </h3>
                                <div class="flex flex-wrap gap-4 mt-1 text-sm text-text-muted">
                                    <?php if ($quote['created_at']) : ?>
                                        <span class="flex items-center gap-1">
                                            <?php echo stridence_icon('calendar', 'w-4 h-4'); ?>
                                            <?php echo esc_html(stride_format_date($quote['created_at'])); ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="flex items-center gap-1 font-medium text-text">
                                        <?php echo esc_html($quote['total']->format()); ?>
                                    </span>
                                </div>
                            </div>
                            <span class="shrink-0 text-text-muted transition-transform duration-200"
                                  :class="{ 'rotate-180': open }">
                                <?php echo stridence_icon('chevron-down', 'w-5 h-5'); ?>
                            </span>
                        </button>

                        <div x-show="open" x-collapse class="border-t border-border">
                            <div class="p-4 space-y-4">
                                <!-- Quote Details -->
                                <dl class="grid grid-cols-2 gap-4 text-sm">
                                    <div>
                                        <dt class="text-text-muted"><?php esc_html_e('Subtotaal', 'stridence'); ?></dt>
                                        <dd class="font-medium"><?php echo esc_html($quote['subtotal']->format()); ?></dd>
                                    </div>
                                    <?php if (!$quote['discount']->isZero()) : ?>
                                        <div>
                                            <dt class="text-text-muted"><?php esc_html_e('Korting', 'stridence'); ?></dt>
                                            <dd class="font-medium text-green-600">
                                                -<?php echo esc_html($quote['discount']->format()); ?>
                                                <?php if ($quote['voucher_code']) : ?>
                                                    <span class="text-xs text-text-muted">(<?php echo esc_html($quote['voucher_code']); ?>)</span>
                                                <?php endif; ?>
                                            </dd>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <dt class="text-text-muted"><?php esc_html_e('BTW (21%)', 'stridence'); ?></dt>
                                        <dd class="font-medium"><?php echo esc_html($quote['tax']->format()); ?></dd>
                                    </div>
                                    <div>
                                        <dt class="text-text-muted"><?php esc_html_e('Totaal', 'stridence'); ?></dt>
                                        <dd class="font-bold text-lg"><?php echo esc_html($quote['total']->format()); ?></dd>
                                    </div>
                                    <?php if ($quote['valid_until']) : ?>
                                        <div class="col-span-2">
                                            <dt class="text-text-muted"><?php esc_html_e('Geldig tot', 'stridence'); ?></dt>
                                            <dd class="font-medium"><?php echo esc_html(stride_format_date($quote['valid_until'])); ?></dd>
                                        </div>
                                    <?php endif; ?>
                                </dl>

                                <!-- Line Items -->
                                <?php if (!empty($quote['items'])) : ?>
                                    <div class="divide-y divide-border rounded-lg border border-border">
                                        <?php foreach ($quote['items'] as $item) : ?>
                                            <div class="p-3 flex items-center justify-between gap-4">
                                                <div class="flex-1 min-w-0">
                                                    <p class="font-medium text-sm truncate">
                                                        <?php echo esc_html($item['title'] ?? ''); ?>
                                                    </p>
                                                    <?php if (($item['quantity'] ?? 1) > 1) : ?>
                                                        <p class="text-xs text-text-muted">
                                                            <?php echo esc_html($item['quantity']); ?> ×
                                                            <?php echo esc_html(stride_format_money($item['unit_price'] ?? 0)); ?>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                                <span class="text-sm font-medium">
                                                    <?php echo esc_html(stride_format_money($item['line_total'] ?? $item['unit_price'] ?? 0)); ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Actions -->
                                <div class="flex flex-wrap gap-3 pt-2">
                                    <a href="<?php echo esc_url(add_query_arg(['action' => 'stride_quote_pdf', 'quote_id' => $quote['id'], 'nonce' => wp_create_nonce('stride_quote_pdf')], admin_url('admin-ajax.php'))); ?>"
                                       class="btn-primary text-sm"
                                       target="_blank">
                                        <?php echo stridence_icon('download', 'w-4 h-4 mr-1'); ?>
                                        <?php esc_html_e('Download PDF', 'stridence'); ?>
                                    </a>
                                    <?php if ($quote['status'] === QuoteStatus::Draft) : ?>
                                        <span class="btn-ghost text-sm text-amber-600">
                                            <?php echo stridence_icon('clock', 'w-4 h-4 mr-1'); ?>
                                            <?php esc_html_e('In behandeling', 'stridence'); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <?php
            get_template_part('partials/empty-state', null, [
                'icon'    => 'file-text',
                'title'   => __('Geen offertes', 'stridence'),
                'message' => __('Je hebt nog geen offertes. Offertes worden automatisch aangemaakt wanneer je je inschrijft voor een opleiding.', 'stridence'),
                'action'  => __('Bekijk opleidingen', 'stridence'),
                'url'     => get_post_type_archive_link('sfwd-courses'),
            ]);
            ?>
        <?php endif; ?>
    </section>

    <!-- Cancelled Quotes -->
    <?php if (!empty($cancelled)) : ?>
        <section x-data="{ open: false }">
            <button type="button"
                    class="w-full flex items-center justify-between gap-4 mb-4"
                    @click="open = !open">
                <h2 class="font-heading text-xl font-bold text-text-muted">
                    <?php
                    printf(
                        /* translators: %d: number of cancelled quotes */
                        esc_html__('Geannuleerd (%d)', 'stridence'),
                        count($cancelled)
                    );
                    ?>
                </h2>
                <span class="text-text-muted transition-transform duration-200"
                      :class="{ 'rotate-180': open }">
                    <?php echo stridence_icon('chevron-down', 'w-5 h-5'); ?>
                </span>
            </button>

            <div x-show="open" x-collapse>
                <div class="card divide-y divide-border">
                    <?php foreach ($cancelled as $quote) : ?>
                        <div class="p-4 text-text-muted">
                            <div class="flex items-center justify-between gap-4">
                                <div class="flex-1 min-w-0">
                                    <span class="font-mono text-sm">
                                        <?php echo esc_html($quote['quote_number']); ?>
                                    </span>
                                    <h3 class="font-medium truncate">
                                        <?php echo esc_html($quote['title']); ?>
                                    </h3>
                                    <p class="text-sm">
                                        <?php
                                        if ($quote['created_at']) {
                                            echo esc_html(stride_format_date($quote['created_at']));
                                        }
                                        ?>
                                    </p>
                                </div>
                                <span class="text-sm line-through">
                                    <?php echo esc_html($quote['total']->format()); ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>
</div>
