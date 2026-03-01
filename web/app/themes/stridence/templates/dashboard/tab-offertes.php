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
        'billing'      => [
            'organisation' => $quote['billing_organisation'] ?? $quote['billing']['organisation'] ?? '',
            'email'        => $quote['billing_email'] ?? $quote['billing']['email'] ?? '',
            'address'      => $quote['billing_address'] ?? $quote['billing']['address'] ?? '',
            'postal_code'  => $quote['billing_postal_code'] ?? $quote['billing']['postal_code'] ?? '',
            'city'         => $quote['billing_city'] ?? $quote['billing']['city'] ?? '',
            'vat_number'   => $quote['billing_vat_number'] ?? $quote['billing']['vat_number'] ?? '',
        ],
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
                                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
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
                                        <div class="sm:col-span-2">
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

                                <!-- Billing Info (editable for draft quotes) -->
                                <?php if ($quote['status'] === QuoteStatus::Draft || $quote['status'] === QuoteStatus::Sent) : ?>
                                    <div class="border-t border-border pt-4 mt-4"
                                         x-data="inlineEditSection({
                                             action: 'stride_update_quote',
                                             params: { quote_id: <?php echo (int)$quote['id']; ?> },
                                             fields: <?php echo esc_attr(json_encode([
                                                 'organisation' => $quote['billing']['organisation'] ?? '',
                                                 'email'        => $quote['billing']['email'] ?? '',
                                                 'address'      => $quote['billing']['address'] ?? '',
                                                 'postal_code'  => $quote['billing']['postal_code'] ?? '',
                                                 'city'         => $quote['billing']['city'] ?? '',
                                                 'vat_number'   => $quote['billing']['vat_number'] ?? '',
                                             ])); ?>
                                         })">
                                        <div class="flex items-center justify-between mb-3">
                                            <h4 class="text-sm font-semibold text-text">
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
                                        <dl x-show="!editing" class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
                                            <div>
                                                <dt class="text-text-muted"><?php esc_html_e('Organisatie', 'stridence'); ?></dt>
                                                <dd x-text="fields.organisation || '-'"></dd>
                                            </div>
                                            <div>
                                                <dt class="text-text-muted"><?php esc_html_e('E-mail', 'stridence'); ?></dt>
                                                <dd x-text="fields.email || '-'"></dd>
                                            </div>
                                            <div class="sm:col-span-2">
                                                <dt class="text-text-muted"><?php esc_html_e('Adres', 'stridence'); ?></dt>
                                                <dd>
                                                    <span x-text="fields.address"></span><template x-if="fields.address && fields.postal_code">, </template>
                                                    <span x-text="fields.postal_code"></span>
                                                    <span x-text="fields.city"></span>
                                                </dd>
                                            </div>
                                            <div x-show="fields.vat_number">
                                                <dt class="text-text-muted"><?php esc_html_e('BTW-nummer', 'stridence'); ?></dt>
                                                <dd x-text="fields.vat_number"></dd>
                                            </div>
                                        </dl>

                                        <!-- Edit mode -->
                                        <div x-show="editing" x-transition class="space-y-3">
                                            <div>
                                                <label class="input-label"><?php esc_html_e('Organisatie', 'stridence'); ?></label>
                                                <input type="text" x-model="fields.organisation" class="input-text">
                                            </div>
                                            <div>
                                                <label class="input-label"><?php esc_html_e('E-mail factuur', 'stridence'); ?></label>
                                                <input type="email" x-model="fields.email" class="input-text">
                                            </div>
                                            <div>
                                                <label class="input-label"><?php esc_html_e('Adres', 'stridence'); ?></label>
                                                <input type="text" x-model="fields.address" class="input-text">
                                            </div>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
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

                                            <!-- Error -->
                                            <div x-show="error" class="p-2 bg-error/10 rounded text-sm text-error" x-text="error"></div>

                                            <!-- Actions -->
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

                                <!-- Apply Voucher (only for Draft/Sent without existing voucher) -->
                                <?php if (($quote['status'] === QuoteStatus::Draft || $quote['status'] === QuoteStatus::Sent) && empty($quote['voucher_code'])) : ?>
                                    <div class="border-t border-border pt-4 mt-4"
                                         x-data="{ code: '', loading: false, error: '', applied: false }">
                                        <h4 class="text-sm font-semibold text-text mb-3">
                                            <?php esc_html_e('Kortingscode toevoegen', 'stridence'); ?>
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
                                                                code: code
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

                                <!-- Actions -->
                                <div class="flex flex-wrap gap-3 pt-4 border-t border-border mt-4">
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
