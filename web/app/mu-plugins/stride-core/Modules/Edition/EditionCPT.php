<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

use Stride\Admin\StrideSettingsService;

/**
 * Edition CPT Registration.
 *
 * Scheduled course offerings with dates, capacity, pricing.
 * Edition title is mandatory - WordPress generates slug from title automatically.
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
            'public' => true,
            'publicly_queryable' => true,
            // No public CPT archive — /klassikaal/ and /online/ are the
            // discovery surfaces. Individual /edities/<slug>/ URLs keep
            // working via the rewrite slug below.
            'has_archive' => false,
            'show_ui' => true,
            'show_in_menu' => 'stride-dashboard',
            'menu_icon' => 'dashicons-calendar-alt',
            'supports' => ['title'],
            'rewrite' => [
                'slug' => StrideSettingsService::getEditionSlug(),
                'with_front' => false,
            ],
            'fields' => self::getFields(),
            // Disable auto-generated metabox - custom UI handled by EditionAdminController
            'auto_metabox' => false,
        ]);
    }

    /**
     * Default copy for the content fields, prefilled in the admin metabox
     * for new/empty editions (NTDST field 'default' is not auto-applied on
     * read, so the metabox passes these as the getField() fallback — they
     * persist on first save). The theme renders saved values only.
     *
     * @return array<string, string>
     */
    public static function getContentDefaults(): array
    {
        return [
            'target_audience' => '',
            'required_experience' => __('Geen voorkennis nodig', 'stride'),
            'included' => __('Lunch, koffie en cursusmateriaal. Je ontvangt achteraf een attest van deelname.', 'stride'),
            'price_includes' => __('incl. lunch en cursusmateriaal', 'stride'),
            'cancellation_policy' => __('Kosteloos tot 14 dagen vóór de eerste sessie. Daarna kan een collega je plaats overnemen.', 'stride'),
            'cta_benefits' => __('Attest van deelname', 'stride') . "\n" . __('Kosteloos annuleren tot 14 dagen vooraf', 'stride'),
            'enrollment_info' => __('Na inschrijving ontvang je een mail met de bevestiging van je deelname.', 'stride'),
        ];
    }

    /**
     * Edition field schema.
     *
     * Public so schema-contract unit tests can assert field keys/types
     * directly (mirrors MailTemplateCPT::getFields()).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getFields(): array
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
                'type' => 'json',
                'label' => 'Sprekers',
                'description' => 'Array of {name, role} entries; legacy values are plain strings',
            ],
            'target_audience' => [
                'type' => 'textarea',
                'label' => 'Doelpubliek',
            ],
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
                'description' => 'Short line under the sidebar price',
            ],
            'cancellation_policy' => [
                'type' => 'textarea',
                'label' => 'Annuleringsvoorwaarden',
            ],
            'cta_benefits' => [
                'type' => 'textarea',
                'label' => 'Voordelen',
                'description' => 'Sidebar benefits checklist, one item per line',
            ],
            'enrollment_info' => [
                'type' => 'textarea',
                'label' => 'Inschrijvingsinfo',
                'description' => 'Shown under the enrollment CTA (e.g. deadline + confirmation info)',
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
            'completion_mode' => [
                'type' => 'text',
                'label' => 'Completion Mode',
                'description' => 'automatic or manual',
            ],
            'completion_threshold' => [
                'type' => 'int',
                'label' => 'Completion Threshold',
                'description' => 'Percentage threshold for automatic completion',
            ],
            'notes' => [
                'type' => 'json',
                'label' => 'Notities',
                'description' => 'Array of edition notes',
            ],
            'requires_approval' => [
                'type' => 'boolean',
                'label' => 'Goedkeuring vereist',
                'description' => 'Enrollment requires admin approval',
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
                'label' => __('Instructie documenten', 'stride'),
                'description' => __('Toelichting die de deelnemer ziet bij de taak "Documenten uploaden". Leeg = standaardtekst.', 'stride'),
            ],
            'requires_session_selection' => [
                'type' => 'boolean',
                'label' => 'Sessiekeuze vereist',
                'description' => 'Enrollment requires session selection',
            ],
            'selection_open' => [
                'type' => 'boolean',
                'label' => 'Sessiekeuze open',
                'description' => 'Whether session selection window is open',
            ],
            'post_requires_evaluation' => [
                'type' => 'boolean',
                'label' => 'Evaluatie vereist na afloop',
                'description' => 'Post-course evaluation questionnaire required',
            ],
            'post_requires_documents' => [
                'type' => 'boolean',
                'label' => 'Documenten vereist na afloop',
                'description' => 'Post-course document upload required',
            ],
            'post_documents_instruction' => [
                'type' => 'textarea',
                'label' => __('Instructie documenten na afloop', 'stride'),
                'description' => __('Toelichting bij de upload-taak na afloop. Leeg = standaardtekst.', 'stride'),
            ],
            'post_requires_approval' => [
                'type' => 'boolean',
                'label' => 'Goedkeuring vereist na afloop',
                'description' => 'Post-course admin approval required',
            ],
            'enrollment_form' => [
                'type' => 'text',
                'label' => 'Inschrijfformulier',
                'description' => 'Which enrollment form to show for this edition',
            ],
            'documents' => [
                'type' => 'json',
                'label' => 'Documenten',
                'description' => 'Attachment IDs of course documents (PDFs, presentations, etc.)',
            ],
        ];
    }
}
