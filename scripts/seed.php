<?php
/**
 * Stride LMS - Development Seed Script
 *
 * Run with: ddev exec wp eval-file scripts/seed.php
 *
 * Creates comprehensive test data for frontend testing:
 *
 * ONLINE COURSES (5 total):
 *   - 2 "open" courses: LD native enrollment (no edition, LearnDash handles access)
 *   - 3 "closed" courses: with editions + enrollment_form + sessions for admin
 *
 * IN-PERSON COURSES (8 total):
 *   - Simple single-session courses
 *   - Multi-day intensive courses (consecutive days)
 *   - Multi-session courses (spread over weeks)
 *   - Courses with session slots + selection deadlines
 *   - Courses with optional/mandatory sessions
 *   - Full/cancelled/rescheduled editions
 *   - Free introductory courses
 *
 * HYBRID / WEBINAR (3 total)
 *
 * TRAJECTORIES (3 total)
 *
 * VOUCHERS, QUOTES, REGISTRATIONS for testing
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: ddev exec wp eval-file scripts/seed.php\n";
    exit(1);
}

// Prevent accidental runs in production
if (!defined('WP_ENV') || !in_array(WP_ENV, ['development', 'local'], true)) {
    echo "ERROR: Seed script only allowed in development/local environments!\n";
    exit(1);
}

use Stride\Domain\RegistrationStatus;
use Stride\Domain\SessionType;
use Stride\Domain\TrajectoryMode;
use Stride\Domain\OfferingStatus;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryService;
use Stride\Modules\Enrollment\EnrollmentCompletion;

// Session type constants
const SESSION_TYPE_IN_PERSON = 'in_person';
const SESSION_TYPE_ONLINE = 'online';
const SESSION_TYPE_WEBINAR = 'webinar';
const SESSION_TYPE_ASSIGNMENT = 'assignment';

/**
 * Comprehensive Seed Data Generator
 */
class StrideSeedData {

    private array $created = [
        'users' => [],
        'courses' => [],
        'editions' => [],
        'sessions' => [],
        'registrations' => [],
        'trajectories' => [],
        'vouchers' => [],
        'quotes' => [],
    ];

    private const SEED_META_KEY = '_stride_seed_data';

    private ?EditionService $editionService = null;
    private ?EditionRepository $editionRepository = null;
    private ?SessionService $sessionService = null;
    private ?RegistrationRepository $regRepo = null;
    private ?TrajectoryService $trajectoryService = null;

    // Current admin user to subscribe
    private int $adminUserId = 1;

    public function run(): void {
        echo "\n=== Stride LMS Comprehensive Seed ===\n\n";

        $this->initServices();
        $this->ensureTaxonomyTerms();
        $this->createUsers();
        $this->createCourses();
        $this->createTrajectories();
        $this->createVouchers();
        $this->createEnrollmentsAndQuotes();
        $this->createPostCourseTestData();
        $this->createTrajectoryTestData();

        $this->saveSeedManifest();
        $this->seedCompanyDetails();
        $this->printSummary();
    }

    private function initServices(): void {
        if (function_exists('ntdst_get')) {
            $this->editionService = ntdst_get(EditionService::class);
            $this->editionRepository = ntdst_get(EditionRepository::class);
            $this->sessionService = ntdst_get(SessionService::class);
            $this->regRepo = ntdst_get(RegistrationRepository::class);
            $this->trajectoryService = ntdst_get(TrajectoryService::class);
        }
    }

    /**
     * Ensure stride_format and stride_theme taxonomy terms exist.
     */
    private function ensureTaxonomyTerms(): void {
        echo "Ensuring taxonomy terms...\n";

        // stride_format terms
        $formats = [
            'klassikaal' => 'Klassikaal',
            'online'     => 'Online',
            'e-learning' => 'E-learning',
            'webinar'    => 'Webinar',
            'hybrid'     => 'Hybride',
        ];

        foreach ($formats as $slug => $name) {
            if (!term_exists($slug, 'stride_format')) {
                $result = wp_insert_term($name, 'stride_format', ['slug' => $slug]);
                if (!is_wp_error($result)) {
                    echo "  + Format term: {$name}\n";
                }
            }
        }

        // stride_theme terms
        $themes = [
            'beweging'       => 'Beweging',
            'voeding'        => 'Voeding',
            'welzijn'        => 'Welzijn',
            'sportblessures' => 'Sportblessures',
            'jeugdwerk'      => 'Jeugdwerk',
            'schoolbeleid'   => 'Schoolbeleid',
        ];

        foreach ($themes as $slug => $name) {
            if (!term_exists($slug, 'stride_theme')) {
                $result = wp_insert_term($name, 'stride_theme', ['slug' => $slug]);
                if (!is_wp_error($result)) {
                    echo "  + Theme term: {$name}\n";
                }
            }
        }
    }

    private function createUsers(): void {
        echo "\nCreating users...\n";

        $users = [
            ['login' => 'seed_admin', 'email' => 'seed_admin@seed.test', 'role' => 'administrator', 'first' => 'Admin', 'last' => 'Seed'],
            ['login' => 'seed_instructor', 'email' => 'instructor@seed.test', 'role' => 'group_leader', 'first' => 'Jan', 'last' => 'De Trainer'],
            ['login' => 'seed_partner', 'email' => 'seed_partner@seed.test', 'role' => 'partner', 'first' => 'Partner', 'last' => 'Test', 'company_id' => 1],
            ['login' => 'seed_student1', 'email' => 'student1@seed.test', 'role' => 'subscriber', 'first' => 'Pieter', 'last' => 'Janssen', 'is_member' => true, 'company_id' => 1],
            ['login' => 'seed_student2', 'email' => 'student2@seed.test', 'role' => 'subscriber', 'first' => 'Anna', 'last' => 'De Vries', 'is_member' => true, 'company_id' => 1],
            ['login' => 'seed_student3', 'email' => 'student3@seed.test', 'role' => 'subscriber', 'first' => 'Thomas', 'last' => 'Bakker', 'is_member' => false, 'company_id' => 1],
            ['login' => 'seed_coordinator', 'email' => 'seed_coordinator@seed.test', 'role' => 'stride_coordinator', 'first' => 'Coordinator', 'last' => 'Seed'],
            ['login' => 'seed_supervisor', 'email' => 'seed_supervisor@seed.test', 'role' => 'stride_supervisor', 'first' => 'Supervisor', 'last' => 'Seed'],
        ];

        foreach ($users as $userData) {
            $existing = get_user_by('login', $userData['login']);
            if ($existing) {
                $this->created['users'][] = $existing->ID;
                if (!get_user_meta($existing->ID, 'ntdst_auth_activated', true)) {
                    update_user_meta($existing->ID, 'ntdst_auth_activated', true);
                    update_user_meta($existing->ID, 'ntdst_auth_activated_at', time());
                    echo "  - User {$userData['login']} exists (ID: {$existing->ID}) - activated\n";
                } else {
                    echo "  - User {$userData['login']} exists (ID: {$existing->ID})\n";
                }
                if (isset($userData['company_id'])) {
                    update_user_meta($existing->ID, '_stride_company_id', $userData['company_id']);
                }
                continue;
            }

            $userId = wp_insert_user([
                'user_login' => $userData['login'],
                'user_email' => $userData['email'],
                'user_pass' => 'seedpass123',
                'first_name' => $userData['first'],
                'last_name' => $userData['last'],
                'display_name' => $userData['first'] . ' ' . $userData['last'],
                'role' => $userData['role'],
            ]);

            if (is_wp_error($userId)) {
                echo "  ! Failed: {$userId->get_error_message()}\n";
                continue;
            }

            update_user_meta($userId, self::SEED_META_KEY, true);
            if (isset($userData['is_member'])) {
                update_user_meta($userId, 'is_vad_member', $userData['is_member']);
            }
            if (isset($userData['company_id'])) {
                update_user_meta($userId, '_stride_company_id', $userData['company_id']);
            }

            // Activate users for auth (ntdst-auth requires activation)
            update_user_meta($userId, 'ntdst_auth_activated', true);
            update_user_meta($userId, 'ntdst_auth_activated_at', time());

            $this->created['users'][] = $userId;
            echo "  + Created: {$userData['login']} (ID: {$userId})\n";
        }
    }

