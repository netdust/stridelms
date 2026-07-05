<?php

declare(strict_types=1);

namespace Stride\Modules\Trajectory;

use Stride\Admin\StrideSettingsService;

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
            'meta_prefix' => '_ntdst_',
            'label' => 'Trajecten',
            'labels' => [
                'name' => 'Trajecten',
                'singular_name' => 'Traject',
                'add_new' => 'Nieuw traject',
                'add_new_item' => 'Nieuw traject toevoegen',
                'edit_item' => 'Traject bewerken',
            ],
            'public' => true,
            'publicly_queryable' => true,
            'has_archive' => true,
            'show_ui' => true,
            'show_in_menu' => 'stride-dashboard',
            'menu_icon' => 'dashicons-networking',
            'supports' => ['title', 'editor', 'excerpt', 'thumbnail'],
            'auto_metabox' => false,
            'rewrite' => [
                'slug' => StrideSettingsService::getTrajectorySlug(),
                'with_front' => false,
            ],
            'fields' => self::getFields(),
            'field_groups' => self::getFieldGroups(),
        ]);
    }

    public static function getFields(): array
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
                'label' => 'Prijs (intern — spiegelt Prijs)',
            ],
            'price_non_member' => [
                'type' => 'float',
                'label' => 'Prijs (€)',
            ],
            'description' => [
                'type' => 'text',
                'label' => 'Beschrijving',
            ],
            'tagline' => [
                'type' => 'text',
                'label' => 'Tagline',
                'description' => 'Korte pakkende zin over het traject',
            ],
            'target_audience' => [
                'type' => 'textarea',
                'label' => 'Doelgroep',
                'description' => 'Voor wie is dit traject bedoeld?',
            ],
            'duration' => [
                'type' => 'text',
                'label' => 'Duur',
                'description' => 'Bijv. "6 maanden" of "1 jaar"',
            ],
            // Descriptive fields reused verbatim from the edition set
            // (EditionCPT::getFields) so the trajectory page can render the
            // same "Praktisch" wells + sidebar info. Rendered render-when-present.
            'required_experience' => [
                'type' => 'text',
                'label' => 'Voorkennis',
            ],
            'included' => [
                'type' => 'textarea',
                'label' => 'Inbegrepen',
            ],
            'price_includes' => [
                'type' => 'text',
                'label' => 'Prijs inclusief',
                'description' => 'Korte regel onder de prijs in de sidebar',
            ],
            'cancellation_policy' => [
                'type' => 'textarea',
                'label' => 'Annuleringsvoorwaarden',
            ],
            'cta_benefits' => [
                'type' => 'textarea',
                'label' => 'Voordelen',
                'description' => 'Voordelenlijst in de sidebar, één item per regel',
            ],
            'enrollment_info' => [
                'type' => 'textarea',
                'label' => 'Inschrijvingsinfo',
                'description' => 'Getoond onder de inschrijf-knop (bv. deadline + bevestigingsinfo)',
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
            'trajectory_messages' => [
                'type' => 'json',
                'label' => 'Berichten',
                'description' => 'Admin-authored messages/announcements shown on the trajectory dashboard',
            ],
            'requires_approval' => [
                'type' => 'boolean',
                'label' => 'Goedkeuring vereist',
                'description' => 'Enrollment requires admin approval',
            ],
            'enrollment_form' => [
                'type' => 'text',
                'label' => 'Inschrijfformulier',
                'description' => 'Welk formulier wordt gebruikt: default, minimal, direct',
            ],
            'requires_questionnaire' => [
                'type' => 'boolean',
                'label' => 'Vragenlijst vereist',
                'description' => 'Enrollment requires questionnaire completion',
            ],
            'requires_documents' => [
                'type' => 'boolean',
                'label' => 'Documenten vereist',
                'description' => 'Enrollment requires document upload',
            ],
            'documents_instruction' => [
                'type' => 'textarea',
                'label' => 'Instructie documenten',
                'description' => 'Toelichting die de deelnemer ziet bij de taak "Documenten uploaden". Leeg = standaardtekst.',
            ],
            'post_requires_evaluation' => [
                'type' => 'boolean',
                'label' => 'Evaluatie vereist na afloop',
                'description' => 'Post-trajectory evaluation questionnaire required',
            ],
            'post_requires_documents' => [
                'type' => 'boolean',
                'label' => 'Documenten vereist na afloop',
                'description' => 'Post-trajectory document upload required',
            ],
            'post_documents_instruction' => [
                'type' => 'textarea',
                'label' => 'Instructie documenten na afloop',
                'description' => 'Toelichting bij de upload-taak na afloop. Leeg = standaardtekst.',
            ],
            'post_requires_approval' => [
                'type' => 'boolean',
                'label' => 'Aftekenen door beheerder',
                'description' => 'Post-trajectory admin sign-off required',
            ],
        ];
    }

    private static function getFieldGroups(): array
    {
        return [
            'trajectory_details' => [
                'title' => 'Traject Details',
                'fields' => ['mode', 'status', 'capacity', 'requires_approval'],
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
