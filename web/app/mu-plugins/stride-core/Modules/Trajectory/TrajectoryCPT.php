<?php

declare(strict_types=1);

namespace Stride\Modules\Trajectory;

/**
 * Trajectory CPT Registration.
 *
 * Multi-course programs with required and elective courses.
 */
final class TrajectoryCPT
{
    public const POST_TYPE = 'vad_trajectory';

    public static function register(): void
    {
        ntdst_data()->register(self::POST_TYPE, [
            'label' => 'Trajecten',
            'labels' => [
                'name' => 'Trajecten',
                'singular_name' => 'Traject',
                'add_new' => 'Nieuw traject',
                'add_new_item' => 'Nieuw traject toevoegen',
                'edit_item' => 'Traject bewerken',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'stride-dashboard',
            'menu_icon' => 'dashicons-networking',
            'supports' => ['title', 'editor'],
            'auto_metabox' => false,
            'fields' => self::getFields(),
            'field_groups' => self::getFieldGroups(),
        ]);
    }

    private static function getFields(): array
    {
        return [
            'mode' => [
                'type' => 'text',
                'label' => 'Modus',
                'description' => 'cohort or self_paced',
            ],
            'status' => [
                'type' => 'text',
                'label' => 'Status',
            ],
            'enrollment_deadline' => [
                'type' => 'text',
                'label' => 'Inschrijfdeadline',
                'description' => 'Last date to enroll (YYYY-MM-DD)',
            ],
            'choice_available_date' => [
                'type' => 'text',
                'label' => 'Keuzemoment start',
                'description' => 'When elective choice opens (YYYY-MM-DD)',
            ],
            'choice_deadline' => [
                'type' => 'text',
                'label' => 'Keuzemoment deadline',
                'description' => 'When elective choice locks (YYYY-MM-DD)',
            ],
            'courses' => [
                'type' => 'json',
                'label' => 'Cursussen',
                'description' => 'JSON array of course configurations',
            ],
            'capacity' => [
                'type' => 'int',
                'label' => 'Capaciteit',
            ],
            'price' => [
                'type' => 'float',
                'label' => 'Prijs (leden)',
            ],
            'price_non_member' => [
                'type' => 'float',
                'label' => 'Prijs (niet-leden)',
            ],
            'description' => [
                'type' => 'text',
                'label' => 'Beschrijving',
            ],
            'deadline_months' => [
                'type' => 'int',
                'label' => 'Deadline maanden',
                'description' => 'Months to complete for self-paced mode',
            ],
            'linked_editions' => [
                'type' => 'json',
                'label' => 'Gekoppelde edities',
                'description' => 'JSON array of course-edition mappings',
            ],
        ];
    }

    private static function getFieldGroups(): array
    {
        return [
            'trajectory_details' => [
                'title' => 'Traject Details',
                'fields' => ['mode', 'status', 'capacity'],
            ],
            'trajectory_deadlines' => [
                'title' => 'Deadlines',
                'fields' => ['enrollment_deadline', 'choice_available_date', 'choice_deadline'],
            ],
            'trajectory_courses' => [
                'title' => 'Cursussen',
                'fields' => ['courses'],
            ],
            'trajectory_pricing' => [
                'title' => 'Prijzen',
                'fields' => ['price', 'price_non_member'],
            ],
        ];
    }
}
