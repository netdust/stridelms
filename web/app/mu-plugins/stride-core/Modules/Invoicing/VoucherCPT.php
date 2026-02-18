<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing;

use Stride\Domain\DiscountType;
use Stride\Domain\VoucherStatus;

/**
 * Voucher CPT Registration.
 *
 * Discount codes for course enrollments.
 */
final class VoucherCPT
{
    public const POST_TYPE = 'vad_voucher';

    public static function register(): void
    {
        ntdst_data()->register(self::POST_TYPE, [
            'meta_prefix' => '_ntdst_',
            'label' => 'Vouchers',
            'labels' => [
                'name' => 'Vouchers',
                'singular_name' => 'Voucher',
                'add_new' => 'Nieuwe voucher',
                'add_new_item' => 'Nieuwe voucher toevoegen',
                'edit_item' => 'Voucher bewerken',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'stride-dashboard',
            'menu_icon' => 'dashicons-tickets-alt',
            'supports' => ['title'],
            'fields' => self::getFields(),
            // Disable auto-generated metabox - custom UI handled by VoucherAdminController
            'auto_metabox' => false,
        ]);
    }

    private static function getFields(): array
    {
        return [
            'code' => [
                'type' => 'text',
                'label' => 'Vouchercode',
                'required' => true,
            ],
            'discount_type' => [
                'type' => 'select',
                'label' => 'Kortingstype',
                'options' => [
                    DiscountType::Full->value => DiscountType::Full->label(),
                    DiscountType::Fixed->value => DiscountType::Fixed->label(),
                    DiscountType::Percentage->value => DiscountType::Percentage->label(),
                ],
                'default' => DiscountType::Full->value,
            ],
            'discount_value' => [
                'type' => 'int',
                'label' => 'Kortingswaarde (centen of percentage)',
                'description' => 'Bedrag in centen voor vast, of 0-100 voor percentage',
            ],
            'usage_limit' => [
                'type' => 'int',
                'label' => 'Gebruikslimiet',
                'description' => '0 = onbeperkt',
                'default' => 1,
            ],
            'used_count' => [
                'type' => 'int',
                'label' => 'Aantal gebruikt',
                'default' => 0,
            ],
            'edition_id' => [
                'type' => 'int',
                'label' => 'Beperkt tot editie',
                'description' => '0 = alle edities',
            ],
            'valid_from' => [
                'type' => 'date',
                'label' => 'Geldig vanaf',
            ],
            'valid_until' => [
                'type' => 'date',
                'label' => 'Geldig tot',
            ],
            'status' => [
                'type' => 'select',
                'label' => 'Status',
                'options' => [
                    VoucherStatus::Active->value => VoucherStatus::Active->label(),
                    VoucherStatus::Exhausted->value => VoucherStatus::Exhausted->label(),
                    VoucherStatus::Expired->value => VoucherStatus::Expired->label(),
                    VoucherStatus::Disabled->value => VoucherStatus::Disabled->label(),
                ],
                'default' => VoucherStatus::Active->value,
            ],
            'created_by' => [
                'type' => 'int',
                'label' => 'Aangemaakt door',
            ],
            'redemptions' => [
                'type' => 'json',
                'label' => 'Verzilveringen',
                'description' => 'Array of {user_id, quote_id, redeemed_at}',
            ],
        ];
    }

    private static function getFieldGroups(): array
    {
        return [
            'general' => [
                'title' => 'Algemeen',
                'fields' => ['code', 'status'],
            ],
            'discount' => [
                'title' => 'Korting',
                'fields' => ['discount_type', 'discount_value'],
            ],
            'usage' => [
                'title' => 'Gebruik',
                'fields' => ['usage_limit', 'used_count'],
            ],
            'validity' => [
                'title' => 'Geldigheid',
                'fields' => ['edition_id', 'valid_from', 'valid_until'],
            ],
        ];
    }
}
