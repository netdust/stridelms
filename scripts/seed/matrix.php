<?php
/**
 * Stride seed feature matrix.
 *
 * SHAPE of a course entry:
 * [
 *   'title' => string (Dutch, demo-quality),
 *   'description' => string,
 *   'type' => 'online'|'in-person'|'hybrid'|'webinar',
 *   'format' => string[] (stride_format slugs),
 *   'themes' => string[] (stride_theme slugs),
 *   'ld_price_type' => 'open'|'closed' (default 'open'),
 *   'covers' => string[],            // dimension tags — seed-verify.php asserts these
 *   'lessons' => [['title','content'], ...],
 *   'editions' => [[
 *     'start_date','end_date'?, 'price','price_non_member','capacity','venue',
 *     'status' => OfferingStatus value string,
 *     'speakers' => [['name','role'], ...],                 // JSON repeater — NEVER a plain string
 *     'content' => ['target_audience','required_experience','included','price_includes',
 *                   'cancellation_policy','cta_benefits','enrollment_info'],  // any subset
 *     'enrollment_form' => 'default'|'minimal'|'direct'|null,
 *     'requires' => ['session_selection','questionnaire','documents','approval'],   // pre
 *     'post_requires' => ['evaluation','documents','approval'],                     // post
 *     'selection_deadline'?, 'selection_open'?, 'session_slots'?,
 *     'fake_registered' => int,      // display-only fake-user fill (900000+ IDs)
 *     'registrations' => [[          // REAL registrations
 *       'user' => 'seed_student1'|'admin'|'seed_filler{N}',  // login, or 'admin'
 *       'status' => RegistrationStatus value, 'path' => 'individual'|'colleague'|'trajectory'|'partner',
 *       'attendance' => 'present'|null,  'quote' => 'draft'|'sent'|'exported'|'cancelled'|null,
 *       'init_tasks' => bool, 'init_post_tasks' => bool,
 *     ], ...],
 *     'covers' => string[],
 *     'sessions' => [['date_offset','start','end','type','title','optional'?,'slot'?,'location'?,'link_lesson'?]],
 *   ], ...],
 * ]
 *
 * Top-level keys returned: 'users', 'courses', 'trajectories', 'vouchers',
 * 'questionnaire_groups', 'company_details'.
 */

use Stride\Domain\OfferingStatus;