    private function createCourses(): void {
        echo "\nCreating courses with editions and sessions...\n";

        if (!defined('LEARNDASH_VERSION')) {
            echo "  ! LearnDash not active, skipping courses\n";
            return;
        }

        $courses = [

            // =========================================================================
            // INDEX 0-1: ONLINE COURSES - OPEN (LearnDash native enrollment, no editions)
            // These appear on /online/ and use LD payment buttons for enrollment.
            // =========================================================================

            // === INDEX 0: Open online - comprehensive introduction ===
            [
                'title' => 'E-learning: Basiskennis Jeugdgezondheid',
                'description' => 'Uitgebreide online cursus over de pijlers van jeugdgezondheid: beweging, voeding en mentaal welzijn. Evidence-based inzichten voor iedereen die met jongeren werkt.',
                'type' => 'online',
                'format' => ['online', 'e-learning'],
                'themes' => ['beweging', 'welzijn'],
                'ld_price_type' => 'open', // free, no restrictions
                'editions' => [],
                'lessons' => [
                    ['title' => 'Module 1: Wat is jeugdgezondheid?', 'content' => '<h3>Definitie en Kenmerken</h3><p>In deze eerste module verkennen we wat jeugdgezondheid precies inhoudt. We bekijken de drie pijlers: beweging, voeding en mentaal welzijn.</p>'],
                    ['title' => 'Module 2: Bewegen en motoriek', 'content' => '<h3>Beweging bij Jongeren</h3><p>Hoe beweging bijdraagt aan fysieke en mentale ontwikkeling. Beweegnormen en motorische vaardigheden.</p>'],
                    ['title' => 'Module 3: Voeding bij jongeren', 'content' => '<h3>Gezonde Voeding</h3><p>Overzicht van voedingsbehoeften bij jongeren en de impact op groei en prestaties.</p>'],
                    ['title' => 'Module 4: Mentaal welzijn', 'content' => '<h3>Psychisch Welbevinden</h3><p>Stressmanagement, veerkracht en het herkennen van signalen bij jongeren.</p>'],
                    ['title' => 'Module 5: Eindtoets', 'content' => '<h3>Toets je Kennis</h3><p>25 meerkeuzevragen. Minimaal 70% om te slagen.</p>'],
                ],
            ],

            // === INDEX 1: Open online - sport nutrition course ===
            [
                'title' => 'E-learning: Voeding en Prestatie bij Jonge Sporters',
                'description' => 'Verdiepende online module over sportvoeding voor jongeren. Van koolhydraatlading tot hydratatie, afgestemd op groeiende lichamen.',
                'type' => 'online',
                'format' => ['online', 'e-learning'],
                'themes' => ['voeding', 'beweging'],
                'ld_price_type' => 'open',
                'ld_expire_access' => 'on',
                'ld_expire_access_days' => 90,
                'editions' => [],
                'lessons' => [
                    ['title' => 'Hoofdstuk 1: Voedingsbehoeften van jonge sporters', 'content' => '<h3>De basis</h3><p>Jongeren in de groei hebben specifieke voedingsbehoeften, zeker bij sport.</p>'],
                    ['title' => 'Hoofdstuk 2: Macro- en micronutriënten', 'content' => '<h3>Bouwstenen</h3><p>Koolhydraten, eiwitten, vetten, vitaminen en mineralen voor prestatie.</p>'],
                    ['title' => 'Hoofdstuk 3: Hydratatie en sportdranken', 'content' => '<h3>Vochtbalans</h3><p>Wanneer water volstaat en wanneer sportdranken nodig zijn.</p>'],
                    ['title' => 'Hoofdstuk 4: Timing en maaltijdplanning', 'content' => '<h3>Rond de Training</h3><p>Wat eet je voor, tijdens en na het sporten?</p>'],
                    ['title' => 'Hoofdstuk 5: Veelvoorkomende fouten', 'content' => '<h3>Valkuilen</h3><p>Supplementengebruik, crash-diëten en onvoldoende energie-inname bij jongeren.</p>'],
                ],
            ],

            // =========================================================================
            // INDEX 2-4: ONLINE COURSES - CLOSED (with editions, enrollment form, sessions)
            // These appear on /online/ but require going through Stride enrollment form.
            // =========================================================================

            // === INDEX 2: Closed online - eating disorders (with edition + form) ===
            [
                'title' => 'E-learning: Eetproblemen Herkennen en Bespreekbaar Maken',
                'description' => 'Leer de vroege signalen van eetproblemen herkennen bij jongeren en hoe je het gesprek aangaat — zonder te diagnosticeren. Speciaal voor leerkrachten en CLB-medewerkers.',
                'type' => 'online',
                'format' => ['online', 'e-learning'],
                'themes' => ['welzijn', 'voeding'],
                'ld_price_type' => 'closed', // requires enrollment
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+1 day')),
                        'end_date' => date('Y-m-d', strtotime('+90 days')),
                        'price' => 75.00,
                        'price_non_member' => 95.00,
                        'capacity' => 100,
                        'venue' => 'Online',
                        'status' => 'open',
                        'enrollment_form' => 'default',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '00:00', 'end' => '23:59', 'type' => SESSION_TYPE_ONLINE, 'title' => 'E-learning: Eetproblemen module (90 dagen toegang)'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Les 1: Wat zijn eetproblemen?', 'content' => '<h3>Vormen en Signalen</h3><p>Anorexia, boulimie, eetbuistoornis en selectief eten bij jongeren.</p>'],
                    ['title' => 'Les 2: Vroege signalen herkennen', 'content' => '<h3>Observatie en Alert Zijn</h3><p>Fysieke, emotionele en gedragsmatige signalen die wijzen op eetproblemen.</p>'],
                    ['title' => 'Les 3: Het gesprek aangaan', 'content' => '<h3>Communicatie</h3><p>Hoe bespreek je zorgen zonder te diagnosticeren of te stigmatiseren?</p>'],
                ],
            ],

            // === INDEX 3: Closed online - screen time + movement (with edition + form) ===
            [
                'title' => 'E-learning: Schermtijd en Bewegingsarmoede',
                'description' => 'Interactieve e-learning over de impact van schermgedrag op beweging en welzijn bij jongeren. Met casussen, video-interviews en een toolkit voor gesprekken.',
                'type' => 'online',
                'format' => ['online', 'e-learning'],
                'themes' => ['welzijn', 'beweging'],
                'ld_price_type' => 'closed',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+1 day')),
                        'end_date' => date('Y-m-d', strtotime('+60 days')),
                        'price' => 65.00,
                        'price_non_member' => 85.00,
                        'capacity' => 200,
                        'venue' => 'Online',
                        'status' => 'open',
                        'enrollment_form' => 'default',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '00:00', 'end' => '23:59', 'type' => SESSION_TYPE_ONLINE, 'title' => 'E-learning module (60 dagen toegang)'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Module 1: Schermgedrag bij jongeren', 'content' => '<p>Hoe jongeren schermen gebruiken en de impact op hun beweeggedrag.</p>'],
                    ['title' => 'Module 2: Gevolgen van bewegingsarmoede', 'content' => '<p>Fysieke, mentale en sociale gevolgen van te weinig bewegen.</p>'],
                    ['title' => 'Module 3: Schermtijd en welzijn', 'content' => '<p>Het verband tussen schermgebruik, slaap, concentratie en motivatie om te bewegen.</p>'],
                    ['title' => 'Module 4: In gesprek met jongeren', 'content' => '<p>Praktische gesprekstechnieken om schermtijd en beweging bespreekbaar te maken.</p>'],
                ],
            ],

