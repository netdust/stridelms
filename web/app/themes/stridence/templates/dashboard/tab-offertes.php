<?php
/**
 * Dashboard Tab: Offertes (Quotes)
 *
 * Shows user's quotes as expandable row cards (Helder Tij design).
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
        <?php foreach ($active as $i => $quote) :
            $isDraftOrSent  = $quote['status'] === QuoteStatus::Draft || $quote['status'] === QuoteStatus::Sent;
            $canEditBilling = $isDraftOrSent;
            $canApplyVoucher = $isDraftOrSent && empty($quote['voucher_code']);
            $canCancel      = $quote['status'] !== QuoteStatus::Exported;
            $hasSecondClick = $canEditBilling || $canApplyVoucher;
            ?>
            <div class="bg-white rounded-[16px] shadow-card" x-data="expandable(<?php echo $i === 0 ? 'true' : 'false'; ?>)">
                <!-- Header -->
                <button type="button"
                        class="w-full p-[22px] px-6 flex items-center gap-4 text-left"
                        @click="toggle()">
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

                    <span class="shrink-0 text-text-muted transition-transform duration-200"
                          :class="{ 'rotate-180': open }">
                        <?php echo stridence_icon('chevron-down', 'w-5 h-5'); ?>
                    </span>
                </button>

                <!-- Expanded body -->
                <div x-show="open" x-collapse class="border-t border-border">
                    <div class="p-4 px-6 space-y-4">
                        <!-- Price Breakdown -->
                        <div>
                            <p class="text-xs font-medium text-text-muted uppercase tracking-wide mb-2">
                                <?php esc_html_e('Prijsoverzicht', 'stridence'); ?>
                            </p>
                            <dl class="space-y-2 text-sm">
                                <div class="flex justify-between">
                                    <dt class="text-text-muted"><?php esc_html_e('Subtotaal', 'stridence'); ?></dt>
                                    <dd class="font-medium"><?php echo esc_html($quote['subtotal']->format()); ?></dd>
                                </div>
                                <?php if (!$quote['discount']->isZero()) : ?>
                                    <div class="flex justify-between">
                                        <dt class="text-text-muted">
                                            <?php esc_html_e('Korting', 'stridence'); ?>
                                            <?php if ($quote['voucher_code']) : ?>
                                                <span class="text-xs">(<?php echo esc_html($quote['voucher_code']); ?>)</span>
                                            <?php endif; ?>
                                        </dt>
                                        <dd class="font-medium text-status-success">-<?php echo esc_html($quote['discount']->format()); ?></dd>
                                    </div>
                                <?php endif; ?>
                                <div class="flex justify-between">
                                    <dt class="text-text-muted"><?php esc_html_e('BTW (21%)', 'stridence'); ?></dt>
                                    <dd class="font-medium"><?php echo esc_html($quote['tax']->format()); ?></dd>
                                </div>
                                <div class="flex justify-between pt-2 border-t border-border">
                                    <dt class="font-semibold"><?php esc_html_e('Totaal', 'stridence'); ?></dt>
                                    <dd class="font-bold text-lg"><?php echo esc_html($quote['total']->format()); ?></dd>
                                </div>
                            </dl>
                            <?php if ($quote['valid_until']) : ?>
                                <span class="text-xs text-text-muted block mt-2">
                                    <?php esc_html_e('Geldig tot', 'stridence'); ?>
                                    <?php echo esc_html(stride_format_date($quote['valid_until'])); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Action row -->
                        <div class="flex flex-wrap gap-3 pt-2" x-data="{ moreOpen: false }">
                            <a href="<?php echo esc_url(add_query_arg(['action' => 'stride_quote_pdf', 'quote_id' => $quote['id'], 'nonce' => wp_create_nonce('stride_quote_pdf')], admin_url('admin-ajax.php'))); ?>"
                               class="btn-primary btn-sm"
                               target="_blank"
                               rel="noopener">
                                <?php esc_html_e('Bekijk offerte', 'stridence'); ?>
                            </a>

                            <?php if ($hasSecondClick) : ?>
                                <button type="button"
                                        @click="moreOpen = !moreOpen"
                                        class="btn-ghost btn-sm">
                                    <span x-show="!moreOpen"><?php esc_html_e('Aanpassen', 'stridence'); ?></span>
                                    <span x-show="moreOpen" x-cloak><?php esc_html_e('Sluiten', 'stridence'); ?></span>
                                </button>
                            <?php endif; ?>

                            <?php if ($canCancel) : ?>
                                <div x-data="confirmAction()">
                                    <button type="button"
                                            x-show="!confirming"
                                            @click="startConfirm()"
                                            class="btn-ghost btn-sm text-error hover:bg-error/10">
                                        <?php esc_html_e('Annuleren', 'stridence'); ?>
                                    </button>
                                    <div x-show="confirming" x-transition x-cloak class="flex items-center gap-2">
                                        <span class="text-sm text-text-muted"><?php esc_html_e('Zeker weten?', 'stridence'); ?></span>
                                        <button type="button" @click="cancel()" class="btn-secondary btn-sm">
                                            <?php esc_html_e('Nee', 'stridence'); ?>
                                        </button>
                                        <button type="button"
                                                @click="confirm(async () => {
                                                    await ntdstAPI.call('stride_cancel_quote', { quote_id: <?php echo (int) $quote['id']; ?> });
                                                    $dispatch('toast', { message: 'Inschrijving geannuleerd', type: 'success' });
                                                    setTimeout(() => location.reload(), 1000);
                                                })"
                                                class="btn-sm bg-error text-text-inverse hover:bg-error/90 px-3 py-1.5 rounded-full">
                                            <?php esc_html_e('Ja, annuleren', 'stridence'); ?>
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Second-click panel: billing edit + voucher -->
                            <?php if ($hasSecondClick) : ?>
                                <div x-show="moreOpen" x-collapse x-cloak class="w-full pt-2 space-y-5">

                                    <?php if ($canEditBilling) : ?>
                                        <div class="bg-surface rounded-[12px] p-4"
                                             x-data="inlineEditSection({
                                                 action: 'stride_update_quote',
                                                 params: { quote_id: <?php echo (int) $quote['id']; ?> },
                                                 fields: <?php echo esc_attr(json_encode([
                                                     'company'     => $quote['billing']['company'] ?? '',
                                                     'email'       => $quote['billing']['email'] ?? '',
                                                     'address'     => $quote['billing']['address'] ?? '',
                                                     'postal_code' => $quote['billing']['postal_code'] ?? '',
                                                     'city'        => $quote['billing']['city'] ?? '',
                                                     'vat_number'  => $quote['billing']['vat_number'] ?? '',
                                                 ])); ?>
                                             })"
                                             x-init="startEdit()">
                                            <p class="text-xs font-medium text-text-muted uppercase tracking-wide mb-3">
                                                <?php esc_html_e('Facturatiegegevens', 'stridence'); ?>
                                            </p>

                                            <div class="space-y-3">
                                                <div>
                                                    <label class="input-label"><?php esc_html_e('Organisatie', 'stridence'); ?></label>
                                                    <input type="text" x-model="fields.company" class="input-text">
                                                </div>
                                                <div>
                                                    <label class="input-label"><?php esc_html_e('E-mail factuur', 'stridence'); ?></label>
                                                    <input type="email" x-model="fields.email" class="input-text">
                                                </div>
                                                <div>
                                                    <label class="input-label"><?php esc_html_e('Adres', 'stridence'); ?></label>
                                                    <input type="text" x-model="fields.address" class="input-text">
                                                </div>
                                                <div class="grid grid-cols-2 gap-3">
                                                    <div>
                                                        <label class="input-label"><?php esc_html_e('Postcode', 'stridence'); ?></label>
                                                        <input type="text" x-model="fields.postal_code" class="input-text">
                                                    </div>
                                                    <div>
                                                        <label class="input-label"><?php esc_html_e('Gemeente', 'stridence'); ?></label>
                                                        <input type="text" x-model="fields.city" class="input-text">
                                                    </div>
                                                </div>
                                                <div>
                                                    <label class="input-label"><?php esc_html_e('BTW-nummer', 'stridence'); ?></label>
                                                    <input type="text" x-model="fields.vat_number" class="input-text" placeholder="BE0123.456.789">
                                                </div>

                                                <div x-show="error" class="p-2 bg-error/10 rounded text-sm text-error" x-text="error"></div>

                                                <div class="flex justify-end pt-2">
                                                    <button type="button" @click="saveEdit()" :disabled="saving" class="btn-primary btn-sm">
                                                        <span x-show="!saving"><?php esc_html_e('Opslaan', 'stridence'); ?></span>
                                                        <span x-show="saving" class="flex items-center gap-1">
                                                            <span class="spinner w-3 h-3"></span>
                                                            <?php esc_html_e('Opslaan...', 'stridence'); ?>
                                                        </span>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($canApplyVoucher) : ?>
                                        <div class="bg-surface rounded-[12px] p-4"
                                             x-data="{ code: '', loading: false, error: '', applied: false }">
                                            <p class="text-xs font-medium text-text-muted uppercase tracking-wide mb-2">
                                                <?php esc_html_e('Kortingscode', 'stridence'); ?>
                                            </p>
                                            <div class="flex gap-2" x-show="!applied">
                                                <input type="text" x-model="code"
                                                       class="input-text flex-1"
                                                       placeholder="<?php esc_attr_e('Voer code in', 'stridence'); ?>"
                                                       :disabled="loading">
                                                <button type="button"
                                                        @click="async () => {
                                                            if (!code) return;
                                                            loading = true;
                                                            error = '';
                                                            try {
                                                                await ntdstAPI.call('stride_apply_quote_voucher', {
                                                                    quote_id: <?php echo (int) $quote['id']; ?>,
                                                                    voucher_code: code
                                                                });
                                                                applied = true;
                                                                $dispatch('toast', { message: 'Kortingscode toegepast!', type: 'success' });
                                                                setTimeout(() => location.reload(), 1000);
                                                            } catch (e) {
                                                                error = e.message || 'Code ongeldig';
                                                            } finally {
                                                                loading = false;
                                                            }
                                                        }"
                                                        :disabled="!code || loading"
                                                        class="btn-secondary whitespace-nowrap">
                                                    <span x-show="!loading"><?php esc_html_e('Toepassen', 'stridence'); ?></span>
                                                    <span x-show="loading" class="spinner w-4 h-4"></span>
                                                </button>
                                            </div>
                                            <span x-show="error" class="text-sm text-error mt-2 block" x-text="error"></span>
                                            <span x-show="applied" class="text-sm text-status-success mt-2 block">
                                                <?php echo stridence_icon('check-circle', 'w-4 h-4 inline mr-1'); ?>
                                                <?php esc_html_e('Kortingscode toegepast!', 'stridence'); ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
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
