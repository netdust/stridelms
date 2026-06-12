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
 *   'ld_expire_access' => 'on'|null,      // LearnDash access expiry (Task 8 expiring-access course)
 *   'ld_expire_access_days' => int,       // days until access expires (with ld_expire_access)
 *   'covers' => string[],            // dimension tags — seed-verify.php asserts these
 *   'lessons' => [['title','content'], ...],
 *   'editions' => [[
 *     'start_date','end_date'?, 'price','price_non_member','capacity','venue',
 *     // PRICING (v1 single-price rule): `price` is the MEMBER price, reserved
 *     // for v2 membership (EditionService::getPrice reads it for members).
 *     // v1 has no member feature, so the builder COLLAPSES both fields to
 *     // price_non_member (canonical — same sync EditionAdminController:410
 *     // applies on admin save). The distinct `price` values declared below
 *     // (e.g. 295/345) document the intended v2 member price but are
 *     // currently IGNORED: both fields are stored as price_non_member.
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
use Stride\Domain\TrajectoryMode;

// Byte-identical session arrays shared by sibling editions (hoisted).
$blessureSessions = [
    ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => 'in_person', 'title' => 'Dag 1: Functionele screening'],
    ['date_offset' => 7, 'start' => '09:00', 'end' => '17:00', 'type' => 'in_person', 'title' => 'Dag 2: Blessurepreventie-oefeningen'],
    ['date_offset' => 14, 'start' => '09:00', 'end' => '17:00', 'type' => 'in_person', 'title' => 'Dag 3: Return-to-play protocollen'],
];
$masterclassSessions = [
    ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => 'in_person', 'title' => 'Masterclass Mentale Veerkracht'],
];

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
            'covers' => ['model:closed_single', 'edition_type:in_person'],
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
                    // Task-9 verifier finds the featured edition by scanning edition:{id}
                    // covers — content tags live HERE, not on the course.
                    'covers' => ['status:open', 'sessions:type_in_person', 'form:default', 'content:edition_fields', 'content:speakers_repeater'],
                    'registrations' => [
                        ['user' => 'seed_student2', 'status' => 'confirmed', 'path' => 'individual', 'quote' => 'sent'],
                        ['user' => 'seed_student4', 'status' => 'confirmed', 'path' => 'colleague', 'quote' => 'draft'],
                    ],
                    'sessions' => [
                        ['date_offset' => 0, 'start' => '09:30', 'end' => '16:30', 'type' => 'in_person', 'title' => 'Motiverende gespreksvoering rond voeding'],
                    ],
                ],
                // Past edition, COMPLETED — full post-course flow for student1
                [
                    'start_date' => date('Y-m-d', strtotime('-2 weeks')),
                    'price' => 295.00, 'price_non_member' => 345.00, 'capacity' => 16,
                    'venue' => 'BWEEG Locatie Antwerpen',
                    'status' => OfferingStatus::Completed->value,
                    'speakers' => [
                        ['name' => 'Lien De Smedt', 'role' => 'Sportpedagoge'],
                    ],
                    'enrollment_form' => 'default',
                    'post_requires' => ['evaluation', 'documents'],
                    'covers' => ['status:completed', 'reg:completed', 'quote:exported',
                                 'flow:attendance_marked', 'flow:post_course_ready',
                                 'req:post_evaluation', 'req:post_documents'],
                    'registrations' => [
                        ['user' => 'seed_student1', 'status' => 'completed', 'path' => 'individual',
                         'attendance' => 'present', 'init_post_tasks' => true, 'quote' => 'exported'],
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
        // === Entry/row 2: pure-LD open with 90-day access expiry ===
        [
            'title' => 'E-learning: Voeding en Prestatie bij Jonge Sporters',
            'description' => 'Online cursus over sportvoeding voor jongeren: voedingsbehoeften, hydratatie en maaltijdplanning rond trainingen. 90 dagen toegang.',
            'type' => 'online', 'format' => ['online', 'e-learning'], 'themes' => ['voeding', 'beweging'],
            'ld_price_type' => 'open',
            'ld_expire_access' => 'on', 'ld_expire_access_days' => 90,
            'covers' => ['model:pure_ld_open'],
            'editions' => [],
            'lessons' => [
                ['title' => 'Hoofdstuk 1: Voedingsbehoeften van jonge sporters', 'content' => '<h3>De basis</h3><p>Jongeren in de groei hebben specifieke voedingsbehoeften, zeker bij sport.</p>'],
                ['title' => 'Hoofdstuk 2: Macro- en micronutriënten', 'content' => '<h3>Bouwstenen</h3><p>Koolhydraten, eiwitten, vetten, vitaminen en mineralen voor prestatie.</p>'],
                ['title' => 'Hoofdstuk 3: Hydratatie en sportdranken', 'content' => '<h3>Vochtbalans</h3><p>Wanneer water volstaat en wanneer sportdranken nodig zijn.</p>'],
                ['title' => 'Hoofdstuk 4: Timing en maaltijdplanning', 'content' => '<h3>Rond de Training</h3><p>Wat eet je voor, tijdens en na het sporten?</p>'],
                ['title' => 'Hoofdstuk 5: Veelvoorkomende fouten', 'content' => '<h3>Valkuilen</h3><p>Supplementengebruik, crash-diëten en onvoldoende energie-inname bij jongeren.</p>'],
            ],
        ],
        // === Row 4: multi-edition online closed — in_progress + open cohorts ===
        [
            'title' => 'E-learning: Beweegbeleid Ontwikkelen',
            'description' => 'Begeleide e-learning in cohortvorm: ontwikkel stap voor stap een beweegbeleid voor jouw organisatie.',
            'type' => 'online', 'format' => ['online', 'e-learning'], 'themes' => ['schoolbeleid', 'beweging'],
            'ld_price_type' => 'closed',
            'covers' => ['model:multi_edition'],
            'editions' => [
                [   // Cohort A: started a month ago, running now
                    'start_date' => date('Y-m-d', strtotime('-30 days')),
                    'end_date' => date('Y-m-d', strtotime('+30 days')),
                    'price' => 125.00, 'price_non_member' => 150.00, 'capacity' => 50,
                    'venue' => 'Online',
                    'status' => OfferingStatus::InProgress->value,
                    'speakers' => [['name' => 'Karel Mertens', 'role' => 'Beleidscoach']],
                    'content' => [
                        'target_audience' => 'Beleidsmakers en coördinatoren in scholen en jeugdorganisaties.',
                        'required_experience' => 'Geen voorkennis nodig',
                        'included' => 'Online cursusmateriaal en sjablonen voor je eigen beleidsplan.',
                        'price_includes' => 'incl. sjablonen en begeleiding',
                        'cancellation_policy' => 'Kosteloos annuleren tot de startdatum van het cohort.',
                        'cta_benefits' => "Eigen beleidsplan als eindresultaat\nBegeleiding door een beleidscoach",
                        'enrollment_info' => 'Je krijgt toegang zodra het cohort start.',
                    ],
                    'covers' => ['status:in_progress', 'edition_type:online', 'sessions:type_online'],
                    'registrations' => [
                        ['user' => 'admin', 'status' => 'confirmed', 'path' => 'individual'],
                    ],
                    'sessions' => [
                        ['date_offset' => 0, 'start' => '00:00', 'end' => '23:59', 'type' => 'online', 'title' => 'E-learning: Beweegbeleid (lopende cohort)', 'link_lesson' => true],
                    ],
                ],
                [   // Cohort B: starts in two weeks
                    'start_date' => date('Y-m-d', strtotime('+2 weeks')),
                    'end_date' => date('Y-m-d', strtotime('+10 weeks')),
                    'price' => 125.00, 'price_non_member' => 150.00, 'capacity' => 50,
                    'venue' => 'Online',
                    'status' => OfferingStatus::Open->value,
                    'speakers' => [['name' => 'Karel Mertens', 'role' => 'Beleidscoach']],
                    'covers' => ['status:open', 'edition_type:online'],
                    'sessions' => [
                        ['date_offset' => 0, 'start' => '00:00', 'end' => '23:59', 'type' => 'online', 'title' => 'E-learning: Beweegbeleid (nieuw cohort)', 'link_lesson' => true],
                    ],
                ],
            ],
            'lessons' => [
                ['title' => 'Module 1: Waarom een beweegbeleid?', 'content' => '<p>Het belang van een structureel beweegbeleid voor jongeren en hoe je draagvlak creëert.</p>'],
                ['title' => 'Module 2: Van visie tot actieplan', 'content' => '<p>Stappen om een beweegvisie te formuleren en om te zetten in concrete acties.</p>'],
                ['title' => 'Module 3: Evaluatie en borging', 'content' => '<p>Hoe meet je het effect van je beweegbeleid en hoe borg je het op lange termijn?</p>'],
            ],
        ],
        // === Row 5: 3-day in-person — near-full (fake display) + completed w/ all 3 post reqs ===
        [
            'title' => 'Sportblessures Voorkomen: van Warm-up tot Cool-down',
            'description' => 'Driedaagse evidence-based opleiding over blessurepreventie bij jongeren. Functionele screening, opwarming, cooling-down en return-to-play. Inclusief werkboek.',
            'type' => 'in-person', 'format' => ['klassikaal'], 'themes' => ['sportblessures', 'beweging'],
            'covers' => ['model:multi_edition'],
            'editions' => [
                [   // Near-full: open + 10/12 fake display registrations (replaces invalid 'few_spots' status)
                    'start_date' => date('Y-m-d', strtotime('+2 months')),
                    'price' => 695.00, 'price_non_member' => 795.00, 'capacity' => 12,
                    'fake_registered' => 10,
                    'venue' => 'BWEEG Opleidingscentrum Gent',
                    'status' => OfferingStatus::Open->value,
                    'speakers' => [['name' => 'Dr. Els Peters', 'role' => 'Kinesitherapeut']],
                    'content' => [
                        'target_audience' => 'Sportcoaches, kinesitherapeuten en LO-leerkrachten die met jonge sporters werken.',
                        'required_experience' => 'Basiskennis anatomie is een plus, geen vereiste',
                        'included' => 'Werkboek, lunch op alle lesdagen en een attest van deelname.',
                        'price_includes' => 'incl. werkboek en lunch',
                        'cancellation_policy' => 'Kosteloos tot 14 dagen vóór de eerste sessie.',
                        'cta_benefits' => "Evidence-based methodiek\nDirect toepasbare screeningstools\nWerkboek inbegrepen",
                        'enrollment_info' => 'Beperkt aantal plaatsen — schrijf tijdig in.',
                    ],
                    'enrollment_form' => 'default',
                    'covers' => ['status:few_spots', 'capacity:full_fake_display', 'sessions:type_in_person'],
                    'sessions' => $blessureSessions,
                ],
                [   // Past, completed — ALL THREE post-course requirements
                    'start_date' => date('Y-m-d', strtotime('-6 weeks')),
                    'price' => 695.00, 'price_non_member' => 795.00, 'capacity' => 12,
                    'venue' => 'Sportcentrum De Blaarmeersen, Gent',
                    'status' => OfferingStatus::Completed->value,
                    'speakers' => [['name' => 'Prof. dr. Jan Janssen', 'role' => 'Sportwetenschapper']],
                    'post_requires' => ['evaluation', 'documents', 'approval'],
                    'covers' => ['status:completed', 'req:post_evaluation', 'req:post_documents', 'req:post_approval',
                                 'flow:attendance_marked', 'flow:post_course_ready'],
                    'registrations' => [
                        ['user' => 'seed_student1', 'status' => 'confirmed', 'path' => 'individual',
                         'attendance' => 'present', 'init_post_tasks' => true],
                    ],
                    'sessions' => $blessureSessions,
                ],
            ],
            'lessons' => [
                ['title' => 'Dag 1: Functionele screening', 'content' => 'Leer screeningsmethoden voor het identificeren van blessurerisico bij jongeren.'],
                ['title' => 'Dag 2: Preventie-oefeningen', 'content' => 'Evidence-based opwarmings- en preventie-oefeningen voor verschillende sporten.'],
                ['title' => 'Dag 3: Return-to-play', 'content' => 'Protocollen voor veilige terugkeer naar sport na een blessure.'],
            ],
        ],
        // === Row 6: Masterclass — FULL with 8 real users + waitlist behind it ===
        [
            'title' => 'Masterclass Mentale Veerkracht bij Jonge Sporters',
            'description' => 'Exclusieve masterclass voor ervaren sportcoaches. Herken signalen van prestatiedruk en leer technieken om mentale weerbaarheid te versterken. Maximum 8 deelnemers.',
            'type' => 'in-person', 'format' => ['klassikaal'], 'themes' => ['welzijn'],
            'covers' => ['model:multi_edition'],
            'editions' => [
                [   // FULL: capacity 8, 8 REAL confirmed registrations + 1 waitlist behind it
                    'start_date' => date('Y-m-d', strtotime('+3 weeks')),
                    'price' => 450.00, 'price_non_member' => 525.00, 'capacity' => 8,
                    'venue' => 'BWEEG Opleidingscentrum Gent',
                    'status' => OfferingStatus::Full->value,
                    'speakers' => [['name' => 'Dr. Paul Verhaeghe', 'role' => 'Sportpsycholoog']],
                    'content' => [
                        'target_audience' => 'Ervaren sportcoaches die jonge competitiesporters begeleiden.',
                        'required_experience' => 'Minstens 3 jaar coachingservaring',
                        'included' => 'Intensieve begeleiding in een kleine groep, lunch en naslagwerk.',
                        'price_includes' => 'incl. lunch en naslagwerk',
                        'cancellation_policy' => 'Kosteloos tot 14 dagen vooraf; daarna kan een collega je plaats overnemen.',
                        'cta_benefits' => "Maximum 8 deelnemers\nPersoonlijke feedback van een sportpsycholoog",
                        'enrollment_info' => 'Deze editie is volzet — schrijf je in op de wachtlijst.',
                    ],
                    'covers' => ['status:full', 'capacity:full_real_users', 'capacity:waitlist_behind_full', 'reg:waitlist'],
                    'registrations' => [
                        ['user' => 'seed_filler1', 'status' => 'confirmed', 'path' => 'individual'],
                        ['user' => 'seed_filler2', 'status' => 'confirmed', 'path' => 'individual'],
                        ['user' => 'seed_filler3', 'status' => 'confirmed', 'path' => 'individual'],
                        ['user' => 'seed_filler4', 'status' => 'confirmed', 'path' => 'individual'],
                        ['user' => 'seed_filler5', 'status' => 'confirmed', 'path' => 'individual'],
                        ['user' => 'seed_filler6', 'status' => 'confirmed', 'path' => 'individual'],
                        ['user' => 'seed_student4', 'status' => 'confirmed', 'path' => 'individual'],
                        ['user' => 'seed_student5', 'status' => 'confirmed', 'path' => 'individual'],
                        ['user' => 'seed_student1', 'status' => 'waitlist', 'path' => 'individual'],
                    ],
                    'sessions' => $masterclassSessions,
                ],
                [   // Future edition, open
                    'start_date' => date('Y-m-d', strtotime('+3 months')),
                    'price' => 450.00, 'price_non_member' => 525.00, 'capacity' => 8,
                    'venue' => 'BWEEG Opleidingscentrum Gent',
                    'status' => OfferingStatus::Open->value,
                    'speakers' => [['name' => 'Dr. Paul Verhaeghe', 'role' => 'Sportpsycholoog']],
                    'covers' => ['status:open'],
                    'sessions' => $masterclassSessions,
                ],
            ],
            'lessons' => [
                ['title' => 'Mentale veerkracht module', 'content' => 'Theorie en praktijk van mentale weerbaarheid bij jonge sporters.'],
            ],
        ],
        // === Row 7: session SLOTS (kies 1 uit 3) + selection deadline + pending reg w/ tasks ===
        [
            'title' => 'Gezonde Tussendoortjes in de Jeugdwerking',
            'description' => 'Complete tweedaagse cursus over gezonde voeding in de jeugdwerking. Na de basiscursus kies je uit verdiepingsmodules voor specifieke doelgroepen.',
            'type' => 'in-person', 'format' => ['klassikaal'], 'themes' => ['voeding', 'jeugdwerk'],
            'covers' => ['model:closed_single'],
            'editions' => [
                [
                    'start_date' => date('Y-m-d', strtotime('+5 weeks')),
                    'price' => 395.00, 'price_non_member' => 450.00, 'capacity' => 20,
                    'venue' => 'BWEEG Opleidingscentrum Gent',
                    'status' => OfferingStatus::Open->value,
                    'speakers' => [['name' => 'Team BWEEG Voeding', 'role' => 'Diëtisten en jeugdwerkers']],
                    'content' => [
                        'target_audience' => 'Jeugdwerkers en begeleiders die zelf tussendoortjes voorzien.',
                        'required_experience' => 'Geen voorkennis nodig',
                        'included' => 'Receptenbundel, proeverij en cursusmateriaal.',
                        'price_includes' => 'incl. receptenbundel en proeverij',
                        'cancellation_policy' => 'Kosteloos tot 14 dagen vóór de eerste sessie.',
                        'cta_benefits' => "Kies je eigen verdiepingsmodule\nReceptenbundel inbegrepen",
                        'enrollment_info' => 'Na inschrijving kies je vóór de deadline je verdiepingsmodule.',
                    ],
                    'enrollment_form' => 'default',
                    'requires' => ['session_selection'],
                    'selection_open' => true,
                    'selection_deadline' => date('Y-m-d', strtotime('+4 weeks')),
                    'session_slots' => [
                        ['slot' => 'Verdieping (kies 1)', 'label' => 'Verdieping (kies 1)', 'required' => true, 'max_selections' => 1],
                    ],
                    'covers' => ['status:open', 'sessions:slots_choose_n', 'sessions:selection_deadline',
                                 'req:pre_session_selection', 'reg:pending', 'form:default'],
                    'registrations' => [
                        ['user' => 'seed_student2', 'status' => 'pending', 'path' => 'individual', 'init_tasks' => true],
                    ],
                    'sessions' => [
                        ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => 'in_person', 'title' => 'Dag 1: Basisprincipes gezonde voeding', 'optional' => false],
                        ['date_offset' => 1, 'start' => '09:00', 'end' => '12:00', 'type' => 'in_person', 'title' => 'Dag 2: Praktijk en recepten', 'optional' => false],
                        ['date_offset' => 1, 'start' => '13:00', 'end' => '16:00', 'type' => 'in_person', 'title' => 'Verdieping A: Sportvoeding', 'optional' => true, 'slot' => 'Verdieping (kies 1)'],
                        ['date_offset' => 1, 'start' => '13:00', 'end' => '16:00', 'type' => 'in_person', 'title' => 'Verdieping B: Budget-vriendelijk koken', 'optional' => true, 'slot' => 'Verdieping (kies 1)'],
                        ['date_offset' => 1, 'start' => '13:00', 'end' => '16:00', 'type' => 'in_person', 'title' => 'Verdieping C: Allergieen en intoleranties', 'optional' => true, 'slot' => 'Verdieping (kies 1)'],
                    ],
                ],
            ],
            'lessons' => [
                ['title' => 'Gezonde voeding basis', 'content' => 'Basisprincipes van gezonde voeding in de jeugdwerking.'],
                ['title' => 'Gezonde voeding praktijk', 'content' => 'Praktische toepassing: recepten en tussendoortjes.'],
            ],
        ],
        // === Row 8: cancelled + postponed + rescheduled editions ===
        [
            'title' => 'Workshop Yoga en Mindfulness voor Jongeren',
            'description' => 'Praktische workshop over yoga- en mindfulness-technieken voor jongeren. Geschikt voor alle begeleiders, geen ervaring vereist.',
            'type' => 'in-person', 'format' => ['klassikaal'], 'themes' => ['welzijn', 'beweging'],
            'covers' => ['model:multi_edition'],
            'editions' => [
                [
                    'start_date' => date('Y-m-d', strtotime('+2 weeks')),
                    'price' => 195.00, 'price_non_member' => 225.00, 'capacity' => 15,
                    'venue' => 'Yogacentrum Gent',
                    'status' => OfferingStatus::Cancelled->value,
                    'speakers' => [['name' => 'Leen Smits', 'role' => 'Yogadocente']],
                    'covers' => ['status:cancelled'],
                    'sessions' => [
                        ['date_offset' => 0, 'start' => '13:00', 'end' => '17:00', 'type' => 'in_person', 'title' => 'Workshop Yoga en Mindfulness (GEANNULEERD)'],
                    ],
                ],
                [
                    'start_date' => date('Y-m-d', strtotime('+4 weeks')),
                    'price' => 195.00, 'price_non_member' => 225.00, 'capacity' => 15,
                    'venue' => 'Yogacentrum Antwerpen',
                    'status' => OfferingStatus::Postponed->value,
                    'speakers' => [['name' => 'Leen Smits', 'role' => 'Yogadocente']],
                    'covers' => ['status:postponed'],
                    'sessions' => [
                        ['date_offset' => 0, 'start' => '13:00', 'end' => '17:00', 'type' => 'in_person', 'title' => 'Workshop Yoga en Mindfulness (UITGESTELD)'],
                    ],
                ],
                [
                    'start_date' => date('Y-m-d', strtotime('+6 weeks')),
                    'price' => 195.00, 'price_non_member' => 225.00, 'capacity' => 15,
                    'venue' => 'Yogacentrum Gent',
                    'status' => OfferingStatus::Open->value,
                    'speakers' => [['name' => 'Leen Smits', 'role' => 'Yogadocente']],
                    'covers' => ['status:open'],
                    'sessions' => [
                        ['date_offset' => 0, 'start' => '13:00', 'end' => '17:00', 'type' => 'in_person', 'title' => 'Workshop Yoga en Mindfulness (nieuwe datum)'],
                    ],
                ],
            ],
            'lessons' => [
                ['title' => 'Yoga en mindfulness workshop', 'content' => 'Praktische introductie in yoga en mindfulness voor jongeren.'],
            ],
        ],
        // === Row 9: free intro — open (direct form) + announcement w/ anonymous interest ===
        [
            'title' => 'Gratis Introductie: Werken bij BWEEG',
            'description' => 'Gratis kennismakingsochtend voor nieuwe medewerkers en stagiairs. Rondleiding en ontmoeting met collega\'s.',
            'type' => 'in-person', 'format' => ['klassikaal'], 'themes' => ['beweging'],
            'covers' => ['model:multi_edition'],
            'editions' => [
                [
                    'start_date' => date('Y-m-d', strtotime('+1 week')),
                    'price' => 0.00, 'price_non_member' => 0.00, 'capacity' => 30,
                    'venue' => 'BWEEG Hoofdkantoor Gent',
                    'status' => OfferingStatus::Open->value,
                    'speakers' => [['name' => 'Diverse BWEEG medewerkers', 'role' => 'Gastsprekers']],
                    'enrollment_form' => 'direct',
                    'covers' => ['status:open', 'form:direct'],
                    'sessions' => [
                        ['date_offset' => 0, 'start' => '09:30', 'end' => '12:30', 'type' => 'in_person', 'title' => 'Introductie en rondleiding BWEEG'],
                    ],
                ],
                [   // Announcement: interest CTA, unlimited capacity, anonymous interest reg
                    'start_date' => date('Y-m-d', strtotime('+10 weeks')),
                    'price' => 0.00, 'price_non_member' => 0.00, 'capacity' => 0,
                    'venue' => 'BWEEG Hoofdkantoor Gent',
                    'status' => OfferingStatus::Announcement->value,
                    'speakers' => [['name' => 'Diverse BWEEG medewerkers', 'role' => 'Gastsprekers']],
                    'covers' => ['status:announcement', 'reg:interest', 'capacity:unlimited'],
                    'registrations' => [
                        ['status' => 'interest', 'path' => 'individual'],   // anonymous: no 'user'
                    ],
                    'sessions' => [
                        ['date_offset' => 0, 'start' => '09:30', 'end' => '12:30', 'type' => 'in_person', 'title' => 'Introductie en rondleiding BWEEG (najaar)'],
                    ],
                ],
            ],
            'lessons' => [
                ['title' => 'Introductie BWEEG', 'content' => 'Kennismaking met BWEEG als organisatie.'],
            ],
        ],
        // === Row 10: full pre-enrollment requirement flow + custom form group (Task 7) ===
        [
            'title' => 'Erkenningstraject Jeugdsportcoach',
            'description' => 'Erkend opleidingstraject voor jeugdsportcoaches. Na inschrijving vul je een intake-vragenlijst in, upload je relevante diploma\'s, en wacht je op goedkeuring.',
            'type' => 'in-person', 'format' => ['klassikaal'], 'themes' => ['beweging', 'sportblessures'],
            'covers' => ['model:closed_single'],
            'editions' => [
                [
                    'start_date' => date('Y-m-d', strtotime('+6 weeks')),
                    'price' => 895.00, 'price_non_member' => 995.00, 'capacity' => 12,
                    'venue' => 'BWEEG Opleidingscentrum Gent',
                    'status' => OfferingStatus::Open->value,
                    'speakers' => [
                        ['name' => 'Prof. dr. An Vermeersch', 'role' => 'Hoofddocent'],
                        ['name' => 'Dr. Koen De Smet', 'role' => 'Praktijkbegeleider'],
                    ],
                    'enrollment_form' => 'default',
                    'requires' => ['questionnaire', 'documents', 'approval'],
                    'covers' => ['status:open', 'req:pre_questionnaire', 'req:pre_documents', 'req:pre_approval',
                                 'reg:pending', 'quote:draft'],
                    'registrations' => [
                        ['user' => 'seed_student3', 'status' => 'pending', 'path' => 'individual',
                         'init_tasks' => true, 'quote' => 'draft'],
                    ],
                    'sessions' => [
                        ['date_offset' => 0,  'start' => '09:00', 'end' => '17:00', 'type' => 'in_person', 'title' => 'Dag 1: Intake en motorische screening'],
                        ['date_offset' => 7,  'start' => '09:00', 'end' => '17:00', 'type' => 'in_person', 'title' => 'Dag 2: Blessurepreventie en EHBO'],
                        ['date_offset' => 14, 'start' => '09:00', 'end' => '17:00', 'type' => 'in_person', 'title' => 'Dag 3: Begeleidingsvaardigheden'],
                    ],
                ],
            ],
            'lessons' => [
                ['title' => 'Voorbereidingsmateriaal', 'content' => 'Lees dit document voor de eerste lesdag van het erkenningstraject.'],
            ],
        ],
        // === Row 11: documents-only requirement + partner & colleague paths ===
        [
            'title' => 'Bijscholing Bewegingsonderwijs',
            'description' => 'Verplichte bijscholing voor leerkrachten LO. Upload je registratiebewijs bij inschrijving zodat we je accreditatie kunnen verwerken.',
            'type' => 'in-person', 'format' => ['klassikaal'], 'themes' => ['schoolbeleid'],
            'covers' => ['model:closed_single'],
            'editions' => [
                [
                    'start_date' => date('Y-m-d', strtotime('+4 weeks')),
                    'price' => 175.00, 'price_non_member' => 225.00, 'capacity' => 25,
                    'venue' => 'BWEEG Locatie Antwerpen',
                    'status' => OfferingStatus::Open->value,
                    'speakers' => [['name' => 'Drs. Sofie Claes', 'role' => 'Lerarenopleider']],
                    'enrollment_form' => 'default',
                    'requires' => ['documents'],
                    'covers' => ['status:open', 'req:pre_documents', 'path:partner', 'path:colleague'],
                    'registrations' => [
                        ['user' => 'seed_student1', 'status' => 'confirmed', 'path' => 'partner'],
                        ['user' => 'seed_student2', 'status' => 'confirmed', 'path' => 'colleague'],
                    ],
                    'sessions' => [
                        ['date_offset' => 0, 'start' => '09:30', 'end' => '16:30', 'type' => 'in_person', 'title' => 'Bijscholing Bewegingsonderwijs'],
                    ],
                ],
            ],
            'lessons' => [
                ['title' => 'Bijscholing module', 'content' => 'Actuele ontwikkelingen in het bewegingsonderwijs.'],
            ],
        ],
        // === Row 12: HYBRID — in_person + online (lesson-linked) + webinar + assignment ===
        [
            'title' => 'Beweegbeleid op School: van Visie tot Actie',
            'description' => 'Hybride leertraject: klassikale dag + e-learning + live webinar + praktijkopdracht. Stappenplan om een actief beweegbeleid uit te bouwen op jouw school.',
            'type' => 'hybrid', 'format' => ['klassikaal', 'hybrid'], 'themes' => ['schoolbeleid', 'beweging'],
            'covers' => ['model:closed_single'],
            'editions' => [
                [
                    'start_date' => date('Y-m-d', strtotime('+4 weeks')),
                    'price' => 550.00, 'price_non_member' => 650.00, 'capacity' => 20,
                    'venue' => 'BWEEG Gent + Online',
                    'status' => OfferingStatus::Open->value,
                    'speakers' => [['name' => 'Dr. Katrien Maes', 'role' => 'Onderwijspedagoge']],
                    'covers' => ['status:open', 'edition_type:hybrid', 'sessions:type_assignment',
                                 'sessions:type_webinar', 'sessions:type_online', 'sessions:lesson_linked'],
                    'registrations' => [
                        ['user' => 'admin', 'status' => 'confirmed', 'path' => 'individual'],
                    ],
                    'sessions' => [
                        ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => 'in_person', 'location' => 'BWEEG Opleidingscentrum Gent', 'title' => 'Introductie beweegbeleid'],
                        ['date_offset' => 3, 'start' => '00:00', 'end' => '23:59', 'type' => 'online', 'title' => 'E-learning: Draagvlak en stakeholders', 'link_lesson' => true],
                        ['date_offset' => 7, 'start' => '14:00', 'end' => '16:00', 'type' => 'webinar', 'title' => 'Live Q&A: Casusbespreking'],
                        ['date_offset' => 10, 'start' => '00:00', 'end' => '23:59', 'type' => 'online', 'title' => 'E-learning: Implementatie', 'link_lesson' => true],
                        ['date_offset' => 12, 'start' => '00:00', 'end' => '23:59', 'type' => 'assignment', 'title' => 'Opdracht: Actieplan voor je eigen school'],
                        ['date_offset' => 14, 'start' => '09:00', 'end' => '17:00', 'type' => 'in_person', 'location' => 'BWEEG Opleidingscentrum Gent', 'title' => 'Praktijkdag: Evaluatie en bijsturing'],
                    ],
                ],
            ],
            'lessons' => [
                ['title' => 'E-learning Beweegbeleid Module 1', 'content' => 'Online module over draagvlak en stakeholders.'],
                ['title' => 'E-learning Beweegbeleid Module 2', 'content' => 'Online module over implementatie en evaluatie.'],
            ],
        ],
        // === Row 13: webinar series + cancelled registration + cancelled quote + minimal form ===
        [
            'title' => 'Webinarreeks: Actuele Thema\'s in Jeugdgezondheid',
            'description' => 'Reeks van 4 interactieve webinars. Elke week een nieuw thema met een expert spreker. Inclusief opname en handouts.',
            'type' => 'webinar', 'format' => ['webinar', 'online'], 'themes' => ['welzijn', 'beweging'],
            'ld_price_type' => 'closed',
            'covers' => ['model:closed_single'],
            'editions' => [
                [
                    'start_date' => date('Y-m-d', strtotime('+1 week')),
                    'price' => 150.00, 'price_non_member' => 180.00, 'capacity' => 100,
                    'venue' => 'Online (Zoom)',
                    'status' => OfferingStatus::Open->value,
                    'speakers' => [['name' => 'Diverse experts', 'role' => 'Gastsprekers']],
                    'enrollment_form' => 'minimal',
                    'covers' => ['status:open', 'edition_type:webinar', 'sessions:type_webinar',
                                 'reg:cancelled', 'quote:cancelled', 'form:minimal'],
                    'registrations' => [
                        ['user' => 'seed_student5', 'status' => 'cancelled', 'path' => 'individual', 'quote' => 'cancelled'],
                    ],
                    'sessions' => [
                        ['date_offset' => 0, 'start' => '19:00', 'end' => '20:30', 'type' => 'webinar', 'title' => 'Webinar 1: Schermtijd en bewegen'],
                        ['date_offset' => 7, 'start' => '19:00', 'end' => '20:30', 'type' => 'webinar', 'title' => 'Webinar 2: Sportvoeding voor jongeren'],
                        ['date_offset' => 14, 'start' => '19:00', 'end' => '20:30', 'type' => 'webinar', 'title' => 'Webinar 3: Blessurepreventie update'],
                        ['date_offset' => 21, 'start' => '19:00', 'end' => '20:30', 'type' => 'webinar', 'title' => 'Webinar 4: Mentale veerkracht bij jongeren'],
                    ],
                ],
            ],
            'lessons' => [
                ['title' => 'Webinar introductie', 'content' => 'Algemene informatie over de webinarreeks jeugdgezondheid.'],
            ],
        ],
        // === Row 14: single free webinar ===
        [
            'title' => 'Gratis Webinar: Energy Drinks - De Nieuwe Trend',
            'description' => 'Gratis informatief webinar over energy drinks bij jongeren. Risico\'s, herkenning en gesprekstechnieken.',
            'type' => 'webinar', 'format' => ['webinar', 'online'], 'themes' => ['voeding', 'welzijn'],
            'covers' => ['model:closed_single'],
            'editions' => [
                [
                    'start_date' => date('Y-m-d', strtotime('+10 days')),
                    'price' => 0.00, 'price_non_member' => 0.00, 'capacity' => 200,
                    'venue' => 'Online (Teams)',
                    'status' => OfferingStatus::Open->value,
                    'speakers' => [['name' => 'Dr. Sarah Janssen', 'role' => 'Voedingsdeskundige']],
                    'covers' => ['status:open', 'edition_type:webinar'],
                    'sessions' => [
                        ['date_offset' => 0, 'start' => '12:00', 'end' => '13:00', 'type' => 'webinar', 'title' => 'Lunchwebinar: Energy drinks - feiten en risico\'s'],
                    ],
                ],
            ],
            'lessons' => [
                ['title' => 'Energy drinks info', 'content' => 'Informatie over energy drinks en de effecten bij jongeren.'],
            ],
        ],
        // === Row 15: NEW — draft + archived editions ===
        [
            'title' => 'Nieuw Najaarsaanbod: Buitenspelen het Hele Jaar',
            'description' => 'Nieuwe opleiding in voorbereiding over buitenspelen en risicovol spelen in alle seizoenen. Programma volgt.',
            'type' => 'in-person', 'format' => ['klassikaal'], 'themes' => ['beweging', 'jeugdwerk'],
            'covers' => ['model:multi_edition'],
            'editions' => [
                [   // Draft: nog niet gepubliceerd aanbod
                    'start_date' => date('Y-m-d', strtotime('+5 months')),
                    'price' => 275.00, 'price_non_member' => 325.00, 'capacity' => 18,
                    'venue' => 'BWEEG Opleidingscentrum Gent',
                    'status' => OfferingStatus::Draft->value,
                    'speakers' => [['name' => 'Tine Wouters', 'role' => 'Speelpedagoge']],
                    'covers' => ['status:draft'],
                    'sessions' => [
                        ['date_offset' => 0, 'start' => '09:30', 'end' => '16:30', 'type' => 'in_person', 'title' => 'Buitenspelen het hele jaar'],
                    ],
                ],
                [   // Archived: vorige jaargang
                    'start_date' => date('Y-m-d', strtotime('-1 year')),
                    'price' => 250.00, 'price_non_member' => 295.00, 'capacity' => 18,
                    'venue' => 'BWEEG Opleidingscentrum Gent',
                    'status' => OfferingStatus::Archived->value,
                    'speakers' => [['name' => 'Tine Wouters', 'role' => 'Speelpedagoge']],
                    'covers' => ['status:archived'],
                    'sessions' => [
                        ['date_offset' => 0, 'start' => '09:30', 'end' => '16:30', 'type' => 'in_person', 'title' => 'Buitenspelen (jaargang vorig jaar)'],
                    ],
                ],
            ],
            'lessons' => [
                ['title' => 'Buitenspelen module', 'content' => 'Waarom buitenspelen essentieel is, in elk seizoen.'],
            ],
        ],
        // === Rows 16-20: trajectory courses (referenced by TITLE from the trajectories below) ===
        [
            'title' => 'Jeugdgezondheid: Fundament',
            'description' => 'Basiscursus voor iedereen die professioneel met jeugdgezondheid werkt. Verplicht onderdeel van het traject Jeugdgezondheidsspecialist.',
            'type' => 'in-person', 'format' => ['klassikaal'], 'themes' => ['beweging', 'welzijn'],
            'covers' => ['model:trajectory_course'],
            'editions' => [
                [
                    'start_date' => date('Y-m-d', strtotime('+2 weeks')),
                    'price' => 395.00, 'price_non_member' => 495.00, 'capacity' => 16,
                    'venue' => 'BWEEG Opleidingscentrum Gent',
                    'status' => OfferingStatus::Open->value,
                    'speakers' => [['name' => 'Team BWEEG Academie', 'role' => 'Docenten']],
                    'covers' => ['status:open', 'path:trajectory'],
                    'registrations' => [
                        ['user' => 'seed_enrolled_user', 'status' => 'confirmed', 'path' => 'trajectory'],
                    ],
                    'sessions' => [
                        ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => 'in_person', 'title' => 'Dag 1: Basiskennis'],
                        ['date_offset' => 1, 'start' => '09:00', 'end' => '17:00', 'type' => 'in_person', 'title' => 'Dag 2: Gespreksvaardigheden'],
                    ],
                ],
            ],
            'lessons' => [
                ['title' => 'Fundament module 1', 'content' => 'Basiskennis jeugdgezondheid.'],
                ['title' => 'Fundament module 2', 'content' => 'Introductie gespreksvoering.'],
            ],
        ],
        [
            'title' => 'Assessment en Motorische Screening',
            'description' => 'Gestructureerd intake afnemen, motorische screeningsinstrumenten gebruiken, begeleidingsplan opstellen. Verplicht onderdeel traject.',
            'type' => 'in-person', 'format' => ['klassikaal'], 'themes' => ['beweging', 'sportblessures'],
            'covers' => ['model:trajectory_course'],
            'editions' => [
                [
                    'start_date' => date('Y-m-d', strtotime('+4 weeks')),
                    'price' => 395.00, 'price_non_member' => 495.00, 'capacity' => 14,
                    'venue' => 'BWEEG Opleidingscentrum Gent',
                    'status' => OfferingStatus::Open->value,
                    'speakers' => [['name' => 'Dr. Tom Decorte', 'role' => 'Bewegingswetenschapper']],
                    'covers' => ['status:open'],
                    'sessions' => [
                        ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => 'in_person', 'title' => 'Motorische screening en instrumenten'],
                        ['date_offset' => 14, 'start' => '09:00', 'end' => '17:00', 'type' => 'in_person', 'title' => 'Begeleidingsplanning en doelen stellen'],
                    ],
                ],
            ],
            'lessons' => [
                ['title' => 'Assessment theorie', 'content' => 'Motorische screeningsinstrumenten en intake.'],
                ['title' => 'Screening praktijk', 'content' => 'Begeleidingsplanning.'],
            ],
        ],
        [
            'title' => 'Keuzecursus: Familie en Gezondheidscoaching',
            'description' => 'Verdieping over het betrekken van het gezin bij gezondheidsbevordering. Keuzevak voor het traject Jeugdgezondheidsspecialist.',
            'type' => 'in-person', 'format' => ['klassikaal'], 'themes' => ['welzijn', 'voeding'],
            'covers' => ['model:trajectory_course'],
            'editions' => [
                [
                    'start_date' => date('Y-m-d', strtotime('+8 weeks')),
                    'price' => 245.00, 'price_non_member' => 295.00, 'capacity' => 16,
                    'venue' => 'BWEEG Locatie Antwerpen',
                    'status' => OfferingStatus::Open->value,
                    'speakers' => [['name' => 'Drs. Lies Verhoeven', 'role' => 'Gezondheidscoach']],
                    'covers' => ['status:open'],
                    'sessions' => [
                        ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => 'in_person', 'title' => 'Familie en gezondheidscoaching'],
                    ],
                ],
            ],
            'lessons' => [
                ['title' => 'Gezondheidscoaching module', 'content' => 'Werken met het gezin rondom de jongere.'],
            ],
        ],
        [
            'title' => 'Keuzecursus: Groepsdynamiek in Sportteams',
            'description' => 'Verdieping in groepsdynamiek bij jonge sportteams. Teamcohesie, conflicthantering en motivatietechnieken.',
            'type' => 'in-person', 'format' => ['klassikaal'], 'themes' => ['beweging', 'welzijn'],
            'covers' => ['model:trajectory_course'],
            'editions' => [
                [
                    'start_date' => date('Y-m-d', strtotime('+9 weeks')),
                    'price' => 245.00, 'price_non_member' => 295.00, 'capacity' => 12,
                    'venue' => 'BWEEG Opleidingscentrum Gent',
                    'status' => OfferingStatus::Open->value,
                    'speakers' => [['name' => 'Dr. Kris Goethals', 'role' => 'Sportpsycholoog']],
                    'covers' => ['status:open'],
                    'sessions' => [
                        ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => 'in_person', 'title' => 'Groepsdynamiek in sportteams'],
                    ],
                ],
            ],
            'lessons' => [
                ['title' => 'Groepsdynamiek', 'content' => 'Theorie en praktijk van groepsdynamiek in sportteams.'],
            ],
        ],
        [
            'title' => 'Keuzecursus: Harm Reduction bij Eetproblemen',
            'description' => 'Verdieping in harm reduction benaderingen bij eetproblemen. Signalering, gespreksvoering en doorverwijzing.',
            'type' => 'in-person', 'format' => ['klassikaal'], 'themes' => ['voeding', 'welzijn'],
            'covers' => ['model:trajectory_course'],
            'editions' => [
                [
                    'start_date' => date('Y-m-d', strtotime('+10 weeks')),
                    'price' => 195.00, 'price_non_member' => 245.00, 'capacity' => 20,
                    'venue' => 'BWEEG Hoofdkantoor Gent',
                    'status' => OfferingStatus::Open->value,
                    'speakers' => [['name' => 'Peter Vander Laenen', 'role' => 'Preventiewerker']],
                    'covers' => ['status:open'],
                    'sessions' => [
                        ['date_offset' => 0, 'start' => '09:30', 'end' => '16:30', 'type' => 'in_person', 'title' => 'Harm reduction bij eetproblemen: theorie en praktijk'],
                    ],
                ],
            ],
            'lessons' => [
                ['title' => 'Harm reduction module', 'content' => 'Principes en praktijk van schadebeperking bij eetproblemen.'],
            ],
        ],
    ],
    'trajectories' => [
        // Course references by TITLE — resolved to IDs by buildTrajectory()
        // (replaces the old brittle index-position scheme).
        [
            'title' => 'Traject Jeugdgezondheidsspecialist',
            'content' => '<p>Word een gecertificeerd jeugdgezondheidsspecialist met dit intensieve cohort-traject.</p>
<h3>Verplichte cursussen</h3>
<ul><li>Jeugdgezondheid: Fundament (2 dagen)</li><li>Assessment en Motorische Screening (2 dagen)</li><li>Motiverende Gespreksvoering rond Voeding (1 dag)</li><li>Sportblessures Voorkomen (3 dagen)</li></ul>
<h3>Keuzecursussen (kies 2 uit 3)</h3>
<ul><li>Familie en Gezondheidscoaching</li><li>Groepsdynamiek in Sportteams</li><li>Harm Reduction bij Eetproblemen</li></ul>',
            'mode' => TrajectoryMode::Cohort->value,
            'status' => OfferingStatus::Open->value,
            'enrollment_deadline' => date('Y-m-d', strtotime('+1 month')),
            'choice_available_date' => date('Y-m-d', strtotime('+2 weeks')),
            'choice_deadline' => date('Y-m-d', strtotime('+3 weeks')),
            'capacity' => 15,
            'price' => 1695.00, 'price_non_member' => 1995.00,
            'courses' => [
                ['course_title' => 'Jeugdgezondheid: Fundament', 'required' => true, 'order' => 1],
                ['course_title' => 'Assessment en Motorische Screening', 'required' => true, 'order' => 2],
                ['course_title' => 'Motiverende Gespreksvoering rond Voeding bij Jongeren', 'required' => true, 'order' => 3],
                ['course_title' => 'Sportblessures Voorkomen: van Warm-up tot Cool-down', 'required' => true, 'order' => 4],
                ['course_title' => 'Keuzecursus: Familie en Gezondheidscoaching', 'required' => false, 'group' => 'Keuzecursussen (kies 2)', 'min_choices' => 2],
                ['course_title' => 'Keuzecursus: Groepsdynamiek in Sportteams', 'required' => false, 'group' => 'Keuzecursussen (kies 2)', 'min_choices' => 2],
                ['course_title' => 'Keuzecursus: Harm Reduction bij Eetproblemen', 'required' => false, 'group' => 'Keuzecursussen (kies 2)', 'min_choices' => 2],
            ],
            'covers' => ['trajectory:cohort', 'trajectory:elective_choose_n'],
        ],
        [
            'title' => 'Traject Jeugdgezondheidswerker',
            'content' => '<p>Flexibel traject voor jeugdgezondheidswerkers. Combineer online cursussen met praktijkdagen, in je eigen tempo.</p>',
            'mode' => TrajectoryMode::SelfPaced->value,
            'status' => OfferingStatus::Open->value,
            'enrollment_deadline' => '',
            'capacity' => 0,
            'price' => 595.00, 'price_non_member' => 745.00,
            'courses' => [
                ['course_title' => 'E-learning: Basiskennis Jeugdgezondheid', 'required' => true, 'order' => 1],
                ['course_title' => 'E-learning: Voeding en Prestatie bij Jonge Sporters', 'required' => true, 'order' => 2],
                ['course_title' => 'Gezonde Tussendoortjes in de Jeugdwerking', 'required' => true, 'order' => 3],
                ['course_title' => 'E-learning: Beweegbeleid Ontwikkelen', 'required' => false, 'group' => 'Optionele verdieping'],
                ['course_title' => 'Webinarreeks: Actuele Thema\'s in Jeugdgezondheid', 'required' => false, 'group' => 'Optionele verdieping'],
            ],
            'covers' => ['trajectory:self_paced'],
        ],
        [
            'title' => 'Kennismakingstraject Jeugdgezondheid',
            'content' => '<p>Laagdrempelig kennismakingstraject. Combinatie van gratis en betaalde onderdelen.</p>',
            'mode' => TrajectoryMode::SelfPaced->value,
            'status' => OfferingStatus::Open->value,
            'enrollment_deadline' => '',
            'capacity' => 0,
            'price' => 0.00, 'price_non_member' => 0.00,
            'courses' => [
                ['course_title' => 'Gratis Introductie: Werken bij BWEEG', 'required' => true, 'order' => 1],
                ['course_title' => 'Gratis Webinar: Energy Drinks - De Nieuwe Trend', 'required' => true, 'order' => 2],
                ['course_title' => 'E-learning: Basiskennis Jeugdgezondheid', 'required' => false, 'group' => 'Optionele verdieping (betaald)'],
                ['course_title' => 'Motiverende Gespreksvoering rond Voeding bij Jongeren', 'required' => false, 'group' => 'Optionele verdieping (betaald)'],
            ],
            'covers' => [],
        ],
        [   // Slug-pinned E2E fixture — keep byte-identical contract
            'title' => 'Test Trajectory',
            'slug' => 'test-trajectory',
            'content' => 'A test trajectory for E2E testing.',
            'mode' => TrajectoryMode::Cohort->value,
            'status' => OfferingStatus::Open->value,
            'enrollment_deadline' => date('Y-m-d', strtotime('+30 days')),
            'capacity' => 20,
            'price' => 600, 'price_non_member' => 600,
            'covers' => [],
        ],
    ],
    'vouchers' => [
        // SEEDVOUCHER10 code preserved (legacy fixture). discount_value: cents
        // for fixed, 0-100 for percentage. scope: all | only | except.
        ['code' => 'WELKOM2026', 'discount_type' => 'percentage', 'discount_value' => 10, 'usage_limit' => 50, 'scope' => 'all',
         'covers' => ['voucher:percentage', 'voucher:scope_all']],
        ['code' => 'KORTING50', 'discount_type' => 'fixed', 'discount_value' => 5000, 'usage_limit' => 20, 'scope' => 'all',
         'covers' => ['voucher:fixed']],
        ['code' => 'GRATIS-INTRO', 'discount_type' => 'full', 'discount_value' => 0, 'usage_limit' => 5, 'scope' => 'all',
         'covers' => ['voucher:full']],
        ['code' => 'SEEDVOUCHER10', 'discount_type' => 'percentage', 'discount_value' => 10, 'usage_limit' => 100, 'scope' => 'all',
         'covers' => []],   // legacy fixture, preserved
        ['code' => 'EDITIE-ONLY', 'discount_type' => 'percentage', 'discount_value' => 20, 'usage_limit' => 10, 'scope' => 'only',
         'covers' => ['voucher:scope_only']],
        ['code' => 'NIET-HIER', 'discount_type' => 'fixed', 'discount_value' => 2500, 'usage_limit' => 10, 'scope' => 'except',
         'covers' => ['voucher:scope_except']],
    ],
    'questionnaire_groups' => [
        // NOTE: field 'options' are comma-separated STRINGS — verified against
        // QuestionnaireSettingsPage::sanitize (:329) and the renderers
        // (dynamic-field.php / field-radio.php both explode(',', $options)).
        [   // Custom enrollment form exercising ALL 7 field types + reserved auto-persist fields
            'id' => 'qg_enrollment_seed',
            'label' => 'Extra inschrijvingsvragen',
            'stage' => 'enrollment_personal',                  // valid per QuestionnaireRepository::STAGES
            'assign_to_course' => 'Erkenningstraject Jeugdsportcoach',   // resolved to course ID by builder
            'fields' => [
                ['label' => 'Toelichting', 'name' => 'intro_desc', 'type' => 'description',
                 'description' => 'Deze gegevens gebruiken we om de opleiding af te stemmen op de groep.'],
                ['label' => 'Telefoonnummer', 'name' => 'phone', 'type' => 'text', 'required' => true],          // reserved → wp_usermeta
                ['label' => 'Organisatie', 'name' => 'organisation', 'type' => 'text', 'required' => true],      // reserved → wp_usermeta
                ['label' => 'Motivatie', 'name' => 'motivatie', 'type' => 'textarea', 'required' => true],
                ['label' => 'Functie', 'name' => 'functie', 'type' => 'select', 'required' => true,
                 'options' => 'Leerkracht, CLB-medewerker, Jeugdwerker, Sportcoach, Andere'],
                ['label' => 'Ervaring met jeugdsport', 'name' => 'ervaring', 'type' => 'radio', 'required' => true,
                 'options' => 'Geen, 1-3 jaar, Meer dan 3 jaar'],
                ['label' => 'Ik wil de nieuwsbrief ontvangen', 'name' => 'nieuwsbrief', 'type' => 'checkbox', 'required' => false,
                 'description' => 'Maximaal één mail per maand.'],
                ['label' => 'Hoe schat je je voorkennis in?', 'name' => 'voorkennis_schaal', 'type' => 'scale',
                 'required' => true, 'min' => 1, 'max' => 5],
            ],
            'covers' => ['form:custom_all_types', 'form:reserved_fields'],
        ],
        [   // Evaluation group (preserves current qg_eval_seed behavior)
            'id' => 'qg_eval_seed',
            'label' => 'Evaluatie opleiding',
            'stage' => 'evaluation',
            'assign_to_course' => '*post_course*',   // builder: every course whose edition has post_requires_evaluation
            'fields' => [
                ['label' => 'Beoordeling docent', 'name' => 'beoordeling_docent', 'type' => 'scale', 'required' => true, 'min' => 1, 'max' => 5],
                ['label' => 'Beoordeling lesmateriaal', 'name' => 'beoordeling_materiaal', 'type' => 'scale', 'required' => true, 'min' => 1, 'max' => 5],
                ['label' => 'Opmerkingen', 'name' => 'opmerkingen', 'type' => 'textarea', 'required' => false],
            ],
            'covers' => ['req:post_evaluation'],
        ],
    ],
    'company_details' => [
        'name' => 'BWEEG vzw', 'address' => 'Sportstraat 42', 'postal_code' => '9000',
        'city' => 'Gent', 'country' => 'België', 'vat' => 'BE0420.798.935',
        'email' => 'info@bweeg.be', 'phone' => '+32 9 234 56 78', 'bank_account' => 'BE68 0682 0553 5765',
    ],
];
