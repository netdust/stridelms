<?php

namespace stride\services\invoicing;

defined('ABSPATH') || exit;

use stride\services\core\CourseService;
use stride\services\core\SubscriberService;
use stride\services\voucher\VoucherService;
use WP_Error;

/**
 * Quote Service
 *
 * Main orchestrator for quote creation and management.
 * Uses NTDST Data Manager for all database operations.
 *
 * Available hooks:
 * - stride/quote/created (action) - After quote creation
 * - stride/quote/updated (action) - After quote update
 * - stride/quote/sent (action) - After quote marked as sent
 * - stride/quote/exported (action) - After quote marked as exported
 *
 * API Endpoints (via ntdst/api_data):
 * - stride_quote_get - Get quote by ID
 * - stride_quote_update - Update quote billing/order data
 * - stride_quote_list - List user's quotes
 *
 * @package stride\services\invoicing
 */
class QuoteService implements \NTDST_Service_Meta
{
    public const POST_TYPE = 'vad_quote';

    // Simple 3-state workflow
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_EXPORTED = 'exported';

    // Field names (used in DataManager schema)
    public const FIELD_USER_ID = 'user_id';
    public const FIELD_COURSE_ID = 'course_id';
    public const FIELD_STATUS = 'status';
    public const FIELD_QUOTE_NUMBER = 'quote_number';
    public const FIELD_ITEMS = 'items';
    public const FIELD_SUBTOTAL = 'subtotal';
    public const FIELD_TAX = 'tax';
    public const FIELD_TOTAL = 'total';
    public const FIELD_VALID_UNTIL = 'valid_until';
    public const FIELD_BILLING = 'billing';
    public const FIELD_ORDER_NUMBER = 'order_number';
    public const FIELD_VOUCHER_CODE = 'voucher_code';
    public const FIELD_PDF_PATH = 'pdf_path';
    public const FIELD_CREATED_AT = 'created_at';
    public const FIELD_SENT_AT = 'sent_at';
    public const FIELD_EXPORTED_AT = 'exported_at';
    public const FIELD_DISCOUNT = 'discount';

