<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

/**
 * Edition CPT Registration.
 *
 * Scheduled course offerings with dates, capacity, pricing.
 */
final class EditionCPT
{
    public const POST_TYPE = 'vad_edition';

    public static function register(): void
    {
        ntdst_data()->register(self::POST_TYPE, [
            'meta_prefix' => '_ntdst_',
            'label' => 'Edities',
            'labels' => [
                'name' => 'Edities',
                'singular_name' => 'Editie',
                'add_new' => 'Nieuwe editie',
                'add_new_item' => 'Nieuwe editie toevoegen',
                'edit_item' => 'Editie bewerken',
            ],
            'public' => false,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => 'stride-dashboard',
            'menu_icon' => 'dashicons-calendar-alt',
            'supports' => ['title'],
            'fields' => self::getFields(),
            // Disable auto-generated metabox - custom UI handled by EditionAdminController
            'auto_metabox' => false,
        ]);
    }

    private static function getFields(): array
    {
        return [
            'course_id' => [
                'type' => 'int',
                'label' => 'Cursus',
                'required' => true,
            ],
            'start_date' => [
                'type' => 'text',
                'label' => 'Startdatum',
                'required' => true,
            ],
            'end_date' => [
                'type' => 'text',
                'label' => 'Einddatum',
            ],
            'capacity' => [
                'type' => 'int',
                'label' => 'Capaciteit',
                'required' => true,
            ],
            'price' => [
                'type' => 'float',
                'label' => 'Prijs (leden)',
            ],
            'price_non_member' => [
                'type' => 'float',
                'label' => 'Prijs (niet-leden)',
            ],
            'venue' => [
                'type' => 'text',
                'label' => 'Locatie',
            ],
            'status' => [
                'type' => 'text',
                'label' => 'Status',
            ],
            'speakers' => [
                'type' => 'text',
                'label' => 'Sprekers',
            ],
            'selection_deadline' => [
                'type' => 'text',
                'label' => 'Selectie deadline',
                'description' => 'Deadline for session selection (YYYY-MM-DD)',
            ],
            'session_slots' => [
                'type' => 'json',
                'label' => 'Sessie slots',
                'description' => 'JSON array of slot configurations',
            ],
        ];
    }

}