return [
    'users' => [
        // EXACT logins/emails preserved — tests/manual + CLAUDE.md reference them.
        ['login' => 'seed_admin', 'email' => 'seed_admin@seed.test', 'role' => 'administrator', 'first' => 'Admin', 'last' => 'Seed'],
        ['login' => 'seed_instructor', 'email' => 'instructor@seed.test', 'role' => 'group_leader', 'first' => 'Jan', 'last' => 'De Trainer'],
        ['login' => 'seed_partner', 'email' => 'seed_partner@seed.test', 'role' => 'partner', 'first' => 'Partner', 'last' => 'Test', 'company_id' => 1],
        ['login' => 'seed_student1', 'email' => 'student1@seed.test', 'role' => 'subscriber', 'first' => 'Pieter', 'last' => 'Janssen', 'company_id' => 1],
        ['login' => 'seed_student2', 'email' => 'student2@seed.test', 'role' => 'subscriber', 'first' => 'Anna', 'last' => 'De Vries', 'company_id' => 1],
        ['login' => 'seed_student3', 'email' => 'student3@seed.test', 'role' => 'subscriber', 'first' => 'Thomas', 'last' => 'Bakker', 'company_id' => 1],
        ['login' => 'seed_student4', 'email' => 'student4@seed.test', 'role' => 'subscriber', 'first' => 'Lotte', 'last' => 'Peeters'],
        ['login' => 'seed_student5', 'email' => 'student5@seed.test', 'role' => 'subscriber', 'first' => 'Wout', 'last' => 'Claes'],
        ['login' => 'seed_coordinator', 'email' => 'seed_coordinator@seed.test', 'role' => 'stride_coordinator', 'first' => 'Coordinator', 'last' => 'Seed'],
        ['login' => 'seed_supervisor', 'email' => 'seed_supervisor@seed.test', 'role' => 'stride_supervisor', 'first' => 'Supervisor', 'last' => 'Seed'],
        ['login' => 'seed_enrolled_user', 'email' => 'seed_enrolled_user@seed.test', 'role' => 'subscriber', 'first' => 'Enrolled', 'last' => 'User'],
        ['login' => 'seed_completed_user', 'email' => 'seed_completed_user@seed.test', 'role' => 'subscriber', 'first' => 'Completed', 'last' => 'User'],
        // Capacity fillers for the real-users full edition (Task 8 references these)
        ['login' => 'seed_filler1', 'email' => 'filler1@seed.test', 'role' => 'subscriber', 'first' => 'Filler', 'last' => 'Een'],
        ['login' => 'seed_filler2', 'email' => 'filler2@seed.test', 'role' => 'subscriber', 'first' => 'Filler', 'last' => 'Twee'],
        ['login' => 'seed_filler3', 'email' => 'filler3@seed.test', 'role' => 'subscriber', 'first' => 'Filler', 'last' => 'Drie'],
        ['login' => 'seed_filler4', 'email' => 'filler4@seed.test', 'role' => 'subscriber', 'first' => 'Filler', 'last' => 'Vier'],
        ['login' => 'seed_filler5', 'email' => 'filler5@seed.test', 'role' => 'subscriber', 'first' => 'Filler', 'last' => 'Vijf'],
        ['login' => 'seed_filler6', 'email' => 'filler6@seed.test', 'role' => 'subscriber', 'first' => 'Filler', 'last' => 'Zes'],
    ],
    'courses' => [
        // === Entry 1: pure-LD open (no edition) — preserved from current seed ===
        [
            'title' => 'E-learning: Basiskennis Jeugdgezondheid',
            'description' => 'Uitgebreide online cursus over de pijlers van jeugdgezondheid: beweging, voeding en mentaal welzijn.',
            'type' => 'online', 'format' => ['online', 'e-learning'], 'themes' => ['beweging', 'welzijn'],
            'ld_price_type' => 'open',
            'covers' => ['model:pure_ld_open'],
            'editions' => [],
            'lessons' => [
                ['title' => 'Module 1: Wat is jeugdgezondheid?', 'content' => '<p>De drie pijlers: beweging, voeding en mentaal welzijn.</p>'],
                ['title' => 'Module 2: Bewegen en motoriek', 'content' => '<p>Beweegnormen en motorische vaardigheden.</p>'],
                ['title' => 'Module 3: Eindtoets', 'content' => '<p>25 meerkeuzevragen, 70% om te slagen.</p>'],
            ],
        ],
        // === Entry 2: closed single-edition, FEATURED — all content fields + speakers repeater ===
        [
            'title' => 'Motiverende Gespreksvoering rond Voeding bij Jongeren',
            'description' => 'Leer hoe je jongeren op een niet-veroordelende manier motiveert om gezondere eetgewoontes te ontwikkelen.',
            'type' => 'in-person', 'format' => ['klassikaal'], 'themes' => ['voeding', 'welzijn'],
            'covers' => ['model:closed_single', 'edition_type:in_person', 'content:edition_fields', 'content:speakers_repeater'],
            'editions' => [
                [
                    'start_date' => date('Y-m-d', strtotime('+6 weeks')),
                    'price' => 295.00, 'price_non_member' => 345.00, 'capacity' => 16,
                    'venue' => 'BWEEG Opleidingscentrum Gent',
                    'status' => OfferingStatus::Open->value,
                    'speakers' => [
                        ['name' => 'Lien De Smedt', 'role' => 'Sportpedagoge'],
                        ['name' => 'Drs. Peter Willems', 'role' => 'Diëtist'],
                    ],
                    'content' => [
                        'target_audience' => "Leerkrachten, CLB-medewerkers en jeugdwerkers die met 12-18-jarigen werken.",
                        'required_experience' => 'Geen voorkennis nodig',
                        'included' => "Lunch, koffie en cursusmateriaal. Je ontvangt achteraf een attest van deelname.",
                        'price_includes' => 'incl. lunch en cursusmateriaal',
                        'cancellation_policy' => 'Kosteloos tot 14 dagen vóór de eerste sessie. Daarna kan een collega je plaats overnemen.',
                        'cta_benefits' => "Attest van deelname\nKosteloos annuleren tot 14 dagen vooraf\nKleine groep (max. 16)",
                        'enrollment_info' => 'Na inschrijving ontvang je een mail met de bevestiging van je deelname.',
                    ],
                    'enrollment_form' => 'default',
                    'covers' => ['status:open', 'sessions:type_in_person', 'form:default'],
                    'registrations' => [
                        ['user' => 'seed_student2', 'status' => 'confirmed', 'path' => 'individual', 'quote' => 'sent'],
                        ['user' => 'seed_student4', 'status' => 'confirmed', 'path' => 'colleague', 'quote' => 'draft'],
                    ],
                    'sessions' => [
                        ['date_offset' => 0, 'start' => '09:30', 'end' => '16:30', 'type' => 'in_person', 'title' => 'Motiverende gespreksvoering rond voeding'],
                    ],
                ],
            ],
            'lessons' => [
                ['title' => 'Theorie: Motiverende gespreksvoering', 'content' => 'Achtergrond en principes.'],
                ['title' => 'Praktijk: Rollenspelen en casussen', 'content' => 'Oefenen met gesprekstechnieken.'],
            ],
        ],
        // Remaining entries added in Task 8 per the coverage table there.
    ],
    'trajectories' => [],            // Task 8
    'vouchers' => [],                // Task 8
    'questionnaire_groups' => [],    // Task 7
    'company_details' => [
        'name' => 'BWEEG vzw', 'address' => 'Sportstraat 42', 'postal_code' => '9000',
        'city' => 'Gent', 'country' => 'België', 'vat' => 'BE0420.798.935',
        'email' => 'info@bweeg.be', 'phone' => '+32 9 234 56 78', 'bank_account' => 'BE68 0682 0553 5765',
    ],
];
