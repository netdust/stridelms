<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing;

/**
 * Quote CPT Registration.
 *
 * Invoices/quotes for course enrollments.
 */
final class QuoteCPT
{
    public const POST_TYPE = 'vad_quote';

    public static function register(): void
    {
        ntdst_data()->register(self::POST_TYPE, [
            'label' => 'Offertes',
            'labels' => [
                'name' => 'Offertes',
                'singular_name' => 'Offerte',
                'add_new' => 'Nieuwe offerte',
                'add_new_item' => 'Nieuwe offerte toevoegen',
                'edit_item' => 'Offerte bewerken',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-media-text',
            'supports' => ['title'],
            'fields' => self::getFields(),
            // Disable auto-generated metabox - custom UI handled by QuoteAdminController
            'auto_metabox' => false,
        ]);
    }

    private static function getFields(): array
    {
        return [
            'user_id' => [
                'type' => 'int',
                'label' => 'Gebruiker ID',
                'required' => true,
            ],
            'registration_id' => [
                'type' => 'int',
                'label' => 'Registratie ID',
            ],
            'edition_id' => [
                'type' => 'int',
                'label' => 'Editie ID',
            ],
            'quote_number' => [
                'type' => 'text',
                'label' => 'Offertenummer',
                'required' => true,
            ],
            'status' => [
                'type' => 'text',
                'label' => 'Status',
                'required' => true,
            ],
            'items' => [
                'type' => 'json',
                'label' => 'Regels',
            ],
            'subtotal' => [
                'type' => 'int',
                'label' => 'Subtotaal (centen)',
            ],
            'discount' => [
                'type' => 'int',
                'label' => 'Korting (centen)',
            ],
            'tax' => [
                'type' => 'int',
                'label' => 'BTW (centen)',
            ],
            'total' => [
                'type' => 'int',
                'label' => 'Totaal (centen)',
            ],
            'billing' => [
                'type' => 'json',
                'label' => 'Facturatiegegevens',
            ],
            'voucher_code' => [
                'type' => 'text',
                'label' => 'Kortingscode',
            ],
            'valid_until' => [
                'type' => 'text',
                'label' => 'Geldig tot',
            ],
            'sent_at' => [
                'type' => 'text',
                'label' => 'Verzonden op',
            ],
            'pdf_path' => [
                'type' => 'text',
                'label' => 'PDF pad',
            ],
            'locked' => [
                'type' => 'bool',
                'label' => 'Vergrendeld',
            ],
            'notes' => [
                'type' => 'json',
                'label' => 'Notities',
            ],
        ];
    }

}