    private ?CourseService $courseService;
    private ?SubscriberService $subscriberService;
    private ?VATValidator $vatValidator;
    private ?VoucherService $voucherService;

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Quote Service',
            'description' => 'Quote CPT, CRUD, and status management via NTDST DataManager',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 10,
        ];
    }

    /**
     * Constructor with optional dependency injection for testing
     */
    public function __construct(
        ?CourseService $courseService = null,
        ?SubscriberService $subscriberService = null,
        ?VATValidator $vatValidator = null,
        ?VoucherService $voucherService = null
    ) {
        $this->courseService = $courseService ?? $this->resolveService(CourseService::class);
        $this->subscriberService = $subscriberService ?? $this->resolveService(SubscriberService::class);
        $this->vatValidator = $vatValidator ?? new VATValidator();
        $this->voucherService = $voucherService ?? $this->resolveService(VoucherService::class);

        // Register CPT via DataManager
        add_action('init', [$this, 'registerModel'], 5);

        // Register API endpoints
        add_action('init', [$this, 'registerApiEndpoints'], 10);

        // Custom admin metaboxes for quote overview
        add_action('add_meta_boxes', [$this, 'registerMetaboxes']);

        // Hook into enrollment completion
        add_action('stride/enrollment/completed', [$this, 'handleEnrollmentCompleted'], 10, 3);
    }

    /**
     * Resolve service from DI container or create new instance
     */
    private function resolveService(string $class): object
    {
        if (function_exists('ntdst_get')) {
            try {
                $service = ntdst_get($class);
                if ($service instanceof $class) {
                    return $service;
                }
            } catch (\Exception $e) {
                // Fall through to create new instance
            }
        }
        return new $class();
    }

    /**
     * Register vad_quote model via NTDST DataManager
     */
    public function registerModel(): void
    {
        if (!function_exists('ntdst_data')) {
            // Fallback to raw CPT registration if DataManager not available
            $this->registerPostTypeFallback();
            return;
        }

        ntdst_data()->register(self::POST_TYPE, [
            'label' => __('Offertes', 'stride'),
            'labels' => [
                'name' => __('Offertes', 'stride'),
                'singular_name' => __('Offerte', 'stride'),
                'menu_name' => __('Offertes', 'stride'),
                'add_new' => __('Nieuwe offerte', 'stride'),
                'add_new_item' => __('Nieuwe offerte toevoegen', 'stride'),
                'edit_item' => __('Offerte bewerken', 'stride'),
                'view_item' => __('Offerte bekijken', 'stride'),
                'all_items' => __('Alle offertes', 'stride'),
                'search_items' => __('Offertes zoeken', 'stride'),
                'not_found' => __('Geen offertes gevonden', 'stride'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'stride-admin',
            'show_in_rest' => false,
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'has_archive' => false,
            'menu_icon' => 'dashicons-media-text',

            // Field schema for ORM - metabox removed via registerMetaboxes()
            'fields' => [
                self::FIELD_USER_ID => ['type' => 'integer', 'required' => true],
                self::FIELD_COURSE_ID => ['type' => 'integer', 'required' => true],
                self::FIELD_STATUS => [
                    'type' => 'select',
                    'options' => [
                        self::STATUS_DRAFT => __('Concept', 'stride'),
                        self::STATUS_SENT => __('Verzonden', 'stride'),
                        self::STATUS_EXPORTED => __('Geëxporteerd', 'stride'),
                    ],
                    'default' => self::STATUS_DRAFT,
                ],
                self::FIELD_QUOTE_NUMBER => ['type' => 'text', 'required' => true],
                self::FIELD_ITEMS => ['type' => 'json'],
                self::FIELD_SUBTOTAL => ['type' => 'float', 'min' => 0],
                self::FIELD_TAX => ['type' => 'float', 'min' => 0],
                self::FIELD_TOTAL => ['type' => 'float', 'min' => 0],
                self::FIELD_VALID_UNTIL => ['type' => 'text'],
                self::FIELD_BILLING => ['type' => 'json'],
                self::FIELD_ORDER_NUMBER => ['type' => 'text'],
                self::FIELD_VOUCHER_CODE => ['type' => 'text'],
                self::FIELD_PDF_PATH => ['type' => 'text'],
                self::FIELD_CREATED_AT => ['type' => 'text'],
                self::FIELD_SENT_AT => ['type' => 'text'],
                self::FIELD_EXPORTED_AT => ['type' => 'text'],
                self::FIELD_DISCOUNT => ['type' => 'float', 'min' => 0],
            ],

            // Disable auto-generated metabox - we use custom invoice-style layout
            'auto_metabox' => false,
        ]);
    }

    /**
     * Fallback CPT registration if DataManager not available
     */
    private function registerPostTypeFallback(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Offertes', 'stride'),
                'singular_name' => __('Offerte', 'stride'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'stride-admin',
            'supports' => ['title'],
        ]);
    }

    /**
     * Register API endpoints via NTDST API system
     */
    public function registerApiEndpoints(): void
    {
        // Get single quote
        add_filter('ntdst/api_data/stride_quote_get', [$this, 'apiGetQuote'], 10, 2);

        // Update quote
        add_filter('ntdst/api_data/stride_quote_update', [$this, 'apiUpdateQuote'], 10, 2);

        // List user's quotes
        add_filter('ntdst/api_data/stride_quote_list', [$this, 'apiListQuotes'], 10, 2);
    }

    /**
     * Register custom metaboxes for quote overview
     */
    public function registerMetaboxes(): void
    {
        // Remove default editor
        remove_post_type_support(self::POST_TYPE, 'editor');

        // Main quote overview - invoice style
        add_meta_box(
            'stride_quote_overview',
            __('Offerte', 'stride'),
            [$this, 'renderOverviewMetabox'],
            self::POST_TYPE,
            'normal',
            'high'
        );

        // Status & actions sidebar
        add_meta_box(
            'stride_quote_status',
            __('Status & Acties', 'stride'),
            [$this, 'renderStatusMetabox'],
            self::POST_TYPE,
            'side',
            'high'
        );
    }

    /**
     * Render main quote overview metabox - Invoice style layout
     */
    public function renderOverviewMetabox(\WP_Post $post): void
    {
        $quote = $this->getQuote($post->ID);

        // Handle new/unsaved quotes
        if (!$quote) {
            $this->renderNewQuoteForm($post);
            return;
        }

        $userId = $quote['user_id'];
        $user = get_userdata($userId);
        $billing = $quote['billing'] ?? [];
        $items = $quote['items'] ?? [];
        $courseId = $quote['course_id'];
        $courseTitle = $courseId ? get_the_title($courseId) : '-';

        $this->renderQuoteStyles();
        ?>

        <div class="stride-quote-document">
            <!-- Header: Quote Number & Company -->
            <div class="stride-quote-header">
                <div class="stride-quote-company">
                    <strong>Stride LMS</strong>
                </div>
                <div class="stride-quote-number">
                    <span class="label"><?php esc_html_e('Offerte', 'stride'); ?></span>
                    <span class="number"><?php echo esc_html($quote['number']); ?></span>
                </div>
            </div>

            <!-- Two Column: Customer | Invoice Details -->
            <div class="stride-quote-parties">
                <div class="stride-quote-customer">
                    <h4><?php esc_html_e('Klant', 'stride'); ?></h4>
                    <div class="stride-quote-address">
                        <?php if ($user): ?>
                            <strong>
                                <a href="<?php echo esc_url(get_edit_user_link($userId)); ?>">
                                    <?php echo esc_html($billing['organisation'] ?: $user->display_name); ?>
                                </a>
                            </strong><br>
                            <?php if (!empty($billing['organisation'])): ?>
                                <?php echo esc_html($user->display_name); ?><br>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (!empty($billing['address'])): ?>
                            <?php echo esc_html($billing['address']); ?><br>
                        <?php endif; ?>
                        <?php if (!empty($billing['postal_code']) || !empty($billing['city'])): ?>
                            <?php echo esc_html(trim(($billing['postal_code'] ?? '') . ' ' . ($billing['city'] ?? ''))); ?><br>
                        <?php endif; ?>
                        <?php echo esc_html($billing['email'] ?? $user->user_email ?? ''); ?>
                    </div>
                    <?php if (!empty($billing['vat_number'])): ?>
                        <div class="stride-quote-vat">
                            <span class="label"><?php esc_html_e('BTW', 'stride'); ?>:</span>
                            <?php echo esc_html($billing['vat_number']); ?>
                            <?php if (!empty($billing['vat_validated'])): ?>
                                <span class="validated" title="<?php esc_attr_e('VIES gevalideerd', 'stride'); ?>">✓</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($billing['gln_number'])): ?>
                        <div class="stride-quote-gln">
                            <span class="label"><?php esc_html_e('GLN', 'stride'); ?>:</span>
                            <?php echo esc_html($billing['gln_number']); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="stride-quote-details">
                    <table>
                        <tr>
                            <th><?php esc_html_e('Datum', 'stride'); ?></th>
                            <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($quote['created_at']))); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Geldig tot', 'stride'); ?></th>
                            <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($quote['valid_until']))); ?></td>
                        </tr>
                        <?php if (!empty($quote['order_number'])): ?>
                            <tr>
                                <th><?php esc_html_e('Bestelnummer', 'stride'); ?></th>
                                <td><?php echo esc_html($quote['order_number']); ?></td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <th><?php esc_html_e('Cursus', 'stride'); ?></th>
                            <td>
                                <?php if ($courseId): ?>
                                    <a href="<?php echo esc_url(get_edit_post_link($courseId)); ?>">
                                        <?php echo esc_html($courseTitle); ?>
                                    </a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if (!empty($quote['voucher_code'])): ?>
                            <tr>
                                <th><?php esc_html_e('Voucher', 'stride'); ?></th>
                                <td><code><?php echo esc_html($quote['voucher_code']); ?></code></td>
                            </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <!-- Line Items Table -->
            <table class="stride-quote-items widefat striped">
                <thead>
                    <tr>
                        <th class="description"><?php esc_html_e('Omschrijving', 'stride'); ?></th>
                        <th class="qty"><?php esc_html_e('Aantal', 'stride'); ?></th>
                        <th class="price"><?php esc_html_e('Prijs', 'stride'); ?></th>
                        <th class="total"><?php esc_html_e('Bedrag', 'stride'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr class="<?php echo ($item['type'] ?? '') === 'discount' ? 'discount-row' : ''; ?>">
                            <td class="description"><?php echo esc_html($item['title'] ?? '-'); ?></td>
                            <td class="qty"><?php echo esc_html($item['quantity'] ?? 1); ?></td>
                            <td class="price"><?php echo $this->formatCurrency((float)($item['unit_price'] ?? 0)); ?></td>
                            <td class="total"><?php echo $this->formatCurrency((float)($item['total'] ?? 0)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="subtotal">
                        <td colspan="3"><?php esc_html_e('Subtotaal', 'stride'); ?></td>
                        <td><?php echo $this->formatCurrency($quote['subtotal']); ?></td>
                    </tr>
                    <?php if ($quote['discount'] > 0): ?>
                        <tr class="discount">
                            <td colspan="3"><?php esc_html_e('Korting', 'stride'); ?></td>
                            <td>- <?php echo $this->formatCurrency($quote['discount']); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr class="tax">
                        <td colspan="3"><?php esc_html_e('BTW 21%', 'stride'); ?></td>
                        <td><?php echo $this->formatCurrency($quote['tax']); ?></td>
                    </tr>
                    <tr class="grand-total">
                        <td colspan="3"><?php esc_html_e('Totaal', 'stride'); ?></td>
                        <td><?php echo $this->formatCurrency($quote['total']); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php
    }

    /**
     * Output quote admin styles
     */
    private function renderQuoteStyles(): void
    {
        ?>
        <style>
            .stride-quote-document {
                background: #fff;
                max-width: 800px;
            }

            .stride-quote-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                padding-bottom: 20px;
                margin-bottom: 20px;
                border-bottom: 3px solid #0073aa;
            }

            .stride-quote-company {
                font-size: 18px;
                color: #23282d;
            }

            .stride-quote-number {
                text-align: right;
            }

            .stride-quote-number .label {
                display: block;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 1px;
                color: #666;
            }

            .stride-quote-number .number {
                font-size: 20px;
                font-weight: 600;
                color: #0073aa;
            }

            .stride-quote-parties {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 40px;
                margin-bottom: 30px;
            }

            .stride-quote-customer h4,
            .stride-quote-details h4 {
                margin: 0 0 10px 0;
                padding: 0 0 8px 0;
                border-bottom: 1px solid #ddd;
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                color: #666;
            }

            .stride-quote-address {
                line-height: 1.6;
                margin-bottom: 10px;
            }

            .stride-quote-address strong a {
                color: #23282d;
                text-decoration: none;
            }

            .stride-quote-address strong a:hover {
                color: #0073aa;
            }

            .stride-quote-vat,
            .stride-quote-gln {
                font-size: 13px;
                color: #666;
            }

            .stride-quote-vat .label,
            .stride-quote-gln .label {
                font-weight: 500;
            }

            .stride-quote-vat .validated {
                color: #46b450;
                font-weight: bold;
            }

            .stride-quote-details table {
                width: 100%;
                border-collapse: collapse;
            }

            .stride-quote-details th {
                text-align: left;
                padding: 6px 10px 6px 0;
                font-weight: normal;
                color: #666;
                width: 40%;
            }

            .stride-quote-details td {
                padding: 6px 0;
            }

            .stride-quote-details code {
                background: #f0f0f1;
                padding: 2px 6px;
                border-radius: 3px;
            }

            /* Line Items Table */
            .stride-quote-items {
                margin-top: 0 !important;
            }

            .stride-quote-items th {
                background: #f6f7f7;
                font-weight: 600;
            }

            .stride-quote-items th,
            .stride-quote-items td {
                padding: 12px;
            }

            .stride-quote-items .description { width: 50%; }
            .stride-quote-items .qty { width: 10%; text-align: center; }
            .stride-quote-items .price,
            .stride-quote-items .total { width: 20%; text-align: right; }

            .stride-quote-items tbody td.qty { text-align: center; }
            .stride-quote-items tbody td.price,
            .stride-quote-items tbody td.total { text-align: right; font-family: monospace; }

            .stride-quote-items .discount-row td { color: #d63638; }

            .stride-quote-items tfoot td {
                text-align: right;
                font-family: monospace;
                padding: 8px 12px;
            }

            .stride-quote-items tfoot tr.subtotal td {
                border-top: 1px solid #ddd;
                padding-top: 15px;
            }

            .stride-quote-items tfoot tr.discount td {
                color: #d63638;
            }

            .stride-quote-items tfoot tr.grand-total td {
                border-top: 2px solid #23282d;
                font-size: 16px;
                font-weight: 600;
                padding-top: 12px;
                padding-bottom: 12px;
            }

            @media (max-width: 782px) {
                .stride-quote-parties {
                    grid-template-columns: 1fr;
                    gap: 20px;
                }
            }
        </style>
        <?php
    }

    /**
     * Render form for new quotes (manual creation)
     */
    private function renderNewQuoteForm(\WP_Post $post): void
    {
        // Security nonce
        wp_nonce_field('stride_create_quote', 'stride_quote_nonce');
        ?>
        <div class="stride-new-quote-form">
            <style>
                .stride-new-quote-form {
                    max-width: 600px;
                }
                .stride-new-quote-info {
                    background: #f0f6fc;
                    border-left: 4px solid #2271b1;
                    padding: 12px 16px;
                    margin-bottom: 20px;
                }
                .stride-new-quote-form .form-field {
                    margin-bottom: 15px;
                }
                .stride-new-quote-form .form-field label {
                    display: block;
                    font-weight: 600;
                    margin-bottom: 5px;
                }
                .stride-new-quote-form .form-field input,
                .stride-new-quote-form .form-field select {
                    width: 100%;
                    max-width: 400px;
                }
                .stride-new-quote-form .form-field .description {
                    font-size: 12px;
                    color: #646970;
                    margin-top: 5px;
                }
            </style>

            <div class="stride-new-quote-info">
                <p><strong><?php esc_html_e('Nieuwe offerte', 'stride'); ?></strong></p>
                <p><?php esc_html_e('Offertes worden normaal automatisch aangemaakt bij inschrijving. U kunt hier ook handmatig een offerte aanmaken.', 'stride'); ?></p>
            </div>

            <div class="form-field">
                <label for="quote_user_id"><?php esc_html_e('Gebruiker', 'stride'); ?></label>
                <?php
                wp_dropdown_users([
                    'name' => 'ntdst_fields[user_id]',
                    'id' => 'quote_user_id',
                    'show_option_none' => __('Selecteer gebruiker...', 'stride'),
                    'option_none_value' => '',
                ]);
                ?>
            </div>

            <div class="form-field">
                <label for="quote_course_id"><?php esc_html_e('Cursus', 'stride'); ?></label>
                <select name="ntdst_fields[course_id]" id="quote_course_id">
                    <option value=""><?php esc_html_e('Selecteer cursus...', 'stride'); ?></option>
                    <?php
                    $courses = get_posts([
                        'post_type' => 'sfwd-courses',
                        'posts_per_page' => -1,
                        'orderby' => 'title',
                        'order' => 'ASC',
                        'post_status' => 'publish',
                    ]);
                    foreach ($courses as $course) {
                        echo '<option value="' . esc_attr($course->ID) . '">' . esc_html($course->post_title) . '</option>';
                    }
                    ?>
                </select>
            </div>

            <input type="hidden" name="ntdst_fields[status]" value="<?php echo esc_attr(self::STATUS_DRAFT); ?>">
            <input type="hidden" name="ntdst_fields[created_at]" value="<?php echo esc_attr(current_time('mysql')); ?>">

            <p class="description">
                <?php esc_html_e('Na opslaan worden de offerte details automatisch berekend op basis van de cursus prijs.', 'stride'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Format currency value
     */
    private function formatCurrency(float $amount): string
    {
        return '€ ' . esc_html(number_format($amount, 2, ',', '.'));
    }

    /**
     * Render status sidebar metabox
     */
    public function renderStatusMetabox(\WP_Post $post): void
    {
        $quote = $this->getQuote($post->ID);

        // For new quotes, show simple save prompt
        if (!$quote) {
            ?>
            <div style="text-align: center; padding: 20px 0; color: #646970;">
                <span class="dashicons dashicons-edit" style="font-size: 32px; width: 32px; height: 32px; color: #ddd;"></span>
                <p><?php esc_html_e('Vul de gegevens in en sla op om een offerte aan te maken.', 'stride'); ?></p>
            </div>
            <?php
            return;
        }

        $status = $quote['status'];
        $statusConfig = [
            self::STATUS_DRAFT => [
                'label' => __('Concept', 'stride'),
                'color' => '#dba617',
                'bg' => '#fcf9e8',
                'icon' => 'edit',
            ],
            self::STATUS_SENT => [
                'label' => __('Verzonden', 'stride'),
                'color' => '#0073aa',
                'bg' => '#e5f5fa',
                'icon' => 'email',
            ],
            self::STATUS_EXPORTED => [
                'label' => __('Geëxporteerd', 'stride'),
                'color' => '#46b450',
                'bg' => '#ecf7ed',
                'icon' => 'yes-alt',
            ],
        ];

        $config = $statusConfig[$status] ?? $statusConfig[self::STATUS_DRAFT];
        ?>
        <style>
            .stride-sidebar-status {
                text-align: center;
                padding: 15px;
                margin: -6px -12px 15px -12px;
                background: <?php echo esc_attr($config['bg']); ?>;
                border-bottom: 2px solid <?php echo esc_attr($config['color']); ?>;
            }
            .stride-sidebar-status .dashicons {
                font-size: 24px;
                width: 24px;
                height: 24px;
                color: <?php echo esc_attr($config['color']); ?>;
            }
            .stride-sidebar-status .label {
                display: block;
                font-size: 14px;
                font-weight: 600;
                color: <?php echo esc_attr($config['color']); ?>;
                margin-top: 5px;
            }
            .stride-sidebar-meta {
                margin: 0 0 15px 0;
                padding: 0;
                list-style: none;
            }
            .stride-sidebar-meta li {
                display: flex;
                justify-content: space-between;
                padding: 8px 0;
                border-bottom: 1px solid #f0f0f1;
                font-size: 13px;
            }
            .stride-sidebar-meta li:last-child {
                border-bottom: none;
            }
            .stride-sidebar-meta .meta-label {
                color: #646970;
            }
            .stride-sidebar-meta .meta-value {
                font-weight: 500;
                color: #1d2327;
            }
            .stride-sidebar-total {
                background: #f6f7f7;
                padding: 12px;
                margin: 0 -12px 15px -12px;
                text-align: center;
            }
            .stride-sidebar-total .amount {
                font-size: 24px;
                font-weight: 600;
                color: #1d2327;
            }
            .stride-sidebar-total .currency {
                font-size: 14px;
                color: #646970;
            }
            .stride-sidebar-actions {
                display: flex;
                flex-direction: column;
                gap: 8px;
            }
            .stride-sidebar-actions .button {
                justify-content: center;
            }
        </style>

        <div class="stride-sidebar-status">
            <span class="dashicons dashicons-<?php echo esc_attr($config['icon']); ?>"></span>
            <span class="label"><?php echo esc_html($config['label']); ?></span>
        </div>

        <div class="stride-sidebar-total">
            <span class="currency"><?php esc_html_e('Totaal', 'stride'); ?></span><br>
            <span class="amount"><?php echo $this->formatCurrency($quote['total']); ?></span>
        </div>

        <ul class="stride-sidebar-meta">
            <li>
                <span class="meta-label"><?php esc_html_e('Aangemaakt', 'stride'); ?></span>
                <span class="meta-value"><?php echo esc_html($quote['created_at'] ? date_i18n('d M Y', strtotime($quote['created_at'])) : '-'); ?></span>
            </li>
            <li>
                <span class="meta-label"><?php esc_html_e('Geldig tot', 'stride'); ?></span>
                <span class="meta-value"><?php echo esc_html($quote['valid_until'] ? date_i18n('d M Y', strtotime($quote['valid_until'])) : '-'); ?></span>
            </li>
            <?php if ($quote['sent_at']): ?>
                <li>
                    <span class="meta-label"><?php esc_html_e('Verzonden', 'stride'); ?></span>
                    <span class="meta-value"><?php echo esc_html(date_i18n('d M Y', strtotime($quote['sent_at']))); ?></span>
                </li>
            <?php endif; ?>
            <?php if ($quote['exported_at']): ?>
                <li>
                    <span class="meta-label"><?php esc_html_e('Geëxporteerd', 'stride'); ?></span>
                    <span class="meta-value"><?php echo esc_html(date_i18n('d M Y', strtotime($quote['exported_at']))); ?></span>
                </li>
            <?php endif; ?>
        </ul>

        <div class="stride-sidebar-actions">
            <?php if (!empty($quote['pdf_path'])): ?>
                <a href="<?php echo esc_url($this->getQuoteUrl($post->ID)); ?>" class="button" target="_blank">
                    <span class="dashicons dashicons-pdf" style="margin-top: 4px;"></span>
                    <?php esc_html_e('PDF Bekijken', 'stride'); ?>
                </a>
            <?php endif; ?>

            <?php if ($status === self::STATUS_DRAFT): ?>
                <button type="button" class="button button-primary" onclick="document.getElementById('stride_send_quote').value='1'; document.getElementById('publish').click();">
                    <span class="dashicons dashicons-email" style="margin-top: 4px;"></span>
                    <?php esc_html_e('Verzenden naar klant', 'stride'); ?>
                </button>
                <input type="hidden" name="stride_send_quote" id="stride_send_quote" value="">
            <?php endif; ?>

            <?php if ($status === self::STATUS_SENT): ?>
                <span class="description" style="text-align: center; display: block;">
                    <?php esc_html_e('Wacht op export naar Exact Online', 'stride'); ?>
                </span>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Get public URL for quote PDF
     */
    public function getQuoteUrl(int $quoteId): string
    {
        $quote = $this->getQuote($quoteId);
        if (!$quote || empty($quote['pdf_path'])) {
            return '';
        }

        // Return URL to protected PDF download endpoint
        return add_query_arg([
            'stride_quote' => $quoteId,
            'action' => 'download_pdf',
        ], home_url('/'));
    }

    /**
     * Get the Data Model for quotes
     */
    private function getModel(): ?\NTDST_Data_Model
    {
        if (!function_exists('ntdst_data')) {
            return null;
        }
        return ntdst_data()->get(self::POST_TYPE);
    }

    // ========================================
    // API ENDPOINTS
    // ========================================

    /**
     * API: Get quote by ID
     */
    public function apiGetQuote($data, $params): array|WP_Error
    {
        $quoteId = absint($params['id'] ?? 0);

        if (!$quoteId) {
            return new WP_Error('invalid_input', __('Offerte ID is vereist.', 'stride'), ['status' => 400]);
        }

        $quote = $this->getQuote($quoteId);
        if (!$quote) {
            return new WP_Error('not_found', __('Offerte niet gevonden.', 'stride'), ['status' => 404]);
        }

        // Check permission: owner or admin
        $userId = get_current_user_id();
        if (!current_user_can('manage_options') && $userId !== $quote['user_id']) {
            return new WP_Error('forbidden', __('Geen toegang tot deze offerte.', 'stride'), ['status' => 403]);
        }

        return [
            'success' => true,
            'quote' => $quote,
        ];
    }

    /**
     * API: Update quote
     */
    public function apiUpdateQuote($data, $params): array|WP_Error
    {
        $quoteId = absint($params['id'] ?? 0);

        if (!$quoteId) {
            return new WP_Error('invalid_input', __('Offerte ID is vereist.', 'stride'), ['status' => 400]);
        }

        $quote = $this->getQuote($quoteId);
        if (!$quote) {
            return new WP_Error('not_found', __('Offerte niet gevonden.', 'stride'), ['status' => 404]);
        }

        // Check permission: owner or admin
        $userId = get_current_user_id();
        if (!current_user_can('manage_options') && $userId !== $quote['user_id']) {
            return new WP_Error('forbidden', __('Geen toegang tot deze offerte.', 'stride'), ['status' => 403]);
        }

        // Sanitize update data
        $updateData = [];
        if (isset($params['company'])) {
            $updateData['company'] = sanitize_text_field($params['company']);
        }
        if (isset($params['address'])) {
            $updateData['address'] = sanitize_text_field($params['address']);
        }
        if (isset($params['city'])) {
            $updateData['city'] = sanitize_text_field($params['city']);
        }
        if (isset($params['postal_code'])) {
            $updateData['postal_code'] = sanitize_text_field($params['postal_code']);
        }
        if (isset($params['vat_number'])) {
            $updateData['vat_number'] = sanitize_text_field($params['vat_number']);
        }
        if (isset($params['gln_number'])) {
            $updateData['gln_number'] = sanitize_text_field($params['gln_number']);
        }
        if (isset($params['order_number'])) {
            $updateData['order_number'] = sanitize_text_field($params['order_number']);
        }

        $result = $this->updateQuote($quoteId, $updateData);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'success' => true,
            'message' => __('Offerte bijgewerkt.', 'stride'),
            'quote' => $this->getQuote($quoteId),
        ];
    }

    /**
     * API: List user's quotes
     */
    public function apiListQuotes($data, $params): array|WP_Error
    {
        $userId = get_current_user_id();

        if (!$userId) {
            return new WP_Error('unauthorized', __('Niet ingelogd.', 'stride'), ['status' => 401]);
        }

        $status = isset($params['status']) ? sanitize_text_field($params['status']) : null;
        $quotes = $this->getUserQuotes($userId, $status);

        return [
            'success' => true,
            'quotes' => $quotes,
            'count' => count($quotes),
        ];
    }

    // ========================================
    // CRUD METHODS
    // ========================================

    /**
     * Create a new quote for a user/course
     *
     * @param int $userId WordPress user ID
     * @param int $courseId LearnDash course ID
     * @param array $data Additional quote data
     * @return int|WP_Error Quote post ID or error
     */
    public function createQuote(int $userId, int $courseId, array $data = []): int|WP_Error
    {
        // Check if user already has a quote for this course
        $existing = $this->getUserQuoteForCourse($userId, $courseId);
        if ($existing) {
            return new WP_Error('quote_exists', __('Er bestaat al een offerte voor deze cursus.', 'stride'));
        }

        // Generate quote number
        $quoteNumber = $this->generateQuoteNumber();

        // Validate course via CourseService
        $courseValidation = $this->courseService->validateCourse($courseId);
        if (is_wp_error($courseValidation)) {
            return $courseValidation;
        }

        $courseTitle = $this->courseService->getCourseTitle($courseId);

        // Get billing data from subscriber
        $billing = $this->subscriberService->getBillingData($userId);
        if (is_wp_error($billing)) {
            $billing = [];
        }

        // Merge with any provided billing overrides
        $billing = array_merge($billing, array_filter([
            'organisation' => $data['invoice_org_name'] ?? null,
            'address' => $data['invoice_address'] ?? null,
            'city' => $data['invoice_city'] ?? null,
            'postal_code' => $data['invoice_postal_code'] ?? null,
            'vat_number' => $data['invoice_vat'] ?? null,
            'gln_number' => $data['invoice_gln'] ?? null,
            'email' => $data['invoice_email'] ?? null,
        ], fn($v) => $v !== null && $v !== ''));

        // Validate VAT if provided
        if (!empty($billing['vat_number'])) {
            $vatResult = $this->vatValidator->validate($billing['vat_number']);
            $billing['vat_validated'] = $vatResult['valid'];
            $billing['vat_source'] = $vatResult['source'] ?? 'unknown';
            // Auto-fill company from VIES if available
            if ($vatResult['valid'] && !empty($vatResult['name'])) {
                $billing['organisation'] = $vatResult['name'];
            }
        }

        // Get course price
        $coursePrice = $this->getCoursePrice($courseId);
        $taxRate = $this->getTaxRate();
        $subtotal = $coursePrice;

        // Apply voucher discount if provided
        $discount = 0.0;
        $voucherCode = sanitize_text_field($data['voucher_code'] ?? '');
        if (!empty($voucherCode)) {
            $discount = $this->calculateVoucherDiscount($voucherCode, $courseId, $coursePrice);
        }

        $discountedSubtotal = max(0, $subtotal - $discount);
        $tax = round($discountedSubtotal * ($taxRate / 100), 2);
        $total = $discountedSubtotal + $tax;

        // Build items array
        $items = [
            [
                'id' => $courseId,
                'type' => 'course',
                'title' => $courseTitle,
                'quantity' => 1,
                'unit_price' => $coursePrice,
                'total' => $coursePrice,
            ],
        ];

        // Add discount line if applicable
        if ($discount > 0) {
            $items[] = [
                'id' => 0,
                'type' => 'discount',
                'title' => sprintf(__('Korting (voucher: %s)', 'stride'), $voucherCode),
                'quantity' => 1,
                'unit_price' => -$discount,
                'total' => -$discount,
            ];
        }

        // Calculate valid until date
        $validDays = $this->getConfig('valid_days', 30);
        $validUntil = date('Y-m-d', strtotime("+{$validDays} days"));

        // Use DataManager to create quote
        $model = $this->getModel();
        if (!$model) {
            return new WP_Error('no_model', __('DataManager niet beschikbaar.', 'stride'));
        }

        $result = $model->create([
            'title' => $quoteNumber,
            'status' => 'publish',
            self::FIELD_USER_ID => $userId,
            self::FIELD_COURSE_ID => $courseId,
            self::FIELD_STATUS => self::STATUS_DRAFT,
            self::FIELD_QUOTE_NUMBER => $quoteNumber,
            self::FIELD_ITEMS => $items,
            self::FIELD_SUBTOTAL => $subtotal,
            self::FIELD_DISCOUNT => $discount,
            self::FIELD_TAX => $tax,
            self::FIELD_TOTAL => $total,
            self::FIELD_VALID_UNTIL => $validUntil,
            self::FIELD_BILLING => $billing,
            self::FIELD_ORDER_NUMBER => sanitize_text_field($data['order_number'] ?? ''),
            self::FIELD_VOUCHER_CODE => $voucherCode,
            self::FIELD_CREATED_AT => current_time('mysql'),
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        $quoteId = $result->ID;

        // Create CRM note
        $this->subscriberService->createNote(
            $userId,
            sprintf(__('Offerte %s aangemaakt voor: %s', 'stride'), $quoteNumber, $courseTitle)
        );

        // Fire hook
        do_action('stride/quote/created', $quoteId, $userId, $courseId);

        return $quoteId;
    }

    /**
     * Get quote data as array
     *
     * @param int $quoteId Quote post ID
     * @return array|null Quote data or null if not found
     */
    public function getQuote(int $quoteId): ?array
    {
        $model = $this->getModel();
        if (!$model) {
            return null;
        }

        $post = $model->find($quoteId);
        if (is_wp_error($post) || !$post) {
            return null;
        }

        // Convert WP_Post with meta to our array format
        return [
            'id' => $post->ID,
            'number' => $post->fields[self::FIELD_QUOTE_NUMBER] ?? '',
            'status' => $post->fields[self::FIELD_STATUS] ?? self::STATUS_DRAFT,
            'user_id' => (int) ($post->fields[self::FIELD_USER_ID] ?? 0),
            'course_id' => (int) ($post->fields[self::FIELD_COURSE_ID] ?? 0),
            'items' => $post->fields[self::FIELD_ITEMS] ?? [],
            'subtotal' => (float) ($post->fields[self::FIELD_SUBTOTAL] ?? 0),
            'discount' => (float) ($post->fields[self::FIELD_DISCOUNT] ?? 0),
            'tax' => (float) ($post->fields[self::FIELD_TAX] ?? 0),
            'total' => (float) ($post->fields[self::FIELD_TOTAL] ?? 0),
            'valid_until' => $post->fields[self::FIELD_VALID_UNTIL] ?? '',
            'billing' => $post->fields[self::FIELD_BILLING] ?? [],
            'order_number' => $post->fields[self::FIELD_ORDER_NUMBER] ?? '',
            'voucher_code' => $post->fields[self::FIELD_VOUCHER_CODE] ?? '',
            'pdf_path' => $post->fields[self::FIELD_PDF_PATH] ?? '',
            'created_at' => $post->fields[self::FIELD_CREATED_AT] ?? '',
            'sent_at' => $post->fields[self::FIELD_SENT_AT] ?? '',
            'exported_at' => $post->fields[self::FIELD_EXPORTED_AT] ?? '',
        ];
    }

    /**
     * Update quote data
     *
     * @param int $quoteId Quote post ID
     * @param array $data Data to update
     * @return true|WP_Error
     */
    public function updateQuote(int $quoteId, array $data): true|WP_Error
    {
        $quote = $this->getQuote($quoteId);
        if (!$quote) {
            return new WP_Error('quote_not_found', __('Offerte niet gevonden.', 'stride'));
        }

        // Only allow updates in draft status
        if ($quote['status'] !== self::STATUS_DRAFT) {
            return new WP_Error('quote_locked', __('Offerte kan niet meer worden gewijzigd.', 'stride'));
        }

        $model = $this->getModel();
        if (!$model) {
            return new WP_Error('no_model', __('DataManager niet beschikbaar.', 'stride'));
        }

        $updateData = [];

        // Update billing data
        if (isset($data['billing']) || isset($data['company']) || isset($data['address'])) {
            $billing = $quote['billing'];

            // Map incoming field names to billing array keys
            $billingMap = [
                'company' => 'organisation',
                'organisation' => 'organisation',
                'address' => 'address',
                'city' => 'city',
                'postal_code' => 'postal_code',
                'vat_number' => 'vat_number',
                'gln_number' => 'gln_number',
                'email' => 'email',
            ];

            foreach ($billingMap as $input => $key) {
                if (isset($data[$input]) && $data[$input] !== '') {
                    $billing[$key] = sanitize_text_field($data[$input]);
                }
            }

            // Re-validate VAT if changed
            if (!empty($data['vat_number']) && $data['vat_number'] !== ($quote['billing']['vat_number'] ?? '')) {
                $vatResult = $this->vatValidator->validate($data['vat_number']);
                $billing['vat_validated'] = $vatResult['valid'];
                $billing['vat_source'] = $vatResult['source'] ?? 'unknown';
                if ($vatResult['valid'] && !empty($vatResult['name'])) {
                    $billing['organisation'] = $vatResult['name'];
                }
            }

            $updateData[self::FIELD_BILLING] = $billing;
        }

        // Update order number
        if (isset($data['order_number'])) {
            $updateData[self::FIELD_ORDER_NUMBER] = sanitize_text_field($data['order_number']);
        }

        // Update voucher code
        if (isset($data['voucher_code'])) {
            $updateData[self::FIELD_VOUCHER_CODE] = sanitize_text_field($data['voucher_code']);
        }

        if (empty($updateData)) {
            return true;
        }

        $result = $model->update($quoteId, $updateData);

        if (is_wp_error($result)) {
            return $result;
        }

        // Fire hook
        do_action('stride/quote/updated', $quoteId, $data);

        return true;
    }

    /**
     * Mark quote as sent
     *
     * @param int $quoteId Quote post ID
     * @return true|WP_Error
     */
    public function sendQuote(int $quoteId): true|WP_Error
    {
        $quote = $this->getQuote($quoteId);
        if (!$quote) {
            return new WP_Error('quote_not_found', __('Offerte niet gevonden.', 'stride'));
        }

        if ($quote['status'] !== self::STATUS_DRAFT) {
            return new WP_Error('invalid_status', __('Offerte is al verzonden.', 'stride'));
        }

        $model = $this->getModel();
        if (!$model) {
            return new WP_Error('no_model', __('DataManager niet beschikbaar.', 'stride'));
        }

        $result = $model->update($quoteId, [
            self::FIELD_STATUS => self::STATUS_SENT,
            self::FIELD_SENT_AT => current_time('mysql'),
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        // Fire hook (for email notification, PDF generation, etc.)
        do_action('stride/quote/sent', $quoteId, $quote);

        return true;
    }

    /**
     * Mark quote as exported (to Exact Online)
     *
     * @param int $quoteId Quote post ID
     * @return true|WP_Error
     */
    public function exportQuote(int $quoteId): true|WP_Error
    {
        $quote = $this->getQuote($quoteId);
        if (!$quote) {
            return new WP_Error('quote_not_found', __('Offerte niet gevonden.', 'stride'));
        }

        $model = $this->getModel();
        if (!$model) {
            return new WP_Error('no_model', __('DataManager niet beschikbaar.', 'stride'));
        }

        $result = $model->update($quoteId, [
            self::FIELD_STATUS => self::STATUS_EXPORTED,
            self::FIELD_EXPORTED_AT => current_time('mysql'),
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        // Fire hook
        do_action('stride/quote/exported', $quoteId, $quote);

        return true;
    }

    /**
     * Update PDF path for quote
     *
     * @param int $quoteId Quote post ID
     * @param string $path PDF file path
     * @return true|WP_Error
     */
    public function setPdfPath(int $quoteId, string $path): true|WP_Error
    {
        $model = $this->getModel();
        if (!$model) {
            return new WP_Error('no_model', __('DataManager niet beschikbaar.', 'stride'));
        }

        $result = $model->update($quoteId, [
            self::FIELD_PDF_PATH => $path,
        ]);

        return is_wp_error($result) ? $result : true;
    }

    // ========================================
    // QUERY METHODS
    // ========================================

    /**
     * Get user's quote for a specific course
     *
     * @param int $userId WordPress user ID
     * @param int $courseId LearnDash course ID
     * @return int|null Quote post ID or null
     */
    public function getUserQuoteForCourse(int $userId, int $courseId): ?int
    {
        $model = $this->getModel();
        if (!$model) {
            return null;
        }

        $quote = $model
            ->where(self::FIELD_USER_ID, $userId)
            ->where(self::FIELD_COURSE_ID, $courseId)
            ->limit(1)
            ->first();

        return $quote ? (int) $quote->id : null;
    }

    /**
     * Get all quotes for a user
     *
     * @param int $userId WordPress user ID
     * @param string|null $status Filter by status
     * @return array Array of quote data
     */
    public function getUserQuotes(int $userId, ?string $status = null): array
    {
        $model = $this->getModel();
        if (!$model) {
            return [];
        }

        $query = $model
            ->where(self::FIELD_USER_ID, $userId)
            ->orderBy('date', 'DESC')
            ->withMeta();

        if ($status) {
            $query = $query->where(self::FIELD_STATUS, $status);
        }

        $posts = $query->limit(100)->get();

        return array_map(fn($post) => $this->getQuote((int) $post['id']), $posts);
    }

    /**
     * Get quotes by status
     *
     * @param string $status Quote status
     * @param int $limit Max number of quotes
     * @return array Array of quote data
     */
    public function getQuotesByStatus(string $status, int $limit = 100): array
    {
        $model = $this->getModel();
        if (!$model) {
            return [];
        }

        $posts = $model
            ->where(self::FIELD_STATUS, $status)
            ->orderBy('date', 'ASC')
            ->withMeta()
            ->limit($limit)
            ->get();

        return array_map(fn($post) => $this->getQuote((int) $post['id']), $posts);
    }

    // ========================================
    // ENROLLMENT HANDLER
    // ========================================

    /**
     * Handle enrollment completion - create quote if applicable
     *
     * @param int $userId WordPress user ID
     * @param int $courseId LearnDash course ID
     * @param array $data Enrollment data
     */
    public function handleEnrollmentCompleted(int $userId, int $courseId, array $data): void
    {
        if (!$this->shouldCreateQuote($userId, $courseId)) {
            return;
        }

        $result = $this->createQuote($userId, $courseId, $data);

        if (is_wp_error($result)) {
            // Log error but don't interrupt enrollment
            error_log(sprintf(
                'Stride: Failed to create quote for user %d, course %d: %s',
                $userId,
                $courseId,
                $result->get_error_message()
            ));
        }
    }

    /**
     * Determine if a quote should be created
     *
     * @param int $userId WordPress user ID
     * @param int $courseId LearnDash course ID
     * @return bool
     */
    private function shouldCreateQuote(int $userId, int $courseId): bool
    {
        // Skip if user is admin
        if (user_can($userId, 'manage_options')) {
            return false;
        }

        // Skip for internal email domains
        $emailDomain = $this->subscriberService->getUserEmailDomain($userId);
        if (!$emailDomain) {
            return false;
        }

        $skipDomains = $this->getConfig('skip_domains', ['vad.be', 'druglijn.be']);
        if (in_array($emailDomain, $skipDomains, true)) {
            return false;
        }

        // Skip if user has "geen-factuur" tag in FluentCRM
        $skipTag = $this->getConfig('skip_tag', 'geen-factuur');
        if ($skipTag && $this->subscriberService->hasTag($userId, $skipTag)) {
            return false;
        }

        // Skip if course has no price
        $price = $this->getCoursePrice($courseId);
        if ($price <= 0) {
            return false;
        }

        // Skip if quote already exists
        if ($this->getUserQuoteForCourse($userId, $courseId)) {
            return false;
        }

        return true;
    }

    // ========================================
    // HELPERS
    // ========================================

    /**
     * Generate unique quote number with atomic increment
     *
     * EXCEPTION: Uses raw $wpdb for atomic MySQL transaction.
     * DataManager doesn't support MySQL transactions for counter increment.
     * This prevents race conditions in concurrent quote number generation.
     *
     * @return string Quote number (VADQ-YYYY-NNNNN)
     */
    private function generateQuoteNumber(): string
    {
        global $wpdb;

        $prefix = $this->getConfig('quote_prefix', 'VADQ');
        $year = date('Y');
        $optionName = "stride_quote_last_{$year}";

        try {
            $wpdb->query('START TRANSACTION');

            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                 VALUES (%s, 1, 'no')
                 ON DUPLICATE KEY UPDATE option_value = option_value + 1",
                $optionName
            ));

            $number = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                $optionName
            ));

            $wpdb->query('COMMIT');

            return sprintf('%s-%s-%05d', $prefix, $year, $number);

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('Stride: Quote number generation failed: ' . $e->getMessage());
            // Fallback to timestamp-based number to prevent blocking
            return sprintf('%s-%s-%s', $prefix, $year, strtoupper(substr(md5(microtime(true)), 0, 5)));
        }
    }

    /**
     * Get course price via CourseService
     *
     * @param int $courseId LearnDash course ID
     * @return float Price or 0
     */
    private function getCoursePrice(int $courseId): float
    {
        $price = $this->courseService->getCoursePrice($courseId);
        return $price ?? 0.0;
    }

    /**
     * Get tax rate from config
     *
     * @return float Tax rate percentage
     */
    private function getTaxRate(): float
    {
        return $this->getConfig('tax_rate', 21.0);
    }

    /**
     * Calculate voucher discount amount
     *
     * @param string $voucherCode Voucher code
     * @param int $courseId Course ID
     * @param float $coursePrice Pre-fetched course price (avoids duplicate query)
     * @return float Discount amount (0 if invalid)
     */
    private function calculateVoucherDiscount(string $voucherCode, int $courseId, float $coursePrice): float
    {
        if (!$this->voucherService) {
            return 0.0;
        }

        $voucher = $this->voucherService->validateVoucher($voucherCode, $courseId);
        if (is_wp_error($voucher)) {
            return 0.0;
        }

        // Pass course price to avoid refetching
        return $this->voucherService->calculateDiscount($voucher, $courseId, $coursePrice);
    }

    /**
     * Get config value from theme-config.php
     *
     * @param string $key Config key
     * @param mixed $default Default value
     * @return mixed
     */
    private function getConfig(string $key, mixed $default = null): mixed
    {
        static $config = null;

        if ($config === null) {
            $configPath = get_stylesheet_directory() . '/theme-config.php';
            if (file_exists($configPath)) {
                $config = include $configPath;
            } else {
                $config = [];
            }
        }

        return $config['modules']['invoicing'][$key] ?? $default;
    }
}
