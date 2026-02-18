<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

/**
 * Session CPT Registration.
 *
 * Individual meeting days/times within an edition.
 */
final class SessionCPT
{
    public const POST_TYPE = 'vad_session';

    public static function register(): void
    {
        ntdst_data()->register(self::POST_TYPE, [
            'meta_prefix' => '_ntdst_',
            'label' => 'Sessies',
            'labels' => [
                'name' => 'Sessies',
                'singular_name' => 'Sessie',
                'add_new' => 'Nieuwe sessie',
                'add_new_item' => 'Nieuwe sessie toevoegen',
                'edit_item' => 'Sessie bewerken',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'edit.php?post_type=vad_edition',
            'supports' => ['title'],
            'fields' => self::getFields(),
            'field_groups' => self::getFieldGroups(),
        ]);
    }

    private static function getFields(): array
    {
        return [
            'edition_id' => [
                'type' => 'int',
                'label' => 'Editie',
                'required' => true,
            ],
            'slot' => [
                'type' => 'text',
                'label' => 'Slot',
                'description' => 'Slot identifier (e.g., dag1_vm, keuze_a)',
            ],
            'date' => [
                'type' => 'text',
                'label' => 'Datum',
                'required' => true,
            ],
            'start_time' => [
                'type' => 'text',
                'label' => 'Starttijd',
            ],
            'end_time' => [
                'type' => 'text',
                'label' => 'Eindtijd',
            ],
            'location' => [
                'type' => 'text',
                'label' => 'Locatie',
            ],
            'type' => [
                'type' => 'text',
                'label' => 'Type',
                'description' => 'in_person, webinar, online, assignment',
            ],
            'capacity' => [
                'type' => 'int',
                'label' => 'Capaciteit',
                'description' => 'Leave empty for unlimited',
            ],
            'optional' => [
                'type' => 'boolean',
                'label' => 'Optioneel',
                'description' => 'User can opt out',
            ],
        ];
    }

    private static function getFieldGroups(): array
    {
        return [
            'session_details' => [
                'title' => 'Sessie Details',
                'fields' => ['edition_id', 'slot', 'date', 'start_time', 'end_time', 'location'],
            ],
            'session_config' => [
                'title' => 'Configuratie',
                'fields' => ['type', 'capacity', 'optional'],
            ],
        ];
    }
}