            // === INDEX 4: Closed online - movement policy (full, to test) ===
            [
                'title' => 'E-learning: Beweegbeleid Ontwikkelen',
                'description' => 'Uitgebreide online cursus over het opzetten van een actief beweegbeleid in scholen en jeugdwerkingen. Van visie tot concrete acties.',
                'type' => 'online',
                'format' => ['online', 'e-learning'],
                'themes' => ['schoolbeleid', 'beweging'],
                'ld_price_type' => 'closed',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('-30 days')),
                        'end_date' => date('Y-m-d', strtotime('+60 days')),
                        'price' => 125.00,
                        'price_non_member' => 150.00,
                        'capacity' => 50,
                        'registered' => 50, // FULL
                        'venue' => 'Online',
                        'status' => 'open',
                        'enrollment_form' => 'default',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '00:00', 'end' => '23:59', 'type' => SESSION_TYPE_ONLINE, 'title' => 'E-learning: Beweegbeleid (lopende cohort)'],
                        ],
                    ],
                    // Second edition opening soon
                    [
                        'start_date' => date('Y-m-d', strtotime('+2 weeks')),
                        'end_date' => date('Y-m-d', strtotime('+12 weeks')),
                        'price' => 125.00,
                        'price_non_member' => 150.00,
                        'capacity' => 50,
                        'venue' => 'Online',
                        'status' => 'open',
                        'enrollment_form' => 'default',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '00:00', 'end' => '23:59', 'type' => SESSION_TYPE_ONLINE, 'title' => 'E-learning: Beweegbeleid (nieuw cohort)'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Module 1: Waarom een beweegbeleid?', 'content' => '<p>Het belang van een structureel beweegbeleid voor jongeren en hoe je draagvlak creëert.</p>'],
                    ['title' => 'Module 2: Van visie tot actieplan', 'content' => '<p>Stappen om een beweegvisie te formuleren en om te zetten in concrete acties.</p>'],
                    ['title' => 'Module 3: Evaluatie en borging', 'content' => '<p>Hoe meet je het effect van je beweegbeleid en hoe borg je het op lange termijn?</p>'],
                ],
            ],

            // === SHAKE-OUT: Closed online - minimal enrollment form ===
            [
                'title' => 'E-learning: Mindfulness voor Jongeren',
                'description' => 'Korte online module over mindfulness-technieken voor jongeren. Ademhalingsoefeningen, body scan en korte meditaties.',
                'type' => 'online',
                'format' => ['online', 'e-learning'],
                'themes' => ['welzijn'],
                'ld_price_type' => 'closed',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+1 day')),
                        'end_date' => date('Y-m-d', strtotime('+60 days')),
                        'price' => 45.00,
                        'price_non_member' => 55.00,
                        'capacity' => 0, // unlimited — tests capacity=0 edge case
                        'venue' => 'Online',
                        'status' => 'open',
                        'enrollment_form' => 'minimal',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '00:00', 'end' => '23:59', 'type' => SESSION_TYPE_ONLINE, 'title' => 'E-learning: Mindfulness (60 dagen toegang)'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Les 1: Wat is mindfulness?', 'content' => '<p>Introductie tot mindfulness en waarom het werkt voor jongeren.</p>'],
                    ['title' => 'Les 2: Ademhalingsoefeningen', 'content' => '<p>Drie eenvoudige ademhalingstechnieken voor in de klas.</p>'],
                    ['title' => 'Les 3: Body scan', 'content' => '<p>Geleide body scan oefening met audio-instructies.</p>'],
                ],
            ],

            // === SHAKE-OUT: Closed online - direct enrollment (no form) ===
            [
                'title' => 'E-learning: Snelle Update Jeugdsport',
                'description' => 'Korte opfrismodule over actuele richtlijnen jeugdsport. Geen formulier nodig, direct toegang na inschrijving.',
                'type' => 'online',
                'format' => ['online', 'e-learning'],
                'themes' => ['beweging'],
                'ld_price_type' => 'closed',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+1 day')),
                        'end_date' => date('Y-m-d', strtotime('+30 days')),
                        'price' => 25.00,
                        'price_non_member' => 35.00,
                        'capacity' => 500,
                        'venue' => 'Online',
                        'status' => 'open',
                        'enrollment_form' => 'direct',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '00:00', 'end' => '23:59', 'type' => SESSION_TYPE_ONLINE, 'title' => 'E-learning: Jeugdsport update (30 dagen toegang)'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Update 1: Nieuwe beweegrichtlijnen 2026', 'content' => '<p>Samenvatting van de herziene beweegrichtlijnen voor jongeren.</p>'],
                    ['title' => 'Update 2: Blessurepreventie checklist', 'content' => '<p>Praktische checklist voor blessurepreventie bij jeugdsport.</p>'],
                    ['title' => 'Update 3: Warmteprotocol', 'content' => '<p>Richtlijnen voor sporten bij hoge temperaturen.</p>'],
                ],
            ],

            // =========================================================================
            // INDEX 7-12: IN-PERSON COURSES (various configurations)
            // =========================================================================

            // === INDEX 5: Simple single-session course (2 editions in different cities) ===
            // First edition has post-course requirements for testing completion flow
            [
                'title' => 'Motiverende Gespreksvoering rond Voeding bij Jongeren',
                'description' => 'Leer hoe je jongeren op een niet-veroordelende manier motiveert om gezondere eetgewoontes te ontwikkelen. Met rollenspelen en praktijkcasussen. Erkend voor bijscholing.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['voeding', 'welzijn'],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('-2 weeks')), // Past date: sessions already happened
                        'price' => 295.00,
                        'price_non_member' => 345.00,
                        'capacity' => 16,
                        'venue' => 'BWEEG Opleidingscentrum Gent',
                        'speakers' => 'Lien De Smedt, sportpedagoge',
                        'status' => 'open',
                        'enrollment_form' => 'default',
                        'post_requires_evaluation' => true,
                        'post_requires_documents' => true,
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:30', 'end' => '16:30', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Motiverende gespreksvoering rond voeding'],
                        ],
                    ],
                    [
                        'start_date' => date('Y-m-d', strtotime('+6 weeks')),
                        'price' => 295.00,
                        'price_non_member' => 345.00,
                        'capacity' => 16,
                        'venue' => 'BWEEG Locatie Antwerpen',
                        'speakers' => 'Drs. Peter Willems',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:30', 'end' => '16:30', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Motiverende gespreksvoering rond voeding'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Theorie: Motiverende gespreksvoering', 'content' => 'Achtergrond en principes van motiverende gespreksvoering rond voeding.'],
                    ['title' => 'Praktijk: Rollenspelen en casussen', 'content' => 'Oefenen met gesprekstechnieken aan de hand van praktijkcasussen.'],
                ],
            ],

            // === INDEX 6: Multi-day intensive (3 consecutive days, spread over weeks) ===
            // First edition has post-course requirements including approval for testing
            [
                'title' => 'Sportblessures Voorkomen: van Warm-up tot Cool-down',
                'description' => 'Driedaagse evidence-based opleiding over blessurepreventie bij jongeren. Functionele screening, opwarming, cooling-down en return-to-play. Inclusief werkboek.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['sportblessures', 'beweging'],
                'editions' => [
                    // Edition 1: 3 days spread over 3 weeks (past dates for post-course testing)
                    [
                        'start_date' => date('Y-m-d', strtotime('-4 weeks')),
                        'price' => 695.00,
                        'price_non_member' => 795.00,
                        'capacity' => 12,
                        'venue' => 'Sportcentrum De Blaarmeersen, Gent',
                        'speakers' => 'Prof. dr. Jan Janssen, sportwetenschapper',
                        'status' => 'open',
                        'enrollment_form' => 'default',
                        'post_requires_evaluation' => true,
                        'post_requires_documents' => true,
                        'post_requires_approval' => true,
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 1: Functionele screening'],
                            ['date_offset' => 7, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 2: Blessurepreventie-oefeningen'],
                            ['date_offset' => 14, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 3: Return-to-play protocollen'],
                        ],
                    ],
                    // Edition 2: almost full
                    [
                        'start_date' => date('Y-m-d', strtotime('+2 months')),
                        'price' => 695.00,
                        'price_non_member' => 795.00,
                        'capacity' => 12,
                        'registered' => 10,
                        'venue' => 'BWEEG Opleidingscentrum Gent',
                        'speakers' => 'Dr. Els Peters, kinesitherapeut',
                        'status' => 'few_spots',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 1: Functionele screening'],
                            ['date_offset' => 7, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 2: Blessurepreventie-oefeningen'],
                            ['date_offset' => 14, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 3: Return-to-play protocollen'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Dag 1: Functionele screening', 'content' => 'Leer screeningsmethoden voor het identificeren van blessurerisico bij jongeren.'],
                    ['title' => 'Dag 2: Preventie-oefeningen', 'content' => 'Evidence-based opwarmings- en preventie-oefeningen voor verschillende sporten.'],
                    ['title' => 'Dag 3: Return-to-play', 'content' => 'Protocollen voor veilige terugkeer naar sport na een blessure.'],
                ],
            ],

            // === INDEX 7: Masterclass - sold out + future (single session, exclusive) ===
            [
                'title' => 'Masterclass Mentale Veerkracht bij Jonge Sporters',
                'description' => 'Exclusieve masterclass voor ervaren sportcoaches. Herken signalen van prestatiedruk en leer technieken om mentale weerbaarheid te versterken. Maximum 8 deelnemers.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['welzijn'],
                'editions' => [
                    // Full
                    [
                        'start_date' => date('Y-m-d', strtotime('+3 weeks')),
                        'price' => 450.00,
                        'price_non_member' => 525.00,
                        'capacity' => 8,
                        'registered' => 8,
                        'venue' => 'BWEEG Opleidingscentrum Gent',
                        'speakers' => 'Dr. Paul Verhaeghe, sportpsycholoog',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Masterclass Mentale Veerkracht'],
                        ],
                    ],
                    // Future
                    [
                        'start_date' => date('Y-m-d', strtotime('+3 months')),
                        'price' => 450.00,
                        'price_non_member' => 525.00,
                        'capacity' => 8,
                        'venue' => 'BWEEG Opleidingscentrum Gent',
                        'speakers' => 'Dr. Paul Verhaeghe, sportpsycholoog',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Masterclass Mentale Veerkracht'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Mentale veerkracht module', 'content' => 'Theorie en praktijk van mentale weerbaarheid bij jonge sporters.'],
                ],
            ],

            // === INDEX 8: Course with SESSION SLOTS + SELECTION DEADLINE ===
            // Users must choose between parallel sessions (morning/afternoon tracks)
            [
                'title' => 'Gezonde Tussendoortjes in de Jeugdwerking',
                'description' => 'Complete tweedaagse cursus over gezonde voeding in de jeugdwerking. Na de basiscursus kies je uit verdiepingsmodules voor specifieke doelgroepen.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['voeding', 'jeugdwerk'],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+5 weeks')),
                        'price' => 395.00,
                        'price_non_member' => 450.00,
                        'capacity' => 20,
                        'venue' => 'BWEEG Opleidingscentrum Gent',
                        'speakers' => 'Team BWEEG Voeding',
                        'status' => 'open',
                        'requires_session_selection' => true,
                        'selection_open' => true,
                        // Selection deadline: must choose sessions 1 week before start
                        'selection_deadline' => date('Y-m-d', strtotime('+4 weeks')),
                        // Session slots: define which sessions are alternatives
                        'session_slots' => json_encode([
                            [
                                'slot' => 'Verdieping (kies 1)',
                                'label' => 'Verdieping (kies 1)',
                                'required' => true,
                                'pick_count' => 1,
                            ],
                        ]),
                        'sessions' => [
                            // Mandatory day 1
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 1: Basisprincipes gezonde voeding', 'optional' => false],
                            // Mandatory day 2 morning
                            ['date_offset' => 1, 'start' => '09:00', 'end' => '12:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 2: Praktijk en recepten', 'optional' => false],
                            // SLOT: choose ONE of these afternoon sessions
                            ['date_offset' => 1, 'start' => '13:00', 'end' => '16:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Verdieping A: Sportvoeding', 'optional' => true, 'slot' => 'Verdieping (kies 1)'],
                            ['date_offset' => 1, 'start' => '13:00', 'end' => '16:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Verdieping B: Budget-vriendelijk koken', 'optional' => true, 'slot' => 'Verdieping (kies 1)'],
                            ['date_offset' => 1, 'start' => '13:00', 'end' => '16:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Verdieping C: Allergieen en intoleranties', 'optional' => true, 'slot' => 'Verdieping (kies 1)'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Gezonde voeding basis', 'content' => 'Basisprincipes van gezonde voeding in de jeugdwerking.'],
                    ['title' => 'Gezonde voeding praktijk', 'content' => 'Praktische toepassing: recepten en tussendoortjes.'],
                ],
            ],

            // === INDEX 9: Course with CANCELLED + RESCHEDULED editions ===
            [
                'title' => 'Workshop Yoga en Mindfulness voor Jongeren',
                'description' => 'Praktische workshop over yoga- en mindfulness-technieken voor jongeren. Geschikt voor alle begeleiders, geen ervaring vereist.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['welzijn', 'beweging'],
                'editions' => [
                    // Cancelled
                    [
                        'start_date' => date('Y-m-d', strtotime('+2 weeks')),
                        'price' => 195.00,
                        'price_non_member' => 225.00,
                        'capacity' => 15,
                        'venue' => 'Yogacentrum Gent',
                        'speakers' => 'Leen Smits, yogadocente',
                        'status' => 'cancelled',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '13:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Workshop Yoga en Mindfulness (GEANNULEERD)'],
                        ],
                    ],
                    // Rescheduled
                    [
                        'start_date' => date('Y-m-d', strtotime('+6 weeks')),
                        'price' => 195.00,
                        'price_non_member' => 225.00,
                        'capacity' => 15,
                        'venue' => 'Yogacentrum Gent',
                        'speakers' => 'Leen Smits, yogadocente',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '13:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Workshop Yoga en Mindfulness (nieuwe datum)'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Yoga en mindfulness workshop', 'content' => 'Praktische introductie in yoga en mindfulness voor jongeren.'],
                ],
            ],

            // === INDEX 10: Free single-session introductory course ===
            [
                'title' => 'Gratis Introductie: Werken bij BWEEG',
                'description' => 'Gratis kennismakingsochtend voor nieuwe medewerkers en stagiairs. Rondleiding en ontmoeting met collega\'s.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['beweging'],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+1 week')),
                        'price' => 0.00,
                        'price_non_member' => 0.00,
                        'capacity' => 30,
                        'venue' => 'BWEEG Hoofdkantoor Gent',
                        'speakers' => 'Diverse BWEEG medewerkers',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:30', 'end' => '12:30', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Introductie en rondleiding BWEEG'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Introductie BWEEG', 'content' => 'Kennismaking met BWEEG als organisatie.'],
                ],
            ],

            // =========================================================================
            // INDEX 11-12: IN-PERSON COURSES WITH COMPLETION REQUIREMENTS
            // =========================================================================

            // === INDEX 11: Questionnaire + Documents + Approval (full completion flow) ===
            [
                'title' => 'Erkenningstraject Jeugdsportcoach',
                'description' => 'Erkend opleidingstraject voor jeugdsportcoaches. Na inschrijving vul je een intake-vragenlijst in, upload je relevante diploma\'s, en wacht je op goedkeuring.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['beweging', 'sportblessures'],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+6 weeks')),
                        'price' => 895.00,
                        'price_non_member' => 995.00,
                        'capacity' => 12,
                        'venue' => 'BWEEG Opleidingscentrum Gent',
                        'speakers' => 'Prof. dr. An Vermeersch, Dr. Koen De Smet',
                        'status' => 'open',
                        'enrollment_form' => 'default',
                        'requires_questionnaire' => true,
                        'requires_documents' => true,
                        'requires_approval' => true,
                        'sessions' => [
                            ['date_offset' => 0,  'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 1: Intake en motorische screening'],
                            ['date_offset' => 7,  'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 2: Blessurepreventie en EHBO'],
                            ['date_offset' => 14, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 3: Begeleidingsvaardigheden'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Voorbereidingsmateriaal', 'content' => 'Lees dit document voor de eerste lesdag van het erkenningstraject.'],
                ],
            ],

            // === INDEX 12: Documents only (upload required, no approval) ===
            [
                'title' => 'Bijscholing Bewegingsonderwijs',
                'description' => 'Verplichte bijscholing voor leerkrachten LO. Upload je registratiebewijs bij inschrijving zodat we je accreditatie kunnen verwerken.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['schoolbeleid'],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+4 weeks')),
                        'price' => 175.00,
                        'price_non_member' => 225.00,
                        'capacity' => 25,
                        'venue' => 'BWEEG Locatie Antwerpen',
                        'speakers' => 'Drs. Sofie Claes',
                        'status' => 'open',
                        'enrollment_form' => 'default',
                        'requires_documents' => true,
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:30', 'end' => '16:30', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Bijscholing Bewegingsonderwijs'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Bijscholing module', 'content' => 'Actuele ontwikkelingen in het bewegingsonderwijs.'],
                ],
            ],

            // =========================================================================
            // INDEX 13: HYBRID COURSE (in-person + online + webinar sessions)
            // =========================================================================
            [
                'title' => 'Beweegbeleid op School: van Visie tot Actie',
                'description' => 'Hybride leertraject: klassikale dag + e-learning + live webinar + praktijkdag. Stappenplan om een actief beweegbeleid uit te bouwen op jouw school.',
                'type' => 'hybrid',
                'format' => ['klassikaal', 'hybrid'],
                'themes' => ['schoolbeleid', 'beweging'],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+4 weeks')),
                        'price' => 550.00,
                        'price_non_member' => 650.00,
                        'capacity' => 20,
                        'venue' => 'BWEEG Gent + Online',
                        'speakers' => 'Dr. Katrien Maes, onderwijspedagoge',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'location' => 'BWEEG Opleidingscentrum Gent', 'title' => 'Introductie beweegbeleid'],
                            ['date_offset' => 3, 'start' => '00:00', 'end' => '23:59', 'type' => SESSION_TYPE_ONLINE, 'title' => 'E-learning: Draagvlak en stakeholders'],
                            ['date_offset' => 7, 'start' => '14:00', 'end' => '16:00', 'type' => SESSION_TYPE_WEBINAR, 'title' => 'Live Q&A: Casusbespreking'],
                            ['date_offset' => 10, 'start' => '00:00', 'end' => '23:59', 'type' => SESSION_TYPE_ONLINE, 'title' => 'E-learning: Implementatie'],
                            ['date_offset' => 14, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'location' => 'BWEEG Opleidingscentrum Gent', 'title' => 'Praktijkdag: Evaluatie en bijsturing'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'E-learning Beweegbeleid Module 1', 'content' => 'Online module over draagvlak en stakeholders.'],
                    ['title' => 'E-learning Beweegbeleid Module 2', 'content' => 'Online module over implementatie en evaluatie.'],
                ],
            ],

            // =========================================================================
            // INDEX 14-15: WEBINAR COURSES
            // =========================================================================

            // === INDEX 14: Webinar series (4 weekly sessions) ===
            [
                'title' => 'Webinarreeks: Actuele Thema\'s in Jeugdgezondheid',
                'description' => 'Reeks van 4 interactieve webinars. Elke week een nieuw thema met een expert spreker. Inclusief opname en handouts.',
                'type' => 'webinar',
                'format' => ['webinar', 'online'],
                'themes' => ['welzijn', 'beweging'],
                'ld_price_type' => 'closed',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+1 week')),
                        'price' => 150.00,
                        'price_non_member' => 180.00,
                        'capacity' => 100,
                        'venue' => 'Online (Zoom)',
                        'speakers' => 'Diverse experts',
                        'status' => 'open',
                        'enrollment_form' => 'default',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '19:00', 'end' => '20:30', 'type' => SESSION_TYPE_WEBINAR, 'title' => 'Webinar 1: Schermtijd en bewegen'],
                            ['date_offset' => 7, 'start' => '19:00', 'end' => '20:30', 'type' => SESSION_TYPE_WEBINAR, 'title' => 'Webinar 2: Sportvoeding voor jongeren'],
                            ['date_offset' => 14, 'start' => '19:00', 'end' => '20:30', 'type' => SESSION_TYPE_WEBINAR, 'title' => 'Webinar 3: Blessurepreventie update'],
                            ['date_offset' => 21, 'start' => '19:00', 'end' => '20:30', 'type' => SESSION_TYPE_WEBINAR, 'title' => 'Webinar 4: Mentale veerkracht bij jongeren'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Webinar introductie', 'content' => 'Algemene informatie over de webinarreeks jeugdgezondheid.'],
                ],
            ],

            // === INDEX 15: Single free webinar ===
            [
                'title' => 'Gratis Webinar: Energy Drinks - De Nieuwe Trend',
                'description' => 'Gratis informatief webinar over energy drinks bij jongeren. Risico\'s, herkenning en gesprekstechnieken.',
                'type' => 'webinar',
                'format' => ['webinar', 'online'],
                'themes' => ['voeding', 'welzijn'],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+10 days')),
                        'price' => 0.00,
                        'price_non_member' => 0.00,
                        'capacity' => 200,
                        'venue' => 'Online (Teams)',
                        'speakers' => 'Dr. Sarah Janssen, voedingsdeskundige',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '12:00', 'end' => '13:00', 'type' => SESSION_TYPE_WEBINAR, 'title' => 'Lunchwebinar: Energy drinks - feiten en risico\'s'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Energy drinks info', 'content' => 'Informatie over energy drinks en de effecten bij jongeren.'],
                ],
            ],

            // =========================================================================
            // INDEX 16-20: TRAJECTORY COURSES (used in trajectories)
            // =========================================================================

            // === INDEX 16: Required foundation ===
            [
                'title' => 'Jeugdgezondheid: Fundament',
                'description' => 'Basiscursus voor iedereen die professioneel met jeugdgezondheid werkt. Verplicht onderdeel van het traject Jeugdgezondheidsspecialist.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['beweging', 'welzijn'],
                'trajectory_role' => 'required',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+2 weeks')),
                        'price' => 395.00,
                        'price_non_member' => 495.00,
                        'capacity' => 16,
                        'venue' => 'BWEEG Opleidingscentrum Gent',
                        'speakers' => 'Team BWEEG Academie',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 1: Basiskennis'],
                            ['date_offset' => 1, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 2: Gespreksvaardigheden'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Fundament module 1', 'content' => 'Basiskennis jeugdgezondheid.'],
                    ['title' => 'Fundament module 2', 'content' => 'Introductie gespreksvoering.'],
                ],
            ],

            // === INDEX 17: Required advanced ===
            [
                'title' => 'Assessment en Motorische Screening',
                'description' => 'Gestructureerd intake afnemen, motorische screeningsinstrumenten gebruiken, begeleidingsplan opstellen. Verplicht onderdeel traject.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['beweging', 'sportblessures'],
                'trajectory_role' => 'required',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+4 weeks')),
                        'price' => 395.00,
                        'price_non_member' => 495.00,
                        'capacity' => 14,
                        'venue' => 'BWEEG Opleidingscentrum Gent',
                        'speakers' => 'Dr. Tom Decorte',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Motorische screening en instrumenten'],
                            ['date_offset' => 14, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Begeleidingsplanning en doelen stellen'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Assessment theorie', 'content' => 'Motorische screeningsinstrumenten en intake.'],
                    ['title' => 'Screening praktijk', 'content' => 'Begeleidingsplanning.'],
                ],
            ],

            // === INDEX 18: Elective 1 ===
            [
                'title' => 'Keuzecursus: Familie en Gezondheidscoaching',
                'description' => 'Verdieping over het betrekken van het gezin bij gezondheidsbevordering. Keuzevak voor het traject Jeugdgezondheidsspecialist.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['welzijn', 'voeding'],
                'trajectory_role' => 'elective',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+8 weeks')),
                        'price' => 245.00,
                        'price_non_member' => 295.00,
                        'capacity' => 16,
                        'venue' => 'BWEEG Locatie Antwerpen',
                        'speakers' => 'Drs. Lies Verhoeven, gezondheidscoach',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Familie en gezondheidscoaching'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Gezondheidscoaching module', 'content' => 'Werken met het gezin rondom de jongere.'],
                ],
            ],

            // === INDEX 19: Elective 2 ===
            [
                'title' => 'Keuzecursus: Groepsdynamiek in Sportteams',
                'description' => 'Verdieping in groepsdynamiek bij jonge sportteams. Teamcohesie, conflicthantering en motivatietechnieken.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['beweging', 'welzijn'],
                'trajectory_role' => 'elective',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+9 weeks')),
                        'price' => 245.00,
                        'price_non_member' => 295.00,
                        'capacity' => 12,
                        'venue' => 'BWEEG Opleidingscentrum Gent',
                        'speakers' => 'Dr. Kris Goethals',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Groepsdynamiek in sportteams'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Groepsdynamiek', 'content' => 'Theorie en praktijk van groepsdynamiek in sportteams.'],
                ],
            ],

            // === INDEX 20: Elective 3 ===
            [
                'title' => 'Keuzecursus: Harm Reduction bij Eetproblemen',
                'description' => 'Verdieping in harm reduction benaderingen bij eetproblemen. Signalering, gespreksvoering en doorverwijzing.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['voeding', 'welzijn'],
                'trajectory_role' => 'elective',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+10 weeks')),
                        'price' => 195.00,
                        'price_non_member' => 245.00,
                        'capacity' => 20,
                        'venue' => 'BWEEG Hoofdkantoor Gent',
                        'speakers' => 'Peter Vander Laenen',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:30', 'end' => '16:30', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Harm reduction bij eetproblemen: theorie en praktijk'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Harm reduction module', 'content' => 'Principes en praktijk van schadebeperking bij eetproblemen.'],
                ],
            ],
        ];

        foreach ($courses as $courseData) {
            $this->createCourseWithEditions($courseData);
        }
    }

    private function createCourseWithEditions(array $courseData): void {
        // Check if exists
        $existing = get_page_by_title($courseData['title'], OBJECT, 'sfwd-courses');
        if ($existing) {
            echo "  - Course '{$courseData['title']}' exists\n";
            $this->created['courses'][] = $existing->ID;
            return;
        }

        // Create course
        $courseId = wp_insert_post([
            'post_title' => $courseData['title'],
            'post_type' => 'sfwd-courses',
            'post_status' => 'publish',
            'post_content' => $courseData['description'],
        ]);

        if (is_wp_error($courseId)) {
            echo "  ! Failed: {$courseId->get_error_message()}\n";
            return;
        }

        update_post_meta($courseId, self::SEED_META_KEY, true);
        update_post_meta($courseId, '_course_type', $courseData['type']);

        // --- LearnDash course settings ---
        $ldSettings = ['course_price_type' => $courseData['ld_price_type'] ?? 'open'];

        // Expiration settings
        if (!empty($courseData['ld_expire_access'])) {
            $ldSettings['expire_access'] = $courseData['ld_expire_access'];
            $ldSettings['expire_access_days'] = $courseData['ld_expire_access_days'] ?? 0;
            $ldSettings['expire_access_delete_progress'] = '';
        }

        update_post_meta($courseId, '_sfwd-courses', $ldSettings);

        // --- Assign stride_format taxonomy (used by /online/ and /klassikaal/ pages) ---
        $formats = $courseData['format'] ?? [];
        if (!empty($formats)) {
            wp_set_object_terms($courseId, $formats, 'stride_format');
        }

        // --- Assign stride_theme taxonomy (used for filtering tabs) ---
        $themes = $courseData['themes'] ?? [];
        if (!empty($themes)) {
            wp_set_object_terms($courseId, $themes, 'stride_theme');
        }

        // --- Assign ld_course_category (used by single-sfwd-courses.php for online detection) ---
        $ldCategory = match ($courseData['type']) {
            'online' => 'online',
            'webinar' => 'webinar',
            default => 'in-person',
        };
        wp_set_object_terms($courseId, $ldCategory, 'ld_course_category');

        $this->created['courses'][] = $courseId;
        echo "  + Course: {$courseData['title']} (ID: {$courseId}) [format: " . implode(',', $formats) . "] [themes: " . implode(',', $themes) . "]\n";

        // Create lessons
        $lessonIds = $this->createLessonsForCourse($courseId, $courseData['title'], $courseData['lessons'] ?? []);

        // Create editions (pass lessonIds so online sessions can link to them)
        foreach ($courseData['editions'] as $editionData) {
            $this->createEdition($courseId, $courseData['title'], $editionData, $lessonIds);
        }
    }

    private function createEdition(int $courseId, string $courseTitle, array $data, array $lessonIds = []): void {
        if (!$this->editionRepository) return;

        $postTitle = $courseTitle . ' - ' . date('j M Y', strtotime($data['start_date']));
        $endDate = $data['end_date'] ?? null;
        if (!$endDate && !empty($data['sessions'])) {
            $maxOffset = max(array_column($data['sessions'], 'date_offset'));
            $endDate = date('Y-m-d', strtotime($data['start_date'] . " +{$maxOffset} days"));
        }
        $endDate = $endDate ?? $data['start_date'];

        $result = $this->editionRepository->create([
            'title' => $postTitle,
            'post_status' => 'publish',
            'course_id' => $courseId,
            'start_date' => $data['start_date'],
            'end_date' => $endDate,
            'price' => $data['price'],
            'price_non_member' => $data['price_non_member'],
            'capacity' => $data['capacity'],
            'venue' => $data['venue'],
            'speakers' => $data['speakers'] ?? '',
            'status' => $data['status'],
        ]);

        if (is_wp_error($result)) {
            echo "    ! Edition failed: {$result->get_error_message()}\n";
            return;
        }

        $editionId = $result->ID;
        update_post_meta($editionId, self::SEED_META_KEY, true);

        // Set enrollment form if specified
        if (!empty($data['enrollment_form'])) {
            update_post_meta($editionId, '_ntdst_enrollment_form', $data['enrollment_form']);
        }

        // Set completion requirements if specified (enrollment phase)
        foreach (['requires_session_selection', 'requires_questionnaire', 'requires_documents', 'requires_approval'] as $reqKey) {
            if (!empty($data[$reqKey])) {
                update_post_meta($editionId, '_ntdst_' . $reqKey, '1');
            }
        }

        // Set post-course completion requirements if specified
        foreach (['post_requires_evaluation', 'post_requires_documents', 'post_requires_approval'] as $reqKey) {
            if (!empty($data[$reqKey])) {
                update_post_meta($editionId, '_ntdst_' . $reqKey, '1');
            }
        }

        // Set selection open/deadline if specified
        if (!empty($data['selection_open'])) {
            update_post_meta($editionId, '_ntdst_selection_open', '1');
        }
        if (!empty($data['selection_deadline'])) {
            update_post_meta($editionId, '_ntdst_selection_deadline', $data['selection_deadline']);
        }

        // Set session slots if specified
        if (!empty($data['session_slots'])) {
            update_post_meta($editionId, '_ntdst_session_slots', $data['session_slots']);
        }

        // Simulate registrations if specified
        // Uses high fake user_ids to avoid unique_user_edition constraint (no real user at these IDs)
        if (!empty($data['registered']) && $this->regRepo) {
            global $wpdb;
            for ($i = 0; $i < $data['registered']; $i++) {
                $fakeUserId = 900000 + ($editionId * 100) + $i + 1;
                $result = $wpdb->insert(
                    $wpdb->prefix . 'vad_registrations',
                    [
                        'user_id' => $fakeUserId,
                        'edition_id' => $editionId,
                        'status' => 'confirmed',
                        'enrollment_path' => 'individual',
                        'notes' => 'Seed placeholder',
                        'registered_at' => current_time('mysql'),
                    ]
                );
                if ($result === false) {
                    echo "    ! Fake registration insert failed: {$wpdb->last_error}\n";
                }
            }
        }

        $this->created['editions'][] = $editionId;
        echo "    + Edition: {$data['start_date']} at {$data['venue']} (ID: {$editionId})";
        if (!empty($data['enrollment_form'])) {
            echo " [form: {$data['enrollment_form']}]";
        }
        if (!empty($data['selection_deadline'])) {
            echo " [deadline: {$data['selection_deadline']}]";
        }
        echo "\n";

        // Create sessions (pass lessonIds for online session linking)
        $onlineLessonIndex = 0;
        foreach ($data['sessions'] as $sessionData) {
            $sessionLessonIds = [];
            if (($sessionData['type'] ?? '') === SESSION_TYPE_ONLINE && !empty($lessonIds)) {
                // Link each online session to the next available lesson
                if (isset($lessonIds[$onlineLessonIndex])) {
                    $sessionLessonIds = [$lessonIds[$onlineLessonIndex]];
                    $onlineLessonIndex++;
                }
            }
            $this->createSession($editionId, $data, $sessionData, $sessionLessonIds);
        }
    }

    private function createSession(int $editionId, array $editionData, array $sessionData, array $lessonIds = []): void {
        if (!$this->sessionService) return;

        $sessionDate = date('Y-m-d', strtotime($editionData['start_date'] . " +{$sessionData['date_offset']} days"));

        $createData = [
            'edition_id' => $editionId,
            'date' => $sessionDate,
            'start_time' => $sessionData['start'],
            'end_time' => $sessionData['end'],
            'type' => $sessionData['type'],
            'location' => $sessionData['location'] ?? $editionData['venue'],
            'optional' => $sessionData['optional'] ?? false,
        ];

        if (!empty($sessionData['title'])) {
            $createData['title'] = $sessionData['title'];
        }

        if (!empty($sessionData['slot'])) {
            $createData['slot'] = $sessionData['slot'];
        }

        $sessionId = $this->sessionService->createSession($createData);

        if (is_wp_error($sessionId)) {
            echo "      ! Session failed: {$sessionId->get_error_message()}\n";
            return;
        }

        update_post_meta($sessionId, self::SEED_META_KEY, true);

        // Link online sessions to LearnDash lessons
        if (!empty($lessonIds)) {
            update_post_meta($sessionId, '_ntdst_lesson_ids', $lessonIds);
            $lessonTitles = array_map(fn($id) => get_the_title($id), $lessonIds);
            echo "        🔗 Linked lessons: " . implode(', ', $lessonTitles) . "\n";
        }

        $this->created['sessions'][] = $sessionId;

        $typeEmoji = match($sessionData['type']) {
            SESSION_TYPE_ONLINE => '💻',
            SESSION_TYPE_WEBINAR => '📹',
            SESSION_TYPE_ASSIGNMENT => '📝',
            default => '🏢',
        };
        $title = $sessionData['title'] ?? $sessionData['type'];
        $slotInfo = !empty($sessionData['slot']) ? " [slot: {$sessionData['slot']}]" : '';
        echo "      {$typeEmoji} {$sessionDate} {$sessionData['start']}-{$sessionData['end']}: {$title}{$slotInfo}\n";
    }

    private function createLessonsForCourse(int $courseId, string $courseTitle, array $lessonData = []): array {
        $lessonIds = [];

        if (empty($lessonData)) {
            $lessonData = [
                ['title' => 'Introductie', 'content' => "Welkom bij deze cursus."],
                ['title' => 'Theorie', 'content' => "Theoretische basis."],
                ['title' => 'Praktijk', 'content' => "Oefeningen en casussen."],
                ['title' => 'Evaluatie', 'content' => "Toets en certificaat."],
            ];
        }

        foreach ($lessonData as $index => $lesson) {
            $lessonId = wp_insert_post([
                'post_title' => $lesson['title'],
                'post_type' => 'sfwd-lessons',
                'post_status' => 'publish',
                'post_content' => $lesson['content'],
                'menu_order' => $index + 1,
            ]);

            if (!is_wp_error($lessonId)) {
                update_post_meta($lessonId, self::SEED_META_KEY, true);
                update_post_meta($lessonId, 'course_id', $courseId);
                update_post_meta($lessonId, '_sfwd-lessons', ['sfwd-lessons_course' => $courseId]);
                $lessonIds[] = $lessonId;
                echo "      📖 Lesson: {$lesson['title']}\n";
            }
        }

        // Link lessons to course using LearnDash's expected structure
        if (!empty($lessonIds) && class_exists('LDLMS_Factory_Post')) {
            $courseStepsObj = LDLMS_Factory_Post::course_steps($courseId);
            if ($courseStepsObj) {
                $steps = ['sfwd-lessons' => []];
                foreach ($lessonIds as $lessonId) {
                    $steps['sfwd-lessons'][$lessonId] = [];
                }
                $courseStepsObj->set_steps($steps);
            }
        }

        return $lessonIds;
    }

    private function createTrajectories(): void {
        echo "\nCreating trajectories with opt-in courses...\n";

        if (!$this->trajectoryService) {
            echo "  ! TrajectoryService not available\n";
            return;
        }

        if (count($this->created['courses']) < 19) {
            echo "  ! Not enough courses for trajectories (need 19, have " . count($this->created['courses']) . ")\n";
            return;
        }

        $courseIds = $this->created['courses'];

        // Course index reference:
        // 0: E-learning Basiskennis Jeugdgezondheid (online open)
        // 1: E-learning Voeding en Prestatie (online open)
        // 2: E-learning Eetproblemen (online closed)
        // 3: E-learning Schermtijd en Bewegingsarmoede (online closed)
        // 4: E-learning Beweegbeleid (online closed)
        // 5: MGV rond Voeding (in-person)
        // 6: Sportblessures Voorkomen (in-person)
        // 7: Masterclass Mentale Veerkracht (in-person)
        // 8: Gezonde Tussendoortjes (in-person, slots)
        // 9: Workshop Yoga en Mindfulness (in-person, cancelled/rescheduled)
        // 10: Gratis Introductie BWEEG (in-person, free)
        // 11: Erkenningstraject Jeugdsportcoach (in-person, completion)
        // 12: Bijscholing Bewegingsonderwijs (in-person, documents)
        // 13: Beweegbeleid op School (hybrid)
        // 14: Webinarreeks Jeugdgezondheid (webinar)
        // 15: Gratis Webinar Energy Drinks (webinar, free)
        // 16: Jeugdgezondheid Fundament (trajectory required)
        // 17: Assessment en Motorische Screening (trajectory required)
        // 18: Familie en Gezondheidscoaching (trajectory elective)
        // 19: Groepsdynamiek in Sportteams (trajectory elective)
        // 20: Harm Reduction bij Eetproblemen (trajectory elective)

        // === TRAJECTORY 1: COHORT - Jeugdgezondheidsspecialist ===
        $cohortData = [
            'title' => 'Traject Jeugdgezondheidsspecialist',
            'post_content' => '<p>Word een gecertificeerd jeugdgezondheidsspecialist met dit intensieve cohort-traject.</p>
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
            'price' => 1695.00,
            'price_non_member' => 1995.00,
            'courses' => json_encode([
                ['course_id' => $courseIds[16], 'required' => true, 'order' => 1],
                ['course_id' => $courseIds[17], 'required' => true, 'order' => 2],
                ['course_id' => $courseIds[5], 'required' => true, 'order' => 3],
                ['course_id' => $courseIds[6], 'required' => true, 'order' => 4],
                ['course_id' => $courseIds[18], 'required' => false, 'group' => 'Keuzecursussen (kies 2)', 'min_choices' => 2],
                ['course_id' => $courseIds[19], 'required' => false, 'group' => 'Keuzecursussen (kies 2)', 'min_choices' => 2],
                ['course_id' => $courseIds[20], 'required' => false, 'group' => 'Keuzecursussen (kies 2)', 'min_choices' => 2],
            ]),
        ];

        $cohortId = $this->trajectoryService->createTrajectory($cohortData);
        if (!is_wp_error($cohortId)) {
            update_post_meta($cohortId, self::SEED_META_KEY, true);
            $this->created['trajectories'][] = $cohortId;
            echo "  + Cohort trajectory: Traject Jeugdgezondheidsspecialist (ID: {$cohortId})\n";
        } else {
            echo "  ! Cohort trajectory failed: {$cohortId->get_error_message()}\n";
        }

        // === TRAJECTORY 2: SELF-PACED - Jeugdgezondheidswerker ===
        $selfPacedData = [
            'title' => 'Traject Jeugdgezondheidswerker',
            'post_content' => '<p>Flexibel traject voor jeugdgezondheidswerkers. Combineer online cursussen met praktijkdagen, in je eigen tempo.</p>',
            'mode' => TrajectoryMode::SelfPaced->value,
            'status' => OfferingStatus::Open->value,
            'enrollment_deadline' => '',
            'capacity' => 0,
            'price' => 595.00,
            'price_non_member' => 745.00,
            'courses' => json_encode([
                ['course_id' => $courseIds[0], 'required' => true, 'order' => 1],
                ['course_id' => $courseIds[2], 'required' => true, 'order' => 2],
                ['course_id' => $courseIds[8], 'required' => true, 'order' => 3],
                ['course_id' => $courseIds[1], 'required' => false, 'group' => 'Optionele verdieping'],
                ['course_id' => $courseIds[14], 'required' => false, 'group' => 'Optionele verdieping'],
            ]),
        ];

        $selfPacedId = $this->trajectoryService->createTrajectory($selfPacedData);
        if (!is_wp_error($selfPacedId)) {
            update_post_meta($selfPacedId, self::SEED_META_KEY, true);
            $this->created['trajectories'][] = $selfPacedId;
            echo "  + Self-paced trajectory: Traject Jeugdgezondheidswerker (ID: {$selfPacedId})\n";
        } else {
            echo "  ! Self-paced trajectory failed: {$selfPacedId->get_error_message()}\n";
        }

        // === TRAJECTORY 3: BLENDED - Kennismakingstraject ===
        $blendedData = [
            'title' => 'Kennismakingstraject Jeugdgezondheid',
            'post_content' => '<p>Laagdrempelig kennismakingstraject. Combinatie van gratis en betaalde onderdelen.</p>',
            'mode' => TrajectoryMode::SelfPaced->value,
            'status' => OfferingStatus::Open->value,
            'enrollment_deadline' => '',
            'capacity' => 0,
            'price' => 0.00,
            'price_non_member' => 0.00,
            'courses' => json_encode([
                ['course_id' => $courseIds[10], 'required' => true, 'order' => 1],
                ['course_id' => $courseIds[15], 'required' => true, 'order' => 2],
                ['course_id' => $courseIds[0], 'required' => false, 'group' => 'Optionele verdieping (betaald)'],
                ['course_id' => $courseIds[5], 'required' => false, 'group' => 'Optionele verdieping (betaald)'],
            ]),
        ];

        $blendedId = $this->trajectoryService->createTrajectory($blendedData);
        if (!is_wp_error($blendedId)) {
            update_post_meta($blendedId, self::SEED_META_KEY, true);
            $this->created['trajectories'][] = $blendedId;
            echo "  + Entry trajectory: Kennismakingstraject (ID: {$blendedId})\n";
        } else {
            echo "  ! Entry trajectory failed: {$blendedId->get_error_message()}\n";
        }
    }

    private function createVouchers(): void {
        echo "\nCreating vouchers...\n";

        $voucherService = null;
        if (function_exists('ntdst_get') && class_exists(\Stride\Modules\Invoicing\VoucherService::class)) {
            $voucherService = ntdst_get(\Stride\Modules\Invoicing\VoucherService::class);
        }

        $vouchers = [
            ['code' => 'WELKOM2026', 'discount_type' => 'percentage', 'discount_value' => 10, 'usage_limit' => 50],
            ['code' => 'BWEEG-MEMBER', 'discount_type' => 'percentage', 'discount_value' => 15, 'usage_limit' => 100],
            ['code' => 'GRATIS-INTRO', 'discount_type' => 'full', 'discount_value' => 0, 'usage_limit' => 5],
            ['code' => 'KORTING50', 'discount_type' => 'fixed', 'discount_value' => 5000, 'usage_limit' => 20],
            ['code' => 'SEEDVOUCHER10', 'discount_type' => 'percentage', 'discount_value' => 10, 'usage_limit' => 100],
        ];

        foreach ($vouchers as $data) {
            if ($voucherService) {
                $existing = $voucherService->getVoucherByCode($data['code']);
                if ($existing) {
                    $this->created['vouchers'][] = $existing['id'];
                    echo "  - Voucher {$data['code']} exists\n";
                    continue;
                }

                $result = $voucherService->createVoucher($data);
                if (!is_wp_error($result)) {
                    update_post_meta($result, self::SEED_META_KEY, true);
                    $this->created['vouchers'][] = $result;
                    echo "  + Voucher: {$data['code']} (ID: {$result})\n";
                }
            } else {
                $voucherId = wp_insert_post([
                    'post_title' => $data['code'],
                    'post_type' => 'vad_voucher',
                    'post_status' => 'publish',
                ]);

                if (!is_wp_error($voucherId)) {
                    update_post_meta($voucherId, self::SEED_META_KEY, true);
                    update_post_meta($voucherId, 'code', $data['code']);
                    update_post_meta($voucherId, 'discount_type', $data['discount_type']);
                    update_post_meta($voucherId, 'discount_value', $data['discount_value']);
                    update_post_meta($voucherId, 'usage_limit', $data['usage_limit']);
                    update_post_meta($voucherId, 'used_count', 0);
                    update_post_meta($voucherId, 'status', 'active');
                    $this->created['vouchers'][] = $voucherId;
                    echo "  + Voucher: {$data['code']} (ID: {$voucherId})\n";
                }
            }
        }
    }

    private function createEnrollmentsAndQuotes(): void {
        echo "\nCreating enrollments and quotes for admin...\n";

        if (empty($this->created['editions'])) {
            echo "  ! No editions available\n";
            return;
        }

        // Get open editions (skip cancelled)
        $openEditions = [];
        foreach ($this->created['editions'] as $editionId) {
            $status = get_post_meta($editionId, '_ntdst_status', true);
            if (in_array($status, ['open', 'few_spots'], true)) {
                $openEditions[] = $editionId;
            }
        }

        if (empty($openEditions)) {
            echo "  ! No open editions\n";
            return;
        }

        // Enroll admin in first 3 open editions (in-person ones)
        $enrollEditions = array_slice($openEditions, 0, 3);

        foreach ($enrollEditions as $editionId) {
            if ($this->regRepo) {
                $regId = $this->regRepo->create([
                    'user_id' => $this->adminUserId,
                    'edition_id' => $editionId,
                    'status' => RegistrationStatus::Confirmed->value,
                    'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
                    'notes' => 'Seeded for admin testing',
                ]);

                if (!is_wp_error($regId)) {
                    $this->created['registrations'][] = $regId;
                    echo "  + Registration for edition {$editionId}\n";

                    // Grant LearnDash access
                    if ($this->editionService && function_exists('ld_update_course_access')) {
                        $courseId = $this->editionService->getCourseId($editionId);
                        if ($courseId) {
                            ld_update_course_access($this->adminUserId, $courseId, false);
                        }
                    }

                    // Initialize completion_tasks if edition has requirements
                    $completion = ntdst_get(EnrollmentCompletion::class);
                    $tasks = $completion->buildInitialTasks($editionId, 'vad_edition');
                    if (!empty($tasks)) {
                        global $wpdb;
                        $wpdb->update(
                            $wpdb->prefix . 'vad_registrations',
                            ['completion_tasks' => wp_json_encode($tasks)],
                            ['id' => $regId]
                        );
                        echo "    + Completion tasks: " . implode(', ', array_keys($tasks)) . "\n";
                    }
                }
            }

            // Create quote
            $this->createQuoteForEdition($editionId, $this->adminUserId);
        }

        // === Enroll admin in editions that have enrollment requirements (for task testing) ===
        foreach ($openEditions as $editionId) {
            $reqS = get_post_meta($editionId, '_ntdst_requires_session_selection', true);
            $reqQ = get_post_meta($editionId, '_ntdst_requires_questionnaire', true);
            $reqD = get_post_meta($editionId, '_ntdst_requires_documents', true);
            $reqA = get_post_meta($editionId, '_ntdst_requires_approval', true);

            if (!$reqS && !$reqQ && !$reqD && !$reqA) {
                continue;
            }

            if ($this->regRepo && !$this->regRepo->exists($this->adminUserId, $editionId)) {
                $regId = $this->regRepo->create([
                    'user_id' => $this->adminUserId,
                    'edition_id' => $editionId,
                    'status' => RegistrationStatus::Pending->value,
                    'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
                    'notes' => 'Seeded: edition with enrollment requirements',
                ]);

                if (!is_wp_error($regId)) {
                    $this->created['registrations'][] = $regId;

                    $completion = ntdst_get(EnrollmentCompletion::class);
                    $tasks = $completion->buildInitialTasks($editionId, 'vad_edition');
                    if (!empty($tasks)) {
                        global $wpdb;
                        $wpdb->update(
                            $wpdb->prefix . 'vad_registrations',
                            ['completion_tasks' => wp_json_encode($tasks)],
                            ['id' => $regId]
                        );
                        echo "  + Registration for edition {$editionId} with tasks: " . implode(', ', array_keys($tasks)) . "\n";
                    }

                    if ($this->editionService && function_exists('ld_update_course_access')) {
                        $courseId = $this->editionService->getCourseId($editionId);
                        if ($courseId) {
                            ld_update_course_access($this->adminUserId, $courseId, false);
                        }
                    }
                }
            }
        }

        // === Enroll admin in some ONLINE open courses (LD native) ===
        // This tests the "enrolled" state on the online archive page
        $onlineOpenCourseIds = array_slice($this->created['courses'], 0, 2); // First 2 are online open
        foreach ($onlineOpenCourseIds as $courseId) {
            if (function_exists('ld_update_course_access')) {
                ld_update_course_access($this->adminUserId, $courseId, false);
                echo "  + LD enrollment: admin -> course {$courseId} (online open)\n";
            }
        }

        // === Enroll admin in hybrid course (INDEX 13) to test online lesson actions ===
        // The hybrid course has online sessions linked to lessons
        if (isset($this->created['courses'][13])) {
            $hybridCourseId = $this->created['courses'][13];
            // Find the edition for this course
            $hybridEditions = array_filter($openEditions, function ($editionId) use ($hybridCourseId) {
                return (int) get_post_meta($editionId, '_ntdst_course_id', true) === $hybridCourseId;
            });

            foreach ($hybridEditions as $editionId) {
                if ($this->regRepo && !$this->regRepo->exists($this->adminUserId, $editionId)) {
                    $regId = $this->regRepo->create([
                        'user_id' => $this->adminUserId,
                        'edition_id' => $editionId,
                        'status' => RegistrationStatus::Confirmed->value,
                        'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
                        'notes' => 'Seeded: hybrid course for online lesson testing',
                    ]);

                    if (!is_wp_error($regId)) {
                        $this->created['registrations'][] = $regId;
                        echo "  + Registration for hybrid edition {$editionId} (online lesson testing)\n";

                        if (function_exists('ld_update_course_access')) {
                            ld_update_course_access($this->adminUserId, $hybridCourseId, false);
                            echo "    + Granted LD access to hybrid course {$hybridCourseId}\n";
                        }
                    }
                }
            }
        }

        // Enroll seed students
        foreach ($this->created['users'] as $userId) {
            $user = get_user_by('ID', $userId);
            if (!$user || strpos($user->user_login, 'seed_student') === false) {
                continue;
            }

            // Enroll in 1-2 random editions
            $randomEditions = array_rand(array_flip($openEditions), min(2, count($openEditions)));
            if (!is_array($randomEditions)) $randomEditions = [$randomEditions];

            foreach ($randomEditions as $editionId) {
                if ($this->regRepo) {
                    // Check if edition has enrollment requirements → status=pending
                    $hasEnrollmentReqs = get_post_meta($editionId, '_ntdst_requires_session_selection', true)
                        || get_post_meta($editionId, '_ntdst_requires_questionnaire', true)
                        || get_post_meta($editionId, '_ntdst_requires_documents', true)
                        || get_post_meta($editionId, '_ntdst_requires_approval', true);

                    $regId = $this->regRepo->create([
                        'user_id' => $userId,
                        'edition_id' => $editionId,
                        'status' => $hasEnrollmentReqs
                            ? RegistrationStatus::Pending->value
                            : RegistrationStatus::Confirmed->value,
                        'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
                    ]);

                    if (!is_wp_error($regId)) {
                        $this->created['registrations'][] = $regId;

                        // Initialize completion tasks if edition has requirements
                        $completion = ntdst_get(EnrollmentCompletion::class);
                        $tasks = $completion->buildInitialTasks($editionId, 'vad_edition');
                        if (!empty($tasks)) {
                            global $wpdb;
                            $wpdb->update(
                                $wpdb->prefix . 'vad_registrations',
                                ['completion_tasks' => wp_json_encode($tasks)],
                                ['id' => $regId]
                            );
                            echo "  + Registration: {$user->user_login} -> edition {$editionId} (pending, tasks: " . implode(', ', array_keys($tasks)) . ")\n";
                        } else {
                            echo "  + Registration: {$user->user_login} -> edition {$editionId}\n";
                        }
                    }
                }

                $this->createQuoteForEdition($editionId, $userId);
            }
        }
    }

    private function createQuoteForEdition(int $editionId, int $userId): void {
        $user = get_user_by('ID', $userId);
        $price = (float) (get_post_meta($editionId, '_ntdst_price', true) ?: 299.00);
        $tax = round($price * 0.21, 2);

        $quoteNumber = sprintf('Q-%04d', rand(1000, 9999));
        $statuses = ['draft', 'sent', 'exported'];
        $status = $statuses[array_rand($statuses)];

        $quoteId = wp_insert_post([
            'post_title' => "Quote {$quoteNumber} - {$user->display_name}",
            'post_type' => 'vad_quote',
            'post_status' => 'publish',
        ]);

        if (is_wp_error($quoteId)) {
            return;
        }

        update_post_meta($quoteId, self::SEED_META_KEY, true);
        update_post_meta($quoteId, 'quote_number', $quoteNumber);
        update_post_meta($quoteId, 'user_id', $userId);
        update_post_meta($quoteId, 'edition_id', $editionId);
        update_post_meta($quoteId, 'status', $status);
        update_post_meta($quoteId, 'subtotal', (int) ($price * 100));
        update_post_meta($quoteId, 'tax', (int) ($tax * 100));
        update_post_meta($quoteId, 'total', (int) (($price + $tax) * 100));
        update_post_meta($quoteId, 'valid_until', date('Y-m-d', strtotime('+30 days')));

        $items = [[
            'type' => 'edition',
            'id' => $editionId,
            'title' => get_the_title($editionId),
            'unit_price' => (int) ($price * 100),
            'quantity' => 1,
            'total' => (int) ($price * 100),
        ]];
        update_post_meta($quoteId, 'items', wp_json_encode($items));

        $billing = [
            'name' => $user->display_name,
            'email' => $user->user_email,
        ];
        update_post_meta($quoteId, 'billing', wp_json_encode($billing));

        if ($status !== 'draft') {
            update_post_meta($quoteId, 'sent_at', current_time('mysql'));
        }
        if ($status === 'exported') {
            update_post_meta($quoteId, 'locked', '1');
        }

        $this->created['quotes'][] = $quoteId;
        echo "  + Quote: {$quoteNumber} ({$status})\n";
    }

    // === Post-Course Completion Test Data ===

    /**
     * Create post-course completion test data.
     *
     * Finds editions with post-course requirements, creates confirmed registrations
     * with attendance marked, and initializes post-course tasks so the completion
     * flow can be tested immediately.
     */
    private function createPostCourseTestData(): void {
        echo "\nCreating post-course completion test data...\n";

        if (empty($this->created['editions'])) {
            echo "  ! No editions available\n";
            return;
        }

        // Find seed_student1
        $student = get_user_by('login', 'seed_student1');
        if (!$student) {
            echo "  ! seed_student1 not found\n";
            return;
        }

        // Find editions with post-course requirements
        $postCourseEditions = [];
        foreach ($this->created['editions'] as $editionId) {
            $hasEval = get_post_meta($editionId, '_ntdst_post_requires_evaluation', true);
            $hasDocs = get_post_meta($editionId, '_ntdst_post_requires_documents', true);
            $hasApproval = get_post_meta($editionId, '_ntdst_post_requires_approval', true);

            if ($hasEval || $hasDocs || $hasApproval) {
                $postCourseEditions[] = $editionId;
            }
        }

        if (empty($postCourseEditions)) {
            echo "  ! No editions with post-course requirements found\n";
            return;
        }

        echo "  Found " . count($postCourseEditions) . " editions with post-course requirements\n";

        // Ensure evaluation field groups are assigned to post-course edition courses
        if (class_exists(\Stride\Modules\Questionnaire\QuestionnaireRepository::class)) {
            $questionnaireRepo = ntdst_get(\Stride\Modules\Questionnaire\QuestionnaireRepository::class);
            $groups = $questionnaireRepo->getAllGroups();

            // Find or create an evaluation field group
            $evalGroup = null;
            foreach ($groups as &$g) {
                if (($g['stage'] ?? '') === 'evaluation') {
                    $evalGroup = &$g;
                    break;
                }
            }
            unset($g);

            if (!$evalGroup) {
                $groups[] = [
                    'id' => 'qg_eval_seed',
                    'label' => 'Evaluatie opleiding',
                    'stage' => 'evaluation',
                    'assignments' => [],
                    'fields' => [
                        ['label' => 'Beoordeling docent', 'name' => 'beoordeling_docent', 'type' => 'scale', 'required' => true, 'min' => 1, 'max' => 5],
                        ['label' => 'Beoordeling lesmateriaal', 'name' => 'beoordeling_materiaal', 'type' => 'scale', 'required' => true, 'min' => 1, 'max' => 5],
                        ['label' => 'Opmerkingen', 'name' => 'opmerkingen', 'type' => 'textarea', 'required' => false],
                    ],
                ];
                $evalGroup = &$groups[count($groups) - 1];
            }

            // Assign to courses linked to post-course editions
            $editionService = $this->editionService;
            $courseIds = [];
            foreach ($postCourseEditions as $editionId) {
                $courseId = $editionService ? $editionService->getCourseId($editionId) : 0;
                if ($courseId) {
                    $courseIds[] = $courseId;
                }
            }
            $courseIds = array_unique($courseIds);

            // Merge into existing assignments (flat int IDs, matching admin settings format)
            $existing = $evalGroup['assignments'] ?? [];
            foreach ($courseIds as $cid) {
                if (!in_array($cid, $existing, true)) {
                    $evalGroup['assignments'][] = $cid;
                }
            }

            $questionnaireRepo->saveGroups($groups);
            echo "  + Evaluation field group assigned to courses: " . implode(', ', $courseIds) . "\n";
            unset($evalGroup);
        }

        global $wpdb;
        $attendanceTable = $wpdb->prefix . 'vad_attendance';

        foreach ($postCourseEditions as $editionId) {
            $editionTitle = get_the_title($editionId);

            // Create confirmed registration for seed_student1
            if ($this->regRepo) {
                // Check if already enrolled
                $existing = $this->regRepo->findByUserAndEdition($student->ID, $editionId);
                if ($existing && $existing->status !== 'cancelled') {
                    $regId = (int) $existing->id;
                    echo "  - Registration exists for student1 -> edition {$editionId} (reg: {$regId})\n";
                } else {
                    $regId = $this->regRepo->create([
                        'user_id' => $student->ID,
                        'edition_id' => $editionId,
                        'status' => RegistrationStatus::Confirmed->value,
                        'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
                        'notes' => 'Seeded for post-course completion testing',
                    ]);

                    if (is_wp_error($regId)) {
                        echo "  ! Registration failed for edition {$editionId}: {$regId->get_error_message()}\n";
                        continue;
                    }

                    $this->created['registrations'][] = $regId;
                    echo "  + Registration: student1 -> {$editionTitle} (reg: {$regId})\n";
                }

                // Grant LearnDash access
                if ($this->editionService && function_exists('ld_update_course_access')) {
                    $courseId = $this->editionService->getCourseId($editionId);
                    if ($courseId) {
                        ld_update_course_access($student->ID, $courseId, false);
                        echo "    + LD access granted for course {$courseId}\n";
                    }
                }

                // Mark attendance for all sessions
                $sessions = get_posts([
                    'post_type' => 'vad_session',
                    'post_status' => 'publish',
                    'posts_per_page' => -1,
                    'meta_query' => [
                        ['key' => '_ntdst_edition_id', 'value' => $editionId, 'compare' => '='],
                    ],
                ]);

                foreach ($sessions as $session) {
                    // Check if attendance already exists
                    $existingAttendance = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$attendanceTable} WHERE session_id = %d AND user_id = %d",
                        $session->ID,
                        $student->ID
                    ));

                    if (!$existingAttendance) {
                        $wpdb->insert($attendanceTable, [
                            'edition_id' => $editionId,
                            'session_id' => $session->ID,
                            'user_id' => $student->ID,
                            'status' => 'present',
                            'marked_by' => $this->adminUserId,
                            'marked_at' => current_time('mysql'),
                        ]);
                        echo "    + Attendance: session {$session->ID} (present)\n";
                    } else {
                        echo "    - Attendance exists: session {$session->ID}\n";
                    }
                }

                // Initialize post-course tasks
                if (function_exists('ntdst_get') && class_exists(EnrollmentCompletion::class)) {
                    $completion = ntdst_get(EnrollmentCompletion::class);
                    $completion->initializePostCourseTasks($regId, $editionId);

                    $reqs = $completion->getPostCourseRequirements($editionId, 'vad_edition');
                    $enabledTasks = array_keys(array_filter($reqs));
                    echo "    + Post-course tasks initialized: " . implode(', ', $enabledTasks) . "\n";
                } else {
                    // Fallback: set completion_tasks directly via wpdb
                    $tasks = [];
                    if (get_post_meta($editionId, '_ntdst_post_requires_evaluation', true)) {
                        $tasks['post_evaluation'] = ['status' => 'pending', 'phase' => 'post_course'];
                    }
                    if (get_post_meta($editionId, '_ntdst_post_requires_documents', true)) {
                        $tasks['post_documents'] = ['status' => 'pending', 'phase' => 'post_course'];
                    }
                    if (get_post_meta($editionId, '_ntdst_post_requires_approval', true)) {
                        $tasks['post_approval'] = ['status' => 'pending', 'phase' => 'post_course'];
                    }

                    if (!empty($tasks)) {
                        $wpdb->update(
                            $wpdb->prefix . 'vad_registrations',
                            ['completion_tasks' => wp_json_encode($tasks)],
                            ['id' => $regId]
                        );
                        echo "    + Post-course tasks set via fallback: " . implode(', ', array_keys($tasks)) . "\n";
                    }
                }
            }
        }

        echo "Post-course completion test data complete.\n";
    }

    /**
     * Create trajectory-specific test data for E2E tests.
     */
    private function createTrajectoryTestData(): void {
        echo "\nCreating trajectory test data for E2E tests...\n";

        $testTrajectory = get_page_by_path('test-trajectory', OBJECT, 'vad_trajectory');
        if (!$testTrajectory) {
            $testTrajectoryId = wp_insert_post([
                'post_type' => 'vad_trajectory',
                'post_title' => 'Test Trajectory',
                'post_name' => 'test-trajectory',
                'post_status' => 'publish',
                'post_content' => 'A test trajectory for E2E testing.',
            ]);

            if (is_wp_error($testTrajectoryId)) {
                echo "  ! Failed to create test-trajectory: {$testTrajectoryId->get_error_message()}\n";
                return;
            }

            if (function_exists('ntdst_data')) {
                $trajectoryModel = ntdst_data()->get('vad_trajectory');
                if ($trajectoryModel) {
                    $trajectoryModel->updateMetaBatch($testTrajectoryId, [
                        'mode' => TrajectoryMode::Cohort->value,
                        'status' => OfferingStatus::Open->value,
                        'price' => 500,
                        'price_non_member' => 600,
                        'enrollment_deadline' => date('Y-m-d', strtotime('+30 days')),
                        'capacity' => 20,
                    ]);
                }
            } else {
                update_post_meta($testTrajectoryId, '_ntdst_mode', TrajectoryMode::Cohort->value);
                update_post_meta($testTrajectoryId, '_ntdst_status', OfferingStatus::Open->value);
                update_post_meta($testTrajectoryId, '_ntdst_price', 500);
                update_post_meta($testTrajectoryId, '_ntdst_price_non_member', 600);
                update_post_meta($testTrajectoryId, '_ntdst_enrollment_deadline', date('Y-m-d', strtotime('+30 days')));
                update_post_meta($testTrajectoryId, '_ntdst_capacity', 20);
            }

            update_post_meta($testTrajectoryId, self::SEED_META_KEY, true);
            $this->created['trajectories'][] = $testTrajectoryId;
            echo "  + Created test-trajectory (ID: {$testTrajectoryId})\n";
        } else {
            $testTrajectoryId = $testTrajectory->ID;
            if (!in_array($testTrajectoryId, $this->created['trajectories'], true)) {
                $this->created['trajectories'][] = $testTrajectoryId;
            }
            echo "  - test-trajectory already exists (ID: {$testTrajectoryId})\n";
        }

        // Create enrolled test user
        $this->createTestUser('seed_enrolled_user', 'seed_enrolled_user@seed.test', 'Enrolled', 'User');
        // Create completed test user
        $this->createTestUser('seed_completed_user', 'seed_completed_user@seed.test', 'Completed', 'User');

        echo "Trajectory test data complete.\n";
    }

    private function createTestUser(string $login, string $email, string $first, string $last): void {
        $existing = get_user_by('email', $email);
        if ($existing) {
            if (!in_array($existing->ID, $this->created['users'], true)) {
                $this->created['users'][] = $existing->ID;
            }
            if (!get_user_meta($existing->ID, 'ntdst_auth_activated', true)) {
                update_user_meta($existing->ID, 'ntdst_auth_activated', true);
                update_user_meta($existing->ID, 'ntdst_auth_activated_at', time());
            }
            echo "  - {$email} already exists (ID: {$existing->ID})\n";
            return;
        }

        $userId = wp_insert_user([
            'user_login' => $login,
            'user_email' => $email,
            'user_pass' => 'seedpass123',
            'first_name' => $first,
            'last_name' => $last,
            'display_name' => "{$first} {$last}",
            'role' => 'subscriber',
        ]);

        if (is_wp_error($userId)) {
            echo "  ! Failed to create {$login}: {$userId->get_error_message()}\n";
            return;
        }

        update_user_meta($userId, self::SEED_META_KEY, true);
        update_user_meta($userId, 'ntdst_auth_activated', true);
        update_user_meta($userId, 'ntdst_auth_activated_at', time());
        $this->created['users'][] = $userId;
        echo "  + Created {$email} (ID: {$userId})\n";
    }

    private function saveSeedManifest(): void {
        update_option('stride_seed_manifest', $this->created);
        update_option('stride_seed_timestamp', current_time('mysql'));
    }

    private function seedCompanyDetails(): void {
        // =========================================================================
        // Company Details
        // =========================================================================
        echo "\n--- Company Details ---\n";

        $companyDetails = [
            'name'         => 'BWEEG vzw',
            'address'      => 'Sportstraat 42',
            'postal_code'  => '9000',
            'city'         => 'Gent',
            'country'      => 'België',
            'vat'          => 'BE0420.798.935',
            'email'        => 'info@bweeg.be',
            'phone'        => '+32 9 234 56 78',
            'bank_account' => 'BE68 0682 0553 5765',
        ];

        update_option('stride_company_details', $companyDetails);
        echo "  - Company details seeded: {$companyDetails['name']}\n";
    }

    private function printSummary(): void {
        echo "\n=== Seed Complete ===\n\n";
        echo "Created:\n";
        echo "  - Users: " . count($this->created['users']) . "\n";
        echo "  - Courses: " . count($this->created['courses']) . "\n";
        echo "  - Editions: " . count($this->created['editions']) . "\n";
        echo "  - Sessions: " . count($this->created['sessions']) . "\n";
        echo "  - Trajectories: " . count($this->created['trajectories']) . "\n";
        echo "  - Registrations: " . count($this->created['registrations']) . "\n";
        echo "  - Vouchers: " . count($this->created['vouchers']) . "\n";
        echo "  - Quotes: " . count($this->created['quotes']) . "\n";
        echo "\n";

        echo "=== Course Types ===\n";
        echo "  ONLINE (5 total):\n";
        echo "    Open (LD native enrollment):\n";
        echo "      - E-learning: Basiskennis Jeugdgezondheid (free, open)\n";
        echo "      - E-learning: Voeding en Prestatie bij Jonge Sporters (free, 90-day expiry)\n";
        echo "    Closed (Stride enrollment form + sessions):\n";
        echo "      - E-learning: Eetproblemen Herkennen en Bespreekbaar Maken (€75, form)\n";
        echo "      - E-learning: Schermtijd en Bewegingsarmoede (€65, form)\n";
        echo "      - E-learning: Beweegbeleid Ontwikkelen (€125, full + new cohort)\n";
        echo "\n";

        echo "  IN-PERSON (6 total, various configs):\n";
        echo "    - MGV rond Voeding (2 editions: Gent + Antwerpen, single day)\n";
        echo "    - Sportblessures Voorkomen (2 editions, 3-day spread over weeks, 1 almost full)\n";
        echo "    - Masterclass Mentale Veerkracht (1 FULL, 1 open, single session)\n";
        echo "    - Gezonde Tussendoortjes (session SLOTS + selection deadline)\n";
        echo "    - Workshop Yoga en Mindfulness (1 cancelled, 1 rescheduled)\n";
        echo "    - Gratis Introductie BWEEG (free, single morning session)\n";
        echo "\n";

        echo "  HYBRID (1 total):\n";
        echo "    - Beweegbeleid op School (klassikaal + e-learning + webinar mixed sessions)\n";
        echo "\n";

        echo "  WEBINAR (2 total):\n";
        echo "    - Webinarreeks: Actuele Thema's in Jeugdgezondheid (4 weekly sessions)\n";
        echo "    - Gratis Webinar: Energy Drinks (free, single lunchtime session)\n";
        echo "\n";

        echo "  TRAJECTORY COURSES (5 total):\n";
        echo "    Required: Fundament, Assessment en Motorische Screening\n";
        echo "    Electives: Familie en Gezondheidscoaching, Groepsdynamiek in Sportteams, Harm Reduction bij Eetproblemen\n";
        echo "\n";

        echo "=== Trajectories ===\n";
        echo "  1. Traject Jeugdgezondheidsspecialist (cohort, 4 required + choose 2 of 3)\n";
        echo "  2. Traject Jeugdgezondheidswerker (self-paced, 3 required + 2 optional)\n";
        echo "  3. Kennismakingstraject Jeugdgezondheid (free entry, 2 required + 2 optional)\n";
        echo "\n";

        echo "=== Post-Course Completion ===\n";
        echo "  - MGV rond Voeding (Gent): post_evaluation + post_documents\n";
        echo "  - Sportblessures Voorkomen (Blaarmeersen): post_evaluation + post_documents + post_approval\n";
        echo "  - seed_student1 enrolled + attendance marked + post-course tasks initialized\n";
        echo "\n";

        echo "=== Admin Enrollments ===\n";
        echo "  - Enrolled in first 3 open editions (in-person)\n";
        echo "  - Enrolled in 2 online open courses (LD native)\n";
        echo "\n";

        echo "=== Test Credentials ===\n";
        echo "  Password for all seed users: seedpass123\n";
        echo "  - seed_admin@seed.test (admin)\n";
        echo "  - instructor@seed.test (group_leader)\n";
        echo "  - student1@seed.test, student2@seed.test, student3@seed.test\n";
        echo "\n";

        echo "=== Voucher Codes ===\n";
        echo "  - WELKOM2026 (10% off)\n";
        echo "  - BWEEG-MEMBER (15% off)\n";
        echo "  - GRATIS-INTRO (100% off)\n";
        echo "  - KORTING50 (€50 off)\n";
        echo "  - SEEDVOUCHER10 (10% off)\n";
        echo "\n";

        echo "To cleanup: ddev exec bash -c 'FORCE_UNSEED=1 wp eval-file scripts/unseed.php'\n";
    }
}

// Run
$seeder = new StrideSeedData();
$seeder->run();
