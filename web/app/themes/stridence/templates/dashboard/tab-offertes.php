<?php
/**
 * Dashboard Tab: Offertes (Quotes)
 *
 * Shows user's quotes as flat row cards (Helder Tij design).
 *
 * @param array $args {
 *     @type WP_User $user Current user object
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Domain\QuoteStatus;
use Stride\Modules\User\UserDashboardService;

$user    = $args['user'] ?? wp_get_current_user();
$user_id = $user->ID;

$dashboardService = ntdst_get(UserDashboardService::class);
$quoteData  = $dashboardService->getQuoteData($user_id);
$active     = $quoteData['active'];
$cancelled  = $quoteData['cancelled'];

/**
 * Get badge CSS classes for quote status.
 * Sent → badge-few (amber) per design spec; others per semantic mapping.
 */
function stridence_quote_status_classes(QuoteStatus $status): string
{
    return match ($status) {
        QuoteStatus::Draft    => 'bg-surface-alt text-text-muted',
        QuoteStatus::Sent     => 'badge-few',
        QuoteStatus::Exported => 'bg-badge-online-bg text-badge-online-text',
        QuoteStatus::Cancelled => 'bg-surface-container text-text-muted',
    };
}
?>

<div class="space-y-4">
    <!-- Active Quotes -->
    <?php if (!empty($active)) : ?>
        <?php foreach ($active as $quote) :
            $isSent = $quote['status'] === QuoteStatus::Sent;
            $pdfUrl = esc_url(add_query_arg([
                'action'   => 'stride_quote_pdf',
                'quote_id' => $quote['id'],
                'nonce'    => wp_create_nonce('stride_quote_pdf'),
            ], admin_url('admin-ajax.php')));
            ?>
            <div class="bg-white rounded-[16px] shadow-card p-[22px] px-6 flex flex-wrap items-center gap-4">
                <!-- LEFT: number + badge + meta -->
                <div class="flex-1 min-w-[240px]">
                    <!-- Title row: quote number + status badge -->
                    <div class="flex flex-wrap items-center gap-2 mb-[6px]">
                        <span class="text-[16px] font-bold text-text leading-snug">
                            <?php
                            printf(
                                /* translators: %s: quote number */
                                esc_html__('Offerte #%s', 'stridence'),
                                esc_html($quote['quote_number']),
                            );
                            ?>
                        </span>
                        <span class="inline-flex items-center px-[9px] py-[3px] rounded-full text-[11px] font-bold <?php echo esc_attr(stridence_quote_status_classes($quote['status'])); ?>">
                            <?php echo esc_html($quote['status_label']); ?>
                        </span>
                    </div>

                    <!-- Meta line: 13px muted + strong total -->
                    <div class="text-[13px] text-text-muted leading-snug">
                        <?php echo esc_html($quote['title']); ?>
                        <?php if ($quote['created_at']) : ?>
                            · <?php echo esc_html(stride_format_date($quote['created_at'])); ?>
                        <?php endif; ?>
                        · <strong class="text-text font-semibold"><?php echo esc_html($quote['total']->format()); ?></strong>
                    </div>
                </div>

                <!-- RIGHT: action buttons -->
                <div class="flex flex-wrap gap-2 shrink-0" x-data="{ reminding: false }">
                    <?php if ($isSent) : ?>
                        <!-- Herinner werkgever: loading guard + toast -->
                        <button type="button"
                                class="btn-ghost btn-sm"
                                :disabled="reminding"
                                @click="async () => {
                                    reminding = true;
                                    try {
                                        await ntdstAPI.call('stride_remind_employer', { quote_id: <?php echo (int) $quote['id']; ?> });
                                        $dispatch('toast', { message: <?php echo json_encode(__('Werkgever herinnerd!', 'stridence')); ?>, type: 'success' });
                                    } catch (e) {
                                        $dispatch('toast', { message: e.message || <?php echo json_encode(__('Versturen mislukt', 'stridence')); ?>, type: 'error' });
                                    } finally {
                                        reminding = false;
                                    }
                                }">
                            <span x-show="!reminding"><?php esc_html_e('Herinner werkgever', 'stridence'); ?></span>
                            <span x-show="reminding" class="flex items-center gap-1.5">
                                <span class="spinner w-3.5 h-3.5"></span>
                                <?php esc_html_e('Verzenden...', 'stridence'); ?>
                            </span>
                        </button>
                    <?php endif; ?>

                    <!-- Bekijk offerte -->
                    <a href="<?php echo $pdfUrl; ?>"
                       class="btn-primary btn-sm"
                       target="_blank"
                       rel="noopener">
                        <?php esc_html_e('Bekijk offerte', 'stridence'); ?>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>

    <?php else : ?>
        <?php
        stridence_template_part('partials/empty-state', null, [
            'icon'    => 'file-text',
            'title'   => __('Geen offertes', 'stridence'),
            'message' => __('Je hebt nog geen offertes. Offertes worden automatisch aangemaakt wanneer je je inschrijft voor een opleiding.', 'stridence'),
            'action'  => __('Bekijk opleidingen', 'stridence'),
            'url'     => get_post_type_archive_link('sfwd-courses'),
        ]);
        ?>
    <?php endif; ?>

    <!-- Cancelled Quotes -->
    <?php if (!empty($cancelled)) : ?>
        <section x-data="{ open: false }">
            <button type="button"
                    class="w-full flex items-center justify-between gap-4 pt-2"
                    @click="open = !open">
                <h3 class="text-[13px] font-semibold text-text-muted">
                    <?php
                    printf(
                        /* translators: %d: number of cancelled quotes */
                        esc_html__('Geannuleerd (%d)', 'stridence'),
                        count($cancelled),
                    );
                    ?>
                </h3>
                <span class="text-text-muted transition-transform duration-200"
                      :class="{ 'rotate-180': open }">
                    <?php echo stridence_icon('chevron-down', 'w-4 h-4'); ?>
                </span>
            </button>

            <div x-show="open" x-collapse>
                <div class="mt-3 space-y-2">
                    <?php foreach ($cancelled as $quote) : ?>
                        <div class="flex items-center gap-4 bg-surface-alt rounded-[12px] px-4 py-3 text-text-muted">
                            <div class="flex-1 min-w-0">
                                <span class="text-[13px] font-semibold truncate block">
                                    <?php
                                    printf(
                                        /* translators: %s: quote number */
                                        esc_html__('Offerte #%s', 'stridence'),
                                        esc_html($quote['quote_number']),
                                    );
                                    ?>
                                </span>
                                <span class="text-[12px] truncate block">
                                    <?php echo esc_html($quote['title']); ?>
                                    <?php if ($quote['created_at']) : ?>
                                        · <?php echo esc_html(stride_format_date($quote['created_at'])); ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <span class="text-[13px] line-through shrink-0">
                                <?php echo esc_html($quote['total']->format()); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>
</div>
