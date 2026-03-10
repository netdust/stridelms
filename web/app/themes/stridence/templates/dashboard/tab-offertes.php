<?php
/**
 * Dashboard Tab: Offertes (Quotes)
 *
 * Shows user's quotes as clickable cards with slide-over detail panel.
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

// Get quote data from dashboard service
$dashboardService = ntdst_get(UserDashboardService::class);
$quoteData  = $dashboardService->getQuoteData($user_id);
$active     = $quoteData['active'];
$cancelled  = $quoteData['cancelled'];

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

<div class="space-y-8" x-data="slidePanel()">
    <!-- Active Quotes -->
    <section>
        <h2 class="font-heading text-xl font-bold text-text mb-4">
            <?php esc_html_e('Mijn offertes', 'stridence'); ?>
        </h2>

        <?php if (!empty($active)) : ?>
            <div class="space-y-3">
                <?php foreach ($active as $quote) : ?>
                    <button type="button"
                            class="dash-card w-full p-4 flex items-center justify-between gap-4 text-left hover:shadow-elevated transition-shadow duration-normal"
                            @click="openPanel(<?php echo (int)$quote['id']; ?>)">
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
                        <span class="shrink-0 text-text-muted">
                            <?php echo stridence_icon('chevron-right', 'w-5 h-5'); ?>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- Slide Panel -->
            <div x-show="isOpen" x-cloak class="slide-panel-backdrop"
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0"
                     x-transition:enter-end="opacity-100"
                     x-transition:leave="transition ease-in duration-200"
                     x-transition:leave-start="opacity-100"
                     x-transition:leave-end="opacity-0"
                     @click.self="close()"
                     @keydown.escape.window="close()">
                    <div class="dash-panel"
                         x-show="isOpen"
                         x-transition:enter="transition ease-out duration-300"
                         x-transition:enter-start="translate-x-full"
                         x-transition:enter-end="translate-x-0"
                         x-transition:leave="transition ease-in duration-200"
                         x-transition:leave-start="translate-x-0"
                         x-transition:leave-end="translate-x-full"
                         @click.stop>

                        <?php foreach ($active as $quote) : ?>
                            <template x-if="activeId === <?php echo (int)$quote['id']; ?>">
                                <div class="flex flex-col h-full">
                                    <!-- Header -->
                                    <div class="slide-panel-header">
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2 mb-1">
                                                <span class="font-mono text-sm text-text-muted">
                                                    <?php echo esc_html($quote['quote_number']); ?>
                                                </span>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo esc_attr(stridence_quote_status_classes($quote['status'])); ?>">
                                                    <?php echo esc_html($quote['status_label']); ?>
                                                </span>
                                            </div>
                                            <h3><?php echo esc_html($quote['title']); ?></h3>
                                        </div>
                                        <button type="button" @click="close()"
                                                class="shrink-0 p-1.5 -m-1.5 rounded-lg text-text-muted hover:bg-surface-alt hover:text-text transition-colors">
                                            <?php echo stridence_icon('x', 'w-5 h-5'); ?>
                                        </button>
                                    </div>

                                    <!-- Body -->
                                    <div class="slide-panel-body">
                                        <!-- Price Breakdown -->
                                        <div class="space-y-3">
                                            <h4 class="text-sm font-semibold text-text-muted uppercase tracking-wide">
                                                <?php esc_html_e('Prijsoverzicht', 'stridence'); ?>
                                            </h4>
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
                                                        <dd class="font-medium text-green-600">-<?php echo esc_html($quote['discount']->format()); ?></dd>
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
                                                <p class="text-xs text-text-muted">
                                                    <?php esc_html_e('Geldig tot', 'stridence'); ?>
                                                    <?php echo esc_html(stride_format_date($quote['valid_until'])); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Line Items -->
                                        <?php if (!empty($quote['items'])) : ?>
                                            <div class="space-y-3">
                                                <h4 class="text-sm font-semibold text-text-muted uppercase tracking-wide">
                                                    <?php esc_html_e('Regelitems', 'stridence'); ?>
                                                </h4>
                                                <div class="divide-y divide-border rounded-lg border border-border">
                                                    <?php foreach ($quote['items'] as $item) : ?>
                                                        <div class="p-3 flex items-center justify-between gap-4">
                                                            <div class="flex-1 min-w-0">
                                                                <p class="font-medium text-sm truncate">
                                                                    <?php echo esc_html($item['title'] ?? ''); ?>
                                                                </p>
                                                                <?php if (($item['quantity'] ?? 1) > 1) : ?>
                                                                    <p class="text-xs text-text-muted">
                                                                        <?php echo esc_html($item['quantity']); ?> &times;
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
                                            </div>
                                        <?php endif; ?>

                                        <!-- Billing Info -->
                                        <?php if ($quote['status'] === QuoteStatus::Draft || $quote['status'] === QuoteStatus::Sent) : ?>
                                            <div x-data="inlineEditSection({
                                                     action: 'stride_update_quote',
                                                     params: { quote_id: <?php echo (int)$quote['id']; ?> },
                                                     fields: <?php echo esc_attr(json_encode([
                                                         'company' => $quote['billing']['company'] ?? '',
                                                         'email'        => $quote['billing']['email'] ?? '',
                                                         'address'      => $quote['billing']['address'] ?? '',
                                                         'postal_code'  => $quote['billing']['postal_code'] ?? '',
                                                         'city'         => $quote['billing']['city'] ?? '',
                                                         'vat_number'   => $quote['billing']['vat_number'] ?? '',
                                                     ])); ?>
                                                 })">
                                                <div class="flex items-center justify-between mb-3">
                                                    <h4 class="text-sm font-semibold text-text-muted uppercase tracking-wide">
                                                        <?php esc_html_e('Facturatiegegevens', 'stridence'); ?>
                                                    </h4>
                                                    <template x-if="!editing">
                                                        <button type="button" @click="startEdit()"
                                                                class="text-sm text-primary hover:underline">
                                                            <?php echo stridence_icon('edit-2', 'w-3.5 h-3.5 inline mr-1'); ?>
                                                            <?php esc_html_e('Bewerken', 'stridence'); ?>
                                                        </button>
                                                    </template>
                                                </div>

                                                <!-- Display mode -->
                                                <dl x-show="!editing" class="space-y-2 text-sm">
                                                    <div class="flex justify-between">
                                                        <dt class="text-text-muted"><?php esc_html_e('Organisatie', 'stridence'); ?></dt>
                                                        <dd x-text="fields.company || '-'"></dd>
                                                    </div>
                                                    <div class="flex justify-between">
                                                        <dt class="text-text-muted"><?php esc_html_e('E-mail', 'stridence'); ?></dt>
                                                        <dd x-text="fields.email || '-'" class="truncate ml-4"></dd>
                                                    </div>
                                                    <div class="flex justify-between">
                                                        <dt class="text-text-muted"><?php esc_html_e('Adres', 'stridence'); ?></dt>
                                                        <dd class="text-right">
                                                            <span x-text="fields.address"></span><template x-if="fields.address && fields.postal_code">, </template>
                                                            <span x-text="fields.postal_code"></span>
                                                            <span x-text="fields.city"></span>
                                                        </dd>
                                                    </div>
                                                    <div x-show="fields.vat_number" class="flex justify-between">
                                                        <dt class="text-text-muted"><?php esc_html_e('BTW-nummer', 'stridence'); ?></dt>
                                                        <dd x-text="fields.vat_number"></dd>
                                                    </div>
                                                </dl>

                                                <!-- Edit mode -->
                                                <div x-show="editing" x-transition class="space-y-3">
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

                                                    <div class="flex justify-end gap-2 pt-2">
                                                        <button type="button" @click="cancelEdit()" class="btn-secondary btn-sm">
                                                            <?php esc_html_e('Annuleren', 'stridence'); ?>
                                                        </button>
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

                                        <!-- Apply Voucher -->
                                        <?php if (($quote['status'] === QuoteStatus::Draft || $quote['status'] === QuoteStatus::Sent) && empty($quote['voucher_code'])) : ?>
                                            <div x-data="{ code: '', loading: false, error: '', applied: false }">
                                                <h4 class="text-sm font-semibold text-text-muted uppercase tracking-wide mb-3">
                                                    <?php esc_html_e('Kortingscode', 'stridence'); ?>
                                                </h4>
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
                                                                        quote_id: <?php echo (int)$quote['id']; ?>,
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
                                                <p x-show="error" class="text-sm text-error mt-2" x-text="error"></p>
                                                <p x-show="applied" class="text-sm text-green-600 mt-2">
                                                    <?php echo stridence_icon('check-circle', 'w-4 h-4 inline mr-1'); ?>
                                                    <?php esc_html_e('Kortingscode toegepast!', 'stridence'); ?>
                                                </p>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Footer -->
                                    <div class="slide-panel-footer">
                                        <div class="flex flex-wrap gap-3">
                                            <a href="<?php echo esc_url(add_query_arg(['action' => 'stride_quote_pdf', 'quote_id' => $quote['id'], 'nonce' => wp_create_nonce('stride_quote_pdf')], admin_url('admin-ajax.php'))); ?>"
                                               class="btn-primary btn-sm flex-1 sm:flex-none"
                                               target="_blank">
                                                <?php echo stridence_icon('download', 'w-4 h-4 mr-1'); ?>
                                                <?php esc_html_e('Download PDF', 'stridence'); ?>
                                            </a>
                                            <?php if ($quote['status'] !== QuoteStatus::Exported) : ?>
                                                <div x-data="confirmAction()" class="flex-1 sm:flex-none">
                                                    <button type="button"
                                                            x-show="!confirming"
                                                            @click="startConfirm()"
                                                            class="btn-ghost btn-sm text-error hover:bg-error/10 w-full sm:w-auto">
                                                        <?php echo stridence_icon('x-circle', 'w-4 h-4 mr-1'); ?>
                                                        <?php esc_html_e('Annuleren', 'stridence'); ?>
                                                    </button>
                                                    <div x-show="confirming" x-transition class="flex items-center gap-2">
                                                        <span class="text-sm text-text-muted"><?php esc_html_e('Zeker weten?', 'stridence'); ?></span>
                                                        <button type="button" @click="cancel()" class="btn-secondary btn-sm">
                                                            <?php esc_html_e('Nee', 'stridence'); ?>
                                                        </button>
                                                        <button type="button"
                                                                @click="confirm(async () => {
                                                                    await ntdstAPI.call('stride_cancel_quote', { quote_id: <?php echo (int)$quote['id']; ?> });
                                                                    $dispatch('toast', { message: 'Inschrijving geannuleerd', type: 'success' });
                                                                    setTimeout(() => location.reload(), 1000);
                                                                })"
                                                                class="btn-sm bg-error text-white hover:bg-error/90">
                                                            <?php esc_html_e('Ja, annuleren', 'stridence'); ?>
                                                        </button>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        <?php endforeach; ?>

                    </div>
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
                <div class="dash-card divide-y divide-border">
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
