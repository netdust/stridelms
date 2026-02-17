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
            'label' => 'Edities',
            'labels' => [
                'name' => 'Edities',
                'singular_name' => 'Editie',
                'add_new' => 'Nieuwe editie',
                'add_new_item' => 'Nieuwe editie toevoegen',
                'edit_item' => 'Editie bewerken',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-calendar-alt',
            'supports' => ['title'],
            'fields' => self::getFields(),
            'field_groups' => self::getFieldGroups(),
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

    private static function getFieldGroups(): array
    {
        return [
            'edition_details' => [
                'title' => 'Editie Details',
                'fields' => ['course_id', 'start_date', 'end_date', 'capacity', 'venue', 'status'],
            ],
            'edition_pricing' => [
                'title' => 'Prijzen',
                'fields' => ['price', 'price_non_member'],
            ],
            'edition_info' => [
                'title' => 'Extra Info',
                'fields' => ['speakers'],
            ],
            'edition_sessions' => [
                'title' => 'Sessie Selectie',
                'fields' => ['selection_deadline', 'session_slots'],
            ],
        ];
    }
}
