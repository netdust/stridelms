<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing\Admin;

use Stride\Domain\DiscountType;
use Stride\Domain\VoucherStatus;
use Stride\Infrastructure\AbstractService;
use Stride\Modules\Invoicing\VoucherCPT;
use Stride\Modules\Invoicing\VoucherRepository;
use Stride\Modules\Invoicing\VoucherService;
use Stride\Modules\Invoicing\VoucherCodeGenerator;
use WP_Post;

/**
 * Voucher Admin Controller.
 *
 * Orchestrates admin interface for vouchers:
 * - Registers metaboxes
 * - Enqueues admin assets
 * - Handles save operations
 */
final class VoucherAdminController extends AbstractService
{
    public const NONCE_SAVE = 'stride_save_voucher';
    public const NONCE_FIELD = 'stride_voucher_nonce';
    private const NONCE_AJAX = 'stride_voucher_admin';

    public function __construct(
        private readonly VoucherService $voucherService,
        private readonly VoucherRepository $repository,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Voucher Admin Controller',
            'description' => 'Admin interface for voucher management',
            'priority' => 100, // Late priority, after services
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'voucher-admin';
    }

    protected function init(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('add_meta_boxes', [$this, 'registerMetaboxes']);
        add_action('save_post_' . VoucherCPT::POST_TYPE, [$this, 'handleSave'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_stride_generate_voucher_code', [$this, 'ajaxGenerateCode']);
    }

    public function registerMetaboxes(): void
    {
        // Remove default editor
        remove_post_type_support(VoucherCPT::POST_TYPE, 'editor');

        // Main voucher details
        add_meta_box(
            'stride_voucher_overview',
            __('Voucher', 'stride'),
            [$this, 'renderOverviewMetabox'],
            VoucherCPT::POST_TYPE,
            'normal',
            'high'
        );

        // Redemptions metabox (only for existing vouchers)
        add_meta_box(
            'stride_voucher_redemptions',
            __('Verzilveringen', 'stride'),
            [$this, 'renderRedemptionsMetabox'],
            VoucherCPT::POST_TYPE,
            'normal',
            'default'
        );

        // Status & actions sidebar
        add_meta_box(
            'stride_voucher_actions',
            __('Status & Acties', 'stride'),
            [$this, 'renderActionsMetabox'],
            VoucherCPT::POST_TYPE,
            'side',
            'high'
        );
    }

    public function enqueueAssets(string $hook): void
    {
        global $post_type, $post;

        if ($post_type !== VoucherCPT::POST_TYPE) {
            return;
        }

        // Flatpickr for date picker
        wp_enqueue_style(
            'flatpickr',
            'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css',
            [],
            '4.6.13'
        );
        wp_enqueue_script(
            'flatpickr',
            'https://cdn.jsdelivr.net/npm/flatpickr',
            [],
            '4.6.13',
            true
        );
        wp_enqueue_script(
            'flatpickr-nl',
            'https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/nl.js',
            ['flatpickr'],
            '4.6.13',
            true
        );

        // Voucher admin styles
        $cssFile = get_stylesheet_directory() . '/assets/css/admin/voucher-admin.css';
        if (file_exists($cssFile)) {
            wp_enqueue_style(
                'stride-voucher-admin',
                get_stylesheet_directory_uri() . '/assets/css/admin/voucher-admin.css',
                [],
                filemtime($cssFile)
            );
        }

        // Voucher admin scripts
        $jsFile = get_stylesheet_directory() . '/assets/js/admin/voucher-admin.js';
        if (file_exists($jsFile)) {
            wp_enqueue_script(
                'stride-voucher-admin',
                get_stylesheet_directory_uri() . '/assets/js/admin/voucher-admin.js',
                ['jquery', 'flatpickr'],
                filemtime($jsFile),
                true
            );

            wp_localize_script('stride-voucher-admin', 'strideVoucherAdmin', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce(self::NONCE_AJAX),
                'voucherId' => $post ? $post->ID : 0,
                'i18n' => [
                    'generateCode' => __('Code genereren', 'stride'),
                    'generating' => __('Bezig...', 'stride'),
                    'error' => __('Er ging iets mis. Probeer het opnieuw.', 'stride'),
                ],
            ]);
        }
    }

    public function renderOverviewMetabox(WP_Post $post): void
    {
        $metabox = new VoucherOverviewMetabox($this->voucherService, $this->repository);
        $metabox->render($post);
    }

    public function renderActionsMetabox(WP_Post $post): void
    {
        $metabox = new VoucherActionsMetabox($this->voucherService);
        $metabox->render($post);
    }

    public function renderRedemptionsMetabox(WP_Post $post): void
    {
        // For new vouchers, show placeholder
        if ($post->post_status === 'auto-draft') {
            echo '<p class="description">' . esc_html__('Sla de voucher eerst op om verzilveringen te zien.', 'stride') . '</p>';
            return;
        }

        $voucher = $this->voucherService->getVoucher($post->ID);
        if (!$voucher) {
            echo '<p class="description">' . esc_html__('Voucher niet gevonden.', 'stride') . '</p>';
            return;
        }

        $redemptions = $voucher['redemptions'] ?? [];
        ?>
        <div class="stride-voucher-redemptions">
            <?php if (empty($redemptions)): ?>
                <p class="description"><?php esc_html_e('Deze voucher is nog niet verzilverd.', 'stride'); ?></p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Gebruiker', 'stride'); ?></th>
                            <th><?php esc_html_e('Offerte', 'stride'); ?></th>
                            <th><?php esc_html_e('Datum', 'stride'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($redemptions as $redemption): ?>
                            <?php
                            $user = get_userdata($redemption['user_id'] ?? 0);
                            $quoteId = $redemption['quote_id'] ?? 0;
                            $date = $redemption['redeemed_at'] ?? '';
                            ?>
                            <tr>
                                <td>
                                    <?php if ($user): ?>
                                        <a href="<?php echo esc_url(get_edit_user_link($user->ID)); ?>">
                                            <?php echo esc_html($user->display_name ?: $user->user_email); ?>
                                        </a>
                                    <?php else: ?>
                                        <?php esc_html_e('Onbekend', 'stride'); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($quoteId): ?>
                                        <a href="<?php echo esc_url(get_edit_post_link($quoteId)); ?>">
                                            <?php echo esc_html(get_the_title($quoteId) ?: "#{$quoteId}"); ?>
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo esc_html($date ? date_i18n('d M Y H:i', strtotime($date)) : '-'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handleSave(int $postId, WP_Post $post): void
    {
        // Verify nonce
        if (!isset($_POST[self::NONCE_FIELD]) ||
            !wp_verify_nonce($_POST[self::NONCE_FIELD], self::NONCE_SAVE)) {
            return;
        }

        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check permissions
        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        $fields = $_POST['ntdst_fields'] ?? [];
        $updateData = [];

        // Process code field
        if (isset($fields['code'])) {
            $code = strtoupper(trim(sanitize_text_field($fields['code'])));
            if (!empty($code)) {
                $updateData['code'] = $code;

                // Update post title to match code
                wp_update_post([
                    'ID' => $postId,
                    'post_title' => $code,
                ]);
            }
        }

        // Process discount type
        if (isset($fields['discount_type'])) {
            $type = sanitize_text_field($fields['discount_type']);
            if (DiscountType::tryFrom($type)) {
                $updateData['discount_type'] = $type;
            }
        }

        // Process discount value (convert to cents for fixed amounts)
        if (isset($fields['discount_value'])) {
            $discountType = $updateData['discount_type'] ?? ($fields['discount_type'] ?? 'full');
            $value = (float) $fields['discount_value'];

            if ($discountType === 'fixed') {
                // Convert euros to cents
                $updateData['discount_value'] = (int) round($value * 100);
            } else {
                // Percentage: keep as integer 0-100
                $updateData['discount_value'] = (int) $value;
            }
        }

        // Process usage limit
        if (isset($fields['usage_limit'])) {
            $updateData['usage_limit'] = absint($fields['usage_limit']);
        }

        // Process edition restriction
        if (isset($fields['edition_id'])) {
            $updateData['edition_id'] = absint($fields['edition_id']);
        }

        // Process validity dates
        if (isset($fields['valid_from'])) {
            $updateData['valid_from'] = sanitize_text_field($fields['valid_from']);
        }
        if (isset($fields['valid_until'])) {
            $updateData['valid_until'] = sanitize_text_field($fields['valid_until']);
        }

        // Handle status change
        if (!empty($_POST['stride_change_status'])) {
            $newStatus = sanitize_text_field($_POST['stride_change_status']);
            if (VoucherStatus::tryFrom($newStatus)) {
                $updateData['status'] = $newStatus;
            }
        }

        // For new vouchers, set defaults
        if ($post->post_status === 'auto-draft' || empty(get_post_meta($postId, 'code', true))) {
            if (empty($updateData['status'])) {
                $updateData['status'] = VoucherStatus::Active->value;
            }
            if (!isset($updateData['used_count'])) {
                $updateData['used_count'] = 0;
            }
            if (!isset($updateData['created_by'])) {
                $updateData['created_by'] = get_current_user_id();
            }
            if (!isset($updateData['redemptions'])) {
                $updateData['redemptions'] = [];
            }
        }

        // Update if we have data
        if (!empty($updateData)) {
            $this->repository->updateMeta($postId, $updateData);
        }
    }

    public function ajaxGenerateCode(): void
    {
        if (!check_ajax_referer(self::NONCE_AJAX, 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token'], 403);
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $code = VoucherCodeGenerator::generate(
            'VAD',
            fn($c) => $this->repository->findByCode($c) !== null
        );

        wp_send_json_success(['code' => $code]);
    }
}
