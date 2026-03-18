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
            'verslaving'    => 'Verslaving',
            'preventie'     => 'Preventie',
            'behandeling'   => 'Behandeling',
            'jongeren'      => 'Jongeren',
            'alcohol'       => 'Alcohol & Drugs',
            'methodiek'     => 'Methodiek',
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
                'title' => 'E-learning: Basiskennis Verslavingszorg',
                'description' => 'Deze uitgebreide online cursus biedt een stevige basis in de verslavingszorg. Je leert over de verschillende soorten verslavingen, de biologische en psychologische mechanismen, en de belangrijkste behandelmethoden.',
                'type' => 'online',
                'format' => ['online', 'e-learning'],
                'themes' => ['verslaving', 'behandeling'],
                'ld_price_type' => 'open', // free, no restrictions
                'editions' => [],
                'lessons' => [
                    ['title' => 'Module 1: Wat is verslaving?', 'content' => '<h3>Definitie en Kenmerken</h3><p>In deze eerste module verkennen we wat verslaving precies inhoudt. We bekijken de DSM-5 criteria.</p>'],
                    ['title' => 'Module 2: Het verslaafde brein', 'content' => '<h3>Neurobiologie van Verslaving</h3><p>Verslaving is een hersenziekte. Leer hoe verslavende stoffen het beloningssysteem beïnvloeden.</p>'],
                    ['title' => 'Module 3: Soorten verslavingen', 'content' => '<h3>Middelen en Gedragsverslavingen</h3><p>Overzicht van de verschillende types verslavingen.</p>'],
                    ['title' => 'Module 4: Behandelmethoden', 'content' => '<h3>Evidence-Based Behandelingen</h3><p>CGT, motiverende gespreksvoering, en farmacologische opties.</p>'],
                    ['title' => 'Module 5: Eindtoets', 'content' => '<h3>Toets je Kennis</h3><p>25 meerkeuzevragen. Minimaal 70% om te slagen.</p>'],
                ],
            ],

            // === INDEX 1: Open online - alcohol course ===
            [
                'title' => 'E-learning: Alcohol en Gezondheid',
                'description' => 'Verdiepende online module over alle aspecten van alcohol. Van de werking op het lichaam tot de sociale impact.',
                'type' => 'online',
                'format' => ['online', 'e-learning'],
                'themes' => ['alcohol', 'preventie'],
                'ld_price_type' => 'open',
                'ld_expire_access' => 'on',
                'ld_expire_access_days' => 90,
                'editions' => [],
                'lessons' => [
                    ['title' => 'Hoofdstuk 1: Alcohol in cijfers', 'content' => '<h3>De situatie</h3><p>België staat in de Europese top qua alcoholconsumptie.</p>'],
                    ['title' => 'Hoofdstuk 2: Effecten op lichaam en geest', 'content' => '<h3>Hoe Alcohol Werkt</h3><p>Alcohol werkt als remmer op het centraal zenuwstelsel.</p>'],
                    ['title' => 'Hoofdstuk 3: Herkennen van problematisch gebruik', 'content' => '<h3>Vroege Signalering</h3><p>De CAGE vragenlijst en andere screeningtools.</p>'],
                    ['title' => 'Hoofdstuk 4: Gespreksvoering', 'content' => '<h3>Het Gesprek Aangaan</h3><p>De 5 A\'s methode voor korte interventies.</p>'],
                    ['title' => 'Hoofdstuk 5: Behandelmogelijkheden', 'content' => '<h3>Van Vroeginterventie tot Behandeling</h3><p>Ambulant, klinisch en zelfhulpgroepen.</p>'],
                ],
            ],

            // =========================================================================
            // INDEX 2-4: ONLINE COURSES - CLOSED (with editions, enrollment form, sessions)
            // These appear on /online/ but require going through Stride enrollment form.
            // =========================================================================

            // === INDEX 2: Closed online - cannabis (with edition + form) ===
            [
                'title' => 'E-learning: Cannabis - Feiten en Fabels',
                'description' => 'Alles wat je moet weten over cannabis: van de werkzame stoffen tot de effecten op de hersenen. Speciaal voor professionals die werken met jongeren.',
                'type' => 'online',
                'format' => ['online', 'e-learning'],
                'themes' => ['alcohol', 'jongeren'],
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
                            ['date_offset' => 0, 'start' => '00:00', 'end' => '23:59', 'type' => SESSION_TYPE_ONLINE, 'title' => 'E-learning: Cannabis module (90 dagen toegang)'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Les 1: Cannabis 101', 'content' => '<h3>Wat is Cannabis?</h3><p>THC vs CBD, vormen van cannabis.</p>'],
                    ['title' => 'Les 2: Effecten en risico\'s', 'content' => '<h3>Wat Doet Cannabis?</h3><p>Gewenste en ongewenste effecten, risico\'s bij jongeren.</p>'],
                    ['title' => 'Les 3: Cannabis en psychose', 'content' => '<h3>De Relatie</h3><p>Genetische kwetsbaarheid en andere risicofactoren.</p>'],
                ],
            ],

            // === INDEX 3: Closed online - gaming addiction (with edition + form) ===
            [
                'title' => 'E-learning: Gaming en Schermverslaving',
                'description' => 'Interactieve e-learning over gaming disorder en problematisch schermgebruik. Met casussen, video-interviews en een toolkit voor het voeren van gesprekken met jongeren over hun schermgedrag.',
                'type' => 'online',
                'format' => ['online', 'e-learning'],
                'themes' => ['jongeren', 'verslaving'],
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
                    ['title' => 'Module 1: Digitale cultuur', 'content' => '<p>Hoe jongeren technologie gebruiken en waarom sommigen problematisch gedrag ontwikkelen.</p>'],
                    ['title' => 'Module 2: Gaming disorder (DSM-5)', 'content' => '<p>Criteria, prevalentie en onderscheid met passioneel gamen.</p>'],
                    ['title' => 'Module 3: Schermtijd en welzijn', 'content' => '<p>Het verband tussen schermgebruik, slaap, concentratie en sociale vaardigheden.</p>'],
                    ['title' => 'Module 4: In gesprek met jongeren', 'content' => '<p>Praktische gesprekstechnieken en tools.</p>'],
                ],
            ],

            // === INDEX 4: Closed online - medication-assisted treatment (full, to test) ===
            [
                'title' => 'E-learning: Medicatie bij Verslaving',
                'description' => 'Uitgebreide online cursus over farmacologische behandeling van verslavingsstoornissen. Methadon, buprenorfine, naltrexon, acamprosaat en meer.',
                'type' => 'online',
                'format' => ['online', 'e-learning'],
                'themes' => ['behandeling', 'verslaving'],
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
                            ['date_offset' => 0, 'start' => '00:00', 'end' => '23:59', 'type' => SESSION_TYPE_ONLINE, 'title' => 'E-learning: Farmacotherapie (lopende cohort)'],
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
                            ['date_offset' => 0, 'start' => '00:00', 'end' => '23:59', 'type' => SESSION_TYPE_ONLINE, 'title' => 'E-learning: Farmacotherapie (nieuw cohort)'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Module 1: Principes van farmacotherapie', 'content' => '<p>Wanneer medicatie inzetten en hoe dit past in een integrale behandeling.</p>'],
                    ['title' => 'Module 2: Opiaat substitutie', 'content' => '<p>Methadon, buprenorfine, heroine-ondersteunde behandeling.</p>'],
                    ['title' => 'Module 3: Anti-craving medicatie', 'content' => '<p>Naltrexon, acamprosaat, disulfiram en nieuwe middelen.</p>'],
                ],
            ],

            // =========================================================================
            // INDEX 5-10: IN-PERSON COURSES (various configurations)
            // =========================================================================

            // === INDEX 5: Simple single-session course (2 editions in different cities) ===
            // First edition has post-course requirements for testing completion flow
            [
                'title' => 'Motiverende Gespreksvoering - Basistraining',
                'description' => 'Motiverende Gespreksvoering (MGV) is een evidence-based gespreksmethodiek. In deze intensieve basistraining leer je de vier processen van MGV. Erkend voor SKJ/NVO-registratie.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['methodiek', 'behandeling'],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('-2 weeks')), // Past date: sessions already happened
                        'price' => 295.00,
                        'price_non_member' => 345.00,
                        'capacity' => 16,
                        'venue' => 'VAD Opleidingscentrum Brussel',
                        'speakers' => 'Dr. Marie De Vries',
                        'status' => 'open',
                        'enrollment_form' => 'default',
                        'post_requires_evaluation' => true,
                        'post_requires_documents' => true,
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:30', 'end' => '16:30', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Basistraining MGV'],
                        ],
                    ],
                    [
                        'start_date' => date('Y-m-d', strtotime('+6 weeks')),
                        'price' => 295.00,
                        'price_non_member' => 345.00,
                        'capacity' => 16,
                        'venue' => 'VAD Locatie Antwerpen',
                        'speakers' => 'Drs. Peter Willems',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:30', 'end' => '16:30', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Basistraining MGV'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Theorie: De geest van MGV', 'content' => 'Achtergrond en principes van motiverende gespreksvoering.'],
                    ['title' => 'Praktijk: ORBS-technieken', 'content' => 'Open vragen, Reflecteren, Bevestigen, Samenvatten.'],
                ],
            ],

            // === INDEX 6: Multi-day intensive (3 consecutive days, spread over weeks) ===
            // First edition has post-course requirements including approval for testing
            [
                'title' => 'Cognitieve Gedragstherapie bij Verslaving',
                'description' => 'Driedaagse intensieve training in CGT specifiek voor de verslavingszorg. Functionele analyses, gedachten uitdagen, terugvalpreventie. Inclusief werkboek.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['behandeling', 'methodiek'],
                'editions' => [
                    // Edition 1: 3 days spread over 3 weeks (past dates for post-course testing)
                    [
                        'start_date' => date('Y-m-d', strtotime('-4 weeks')),
                        'price' => 695.00,
                        'price_non_member' => 795.00,
                        'capacity' => 12,
                        'venue' => 'Conferentiecentrum De Factorij, Antwerpen',
                        'speakers' => 'Prof. dr. Jan Janssen, Dr. Els Peters',
                        'status' => 'open',
                        'enrollment_form' => 'default',
                        'post_requires_evaluation' => true,
                        'post_requires_documents' => true,
                        'post_requires_approval' => true,
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 1: Gedragsanalyse en functieanalyse'],
                            ['date_offset' => 7, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 2: Cognitieve interventies'],
                            ['date_offset' => 14, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 3: Terugvalpreventie en integratie'],
                        ],
                    ],
                    // Edition 2: almost full
                    [
                        'start_date' => date('Y-m-d', strtotime('+2 months')),
                        'price' => 695.00,
                        'price_non_member' => 795.00,
                        'capacity' => 12,
                        'registered' => 10,
                        'venue' => 'VAD Opleidingscentrum Gent',
                        'speakers' => 'Prof. dr. Jan Janssen',
                        'status' => 'few_spots',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 1: Gedragsanalyse'],
                            ['date_offset' => 7, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 2: Cognitieve interventies'],
                            ['date_offset' => 14, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 3: Terugvalpreventie'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'CGT Dag 1: Functieanalyse', 'content' => 'Leer het gedragsmodel en het maken van een functionele analyse.'],
                    ['title' => 'CGT Dag 2: Gedachten uitdagen', 'content' => 'Cognitieve technieken voor het werken met automatische gedachten.'],
                    ['title' => 'CGT Dag 3: Terugvalpreventie', 'content' => 'Het opstellen en implementeren van een terugvalpreventieplan.'],
                ],
            ],

            // === INDEX 7: Masterclass - sold out + future (single session, exclusive) ===
            [
                'title' => 'Masterclass Crisisinterventie bij Verslaving',
                'description' => 'Exclusieve masterclass voor ervaren professionals. De-escalatie, crisisassessment, veilig handelen bij intoxicatie. Maximum 8 deelnemers.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['behandeling'],
                'editions' => [
                    // Full
                    [
                        'start_date' => date('Y-m-d', strtotime('+3 weeks')),
                        'price' => 450.00,
                        'price_non_member' => 525.00,
                        'capacity' => 8,
                        'registered' => 8,
                        'venue' => 'VAD Opleidingscentrum Brussel',
                        'speakers' => 'Dr. Paul Verhaeghe',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Masterclass Crisisinterventie'],
                        ],
                    ],
                    // Future
                    [
                        'start_date' => date('Y-m-d', strtotime('+3 months')),
                        'price' => 450.00,
                        'price_non_member' => 525.00,
                        'capacity' => 8,
                        'venue' => 'VAD Opleidingscentrum Brussel',
                        'speakers' => 'Dr. Paul Verhaeghe',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Masterclass Crisisinterventie'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Crisisinterventie module', 'content' => 'Theorie en praktijk van crisisinterventie in de verslavingszorg.'],
                ],
            ],

            // === INDEX 8: Course with SESSION SLOTS + SELECTION DEADLINE ===
            // Users must choose between parallel sessions (morning/afternoon tracks)
            [
                'title' => 'Preventie in de Praktijk',
                'description' => 'Complete tweedaagse cursus over preventieve interventies. Na de basistraining kies je uit verdiepingsmodules voor specifieke doelgroepen.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['preventie'],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+5 weeks')),
                        'price' => 395.00,
                        'price_non_member' => 450.00,
                        'capacity' => 20,
                        'venue' => 'VAD Opleidingscentrum Brussel',
                        'speakers' => 'Team VAD Preventie',
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
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 1: Basisprincipes preventie', 'optional' => false],
                            // Mandatory day 2 morning
                            ['date_offset' => 1, 'start' => '09:00', 'end' => '12:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 2: Praktijk en interventies', 'optional' => false],
                            // SLOT: choose ONE of these afternoon sessions
                            ['date_offset' => 1, 'start' => '13:00', 'end' => '16:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Verdieping A: Jongeren en social media', 'optional' => true, 'slot' => 'Verdieping (kies 1)'],
                            ['date_offset' => 1, 'start' => '13:00', 'end' => '16:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Verdieping B: Ouderen en medicatie', 'optional' => true, 'slot' => 'Verdieping (kies 1)'],
                            ['date_offset' => 1, 'start' => '13:00', 'end' => '16:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Verdieping C: Werkplek en alcohol', 'optional' => true, 'slot' => 'Verdieping (kies 1)'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Preventie basis', 'content' => 'Basisprincipes van verslavingspreventie.'],
                    ['title' => 'Preventie praktijk', 'content' => 'Praktische toepassing van preventieve interventies.'],
                ],
            ],

            // === INDEX 9: Course with CANCELLED + RESCHEDULED editions ===
            [
                'title' => 'Workshop Mindfulness in de Verslavingszorg',
                'description' => 'Praktische workshop over mindfulness-technieken in de behandeling van verslaving. Geschikt voor alle hulpverleners, geen meditatie-ervaring vereist.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['behandeling', 'methodiek'],
                'editions' => [
                    // Cancelled
                    [
                        'start_date' => date('Y-m-d', strtotime('+2 weeks')),
                        'price' => 195.00,
                        'price_non_member' => 225.00,
                        'capacity' => 15,
                        'venue' => 'Yogacentrum Leuven',
                        'speakers' => 'Leen Smits, MBSR-trainer',
                        'status' => 'cancelled',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '13:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Workshop Mindfulness (GEANNULEERD)'],
                        ],
                    ],
                    // Rescheduled
                    [
                        'start_date' => date('Y-m-d', strtotime('+6 weeks')),
                        'price' => 195.00,
                        'price_non_member' => 225.00,
                        'capacity' => 15,
                        'venue' => 'Yogacentrum Leuven',
                        'speakers' => 'Leen Smits, MBSR-trainer',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '13:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Workshop Mindfulness (nieuwe datum)'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Mindfulness workshop', 'content' => 'Praktische introductie in mindfulness voor de behandelpraktijk.'],
                ],
            ],

            // === INDEX 10: Free single-session introductory course ===
            [
                'title' => 'Gratis Introductie: Werken bij VAD',
                'description' => 'Gratis kennismakingsochtend voor nieuwe medewerkers en stagiairs. Rondleiding en ontmoeting met collega\'s.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['verslaving'],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+1 week')),
                        'price' => 0.00,
                        'price_non_member' => 0.00,
                        'capacity' => 30,
                        'venue' => 'VAD Hoofdkantoor Brussel',
                        'speakers' => 'Diverse VAD medewerkers',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:30', 'end' => '12:30', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Introductie en rondleiding VAD'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Introductie VAD', 'content' => 'Kennismaking met VAD als organisatie.'],
                ],
            ],

            // =========================================================================
            // INDEX 11-12: IN-PERSON COURSES WITH COMPLETION REQUIREMENTS
            // =========================================================================

            // === INDEX 11: Questionnaire + Documents + Approval (full completion flow) ===
            [
                'title' => 'Erkenningstraject Verslavingsdeskundige',
                'description' => 'Erkend opleidingstraject voor verslavingsdeskundigen. Na inschrijving vul je een intake-vragenlijst in, upload je relevante diploma\'s, en wacht je op goedkeuring van het opleidingsteam.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['behandeling', 'methodiek'],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+6 weeks')),
                        'price' => 895.00,
                        'price_non_member' => 995.00,
                        'capacity' => 12,
                        'venue' => 'VAD Opleidingscentrum Brussel',
                        'speakers' => 'Prof. dr. An Vermeersch, Dr. Koen De Smet',
                        'status' => 'open',
                        'enrollment_form' => 'default',
                        'requires_questionnaire' => true,
                        'requires_documents' => true,
                        'requires_approval' => true,
                        'sessions' => [
                            ['date_offset' => 0,  'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 1: Intake en assessment'],
                            ['date_offset' => 7,  'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 2: Diagnostiek en classificatie'],
                            ['date_offset' => 14, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 3: Behandelplanning'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Voorbereidingsmateriaal', 'content' => 'Lees dit document voor de eerste lesdag.'],
                ],
            ],

            // === INDEX 12: Documents only (upload required, no approval) ===
            [
                'title' => 'Bijscholing Ambulante Verslavingszorg',
                'description' => 'Verplichte bijscholing voor ambulante hulpverleners. Upload je registratiebewijs bij inschrijving zodat we je accreditatie kunnen verwerken.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['behandeling'],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+4 weeks')),
                        'price' => 175.00,
                        'price_non_member' => 225.00,
                        'capacity' => 25,
                        'venue' => 'VAD Locatie Antwerpen',
                        'speakers' => 'Drs. Sofie Claes',
                        'status' => 'open',
                        'enrollment_form' => 'default',
                        'requires_documents' => true,
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:30', 'end' => '16:30', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Bijscholing Ambulante Verslavingszorg'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Bijscholing module', 'content' => 'Actuele ontwikkelingen in de ambulante verslavingszorg.'],
                ],
            ],

            // =========================================================================
            // INDEX 13: HYBRID COURSE (in-person + online + webinar sessions)
            // =========================================================================
            [
                'title' => 'Dual Diagnose: Verslaving en Psychiatrie',
                'description' => 'Hybride leertraject: klassikale dag + e-learning + live webinar + praktijkdag. Combineert kennisoverdracht met diepgang.',
                'type' => 'hybrid',
                'format' => ['klassikaal', 'hybrid'],
                'themes' => ['behandeling', 'verslaving'],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+4 weeks')),
                        'price' => 550.00,
                        'price_non_member' => 650.00,
                        'capacity' => 20,
                        'venue' => 'VAD Brussel + Online',
                        'speakers' => 'Dr. Katrien Maes, psychiater',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'location' => 'VAD Opleidingscentrum Brussel', 'title' => 'Introductie dual diagnose'],
                            ['date_offset' => 3, 'start' => '00:00', 'end' => '23:59', 'type' => SESSION_TYPE_ONLINE, 'title' => 'E-learning: Psychiatrische stoornissen'],
                            ['date_offset' => 7, 'start' => '14:00', 'end' => '16:00', 'type' => SESSION_TYPE_WEBINAR, 'title' => 'Live Q&A: Casusbespreking'],
                            ['date_offset' => 10, 'start' => '00:00', 'end' => '23:59', 'type' => SESSION_TYPE_ONLINE, 'title' => 'E-learning: Geïntegreerde behandeling'],
                            ['date_offset' => 14, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'location' => 'VAD Opleidingscentrum Brussel', 'title' => 'Praktijkdag: Integratie en toepassing'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'E-learning Dual Diagnose Module 1', 'content' => 'Online module over comorbiditeit.'],
                    ['title' => 'E-learning Dual Diagnose Module 2', 'content' => 'Online module over geïntegreerde behandeling.'],
                ],
            ],

            // =========================================================================
            // INDEX 14-15: WEBINAR COURSES
            // =========================================================================

            // === INDEX 14: Webinar series (4 weekly sessions) ===
            [
                'title' => 'Webinarreeks: Actuele Thema\'s in Verslavingszorg',
                'description' => 'Reeks van 4 interactieve webinars. Elke week een nieuw thema met een expert spreker. Inclusief opname en handouts.',
                'type' => 'webinar',
                'format' => ['webinar', 'online'],
                'themes' => ['verslaving', 'behandeling'],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+1 week')),
                        'price' => 150.00,
                        'price_non_member' => 180.00,
                        'capacity' => 100,
                        'venue' => 'Online (Zoom)',
                        'speakers' => 'Diverse experts',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '19:00', 'end' => '20:30', 'type' => SESSION_TYPE_WEBINAR, 'title' => 'Webinar 1: Gaming en sociale media verslaving'],
                            ['date_offset' => 7, 'start' => '19:00', 'end' => '20:30', 'type' => SESSION_TYPE_WEBINAR, 'title' => 'Webinar 2: Jongeren en online gokken'],
                            ['date_offset' => 14, 'start' => '19:00', 'end' => '20:30', 'type' => SESSION_TYPE_WEBINAR, 'title' => 'Webinar 3: Medicatie-ondersteunde behandeling'],
                            ['date_offset' => 21, 'start' => '19:00', 'end' => '20:30', 'type' => SESSION_TYPE_WEBINAR, 'title' => 'Webinar 4: Herstelondersteunende zorg'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Webinar introductie', 'content' => 'Algemene informatie over de webinarreeks.'],
                ],
            ],

            // === INDEX 15: Single free webinar ===
            [
                'title' => 'Gratis Webinar: Lachgas - De Nieuwe Trend',
                'description' => 'Gratis informatief webinar over lachgasgebruik onder jongeren. Risico\'s, herkenning en gesprekstechnieken.',
                'type' => 'webinar',
                'format' => ['webinar', 'online'],
                'themes' => ['jongeren', 'preventie'],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+10 days')),
                        'price' => 0.00,
                        'price_non_member' => 0.00,
                        'capacity' => 200,
                        'venue' => 'Online (Teams)',
                        'speakers' => 'Dr. Sarah Janssen, toxicoloog',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '12:00', 'end' => '13:00', 'type' => SESSION_TYPE_WEBINAR, 'title' => 'Lunchwebinar: Lachgas - feiten en risico\'s'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Lachgas info', 'content' => 'Informatie over lachgas en de effecten.'],
                ],
            ],

            // =========================================================================
            // INDEX 16-20: TRAJECTORY COURSES (used in trajectories)
            // =========================================================================

            // === INDEX 16: Required foundation ===
            [
                'title' => 'Verslavingskunde: Fundament',
                'description' => 'Basiscursus voor iedereen die professioneel met verslaving werkt. Verplicht onderdeel van het traject Verslavingsspecialist.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['verslaving', 'behandeling'],
                'trajectory_role' => 'required',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+2 weeks')),
                        'price' => 395.00,
                        'price_non_member' => 495.00,
                        'capacity' => 16,
                        'venue' => 'VAD Opleidingscentrum Brussel',
                        'speakers' => 'Team VAD Academie',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 1: Basiskennis'],
                            ['date_offset' => 1, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 2: Gespreksvaardigheden'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Fundament module 1', 'content' => 'Basiskennis verslavingskunde.'],
                    ['title' => 'Fundament module 2', 'content' => 'Introductie gespreksvoering.'],
                ],
            ],

            // === INDEX 17: Required advanced ===
            [
                'title' => 'Verslavingskunde: Assessment en Diagnostiek',
                'description' => 'Gestructureerd intake afnemen, screeningsinstrumenten gebruiken, behandelplan opstellen. Verplicht onderdeel traject.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['behandeling'],
                'trajectory_role' => 'required',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+4 weeks')),
                        'price' => 395.00,
                        'price_non_member' => 495.00,
                        'capacity' => 14,
                        'venue' => 'VAD Opleidingscentrum Brussel',
                        'speakers' => 'Dr. Tom Decorte',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Assessment en screeningsinstrumenten'],
                            ['date_offset' => 14, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Behandelplanning en doelen stellen'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Assessment theorie', 'content' => 'Screeningsinstrumenten en intake.'],
                    ['title' => 'Diagnostiek praktijk', 'content' => 'Behandelplanning.'],
                ],
            ],

            // === INDEX 18: Elective 1 ===
            [
                'title' => 'Keuzecursus: Familie en Systeem',
                'description' => 'Verdieping over het betrekken van het systeem bij de behandeling. Keuzevak voor het traject Verslavingsspecialist.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['behandeling'],
                'trajectory_role' => 'elective',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+8 weeks')),
                        'price' => 245.00,
                        'price_non_member' => 295.00,
                        'capacity' => 16,
                        'venue' => 'VAD Locatie Antwerpen',
                        'speakers' => 'Drs. Lies Verhoeven, systeemtherapeut',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Familie en systeemwerk'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Systeemwerk module', 'content' => 'Werken met het systeem rondom de cliënt.'],
                ],
            ],

            // === INDEX 19: Elective 2 ===
            [
                'title' => 'Keuzecursus: Groepsbehandeling',
                'description' => 'Verdieping in groepstherapie bij verslaving. Groepsdynamica, sessies structureren, omgaan met weerstand.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['behandeling', 'methodiek'],
                'trajectory_role' => 'elective',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+9 weeks')),
                        'price' => 245.00,
                        'price_non_member' => 295.00,
                        'capacity' => 12,
                        'venue' => 'VAD Opleidingscentrum Gent',
                        'speakers' => 'Dr. Kris Goethals',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Groepsbehandeling bij verslaving'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Groepsdynamica', 'content' => 'Theorie en praktijk van groepstherapie.'],
                ],
            ],

            // === INDEX 20: Elective 3 ===
            [
                'title' => 'Keuzecursus: Harm Reduction',
                'description' => 'Verdieping in harm reduction benaderingen. Spuitenruil, drugscheck, gebruikersruimtes, naloxon.',
                'type' => 'in-person',
                'format' => ['klassikaal'],
                'themes' => ['preventie', 'verslaving'],
                'trajectory_role' => 'elective',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+10 weeks')),
                        'price' => 195.00,
                        'price_non_member' => 245.00,
                        'capacity' => 20,
                        'venue' => 'VAD Hoofdkantoor Brussel',
                        'speakers' => 'Peter Vander Laenen',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:30', 'end' => '16:30', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Harm reduction: theorie en praktijk'],
                        ],
                    ],
                ],
                'lessons' => [
                    ['title' => 'Harm reduction module', 'content' => 'Principes en praktijk van schadebeperking.'],
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
        if (!empty($data['registered']) && $this->regRepo) {
            for ($i = 0; $i < $data['registered']; $i++) {
                global $wpdb;
                $wpdb->insert(
                    $wpdb->prefix . 'vad_registrations',
                    [
                        'user_id' => 0,
                        'edition_id' => $editionId,
                        'status' => 'confirmed',
                        'enrollment_path' => 'individual',
                        'notes' => 'Seed placeholder',
                        'registered_at' => current_time('mysql'),
                    ]
                );
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
        // 0: E-learning Basiskennis (online open)
        // 1: E-learning Alcohol (online open)
        // 2: E-learning Cannabis (online closed)
        // 3: E-learning Gaming (online closed)
        // 4: E-learning Medicatie (online closed)
        // 5: MGV Basistraining (in-person)
        // 6: CGT bij Verslaving (in-person)
        // 7: Masterclass Crisisinterventie (in-person)
        // 8: Preventie in de Praktijk (in-person, slots)
        // 9: Workshop Mindfulness (in-person, cancelled/rescheduled)
        // 10: Gratis Introductie (in-person, free)
        // 11: Dual Diagnose (hybrid)
        // 12: Webinarreeks (webinar)
        // 13: Gratis Webinar Lachgas (webinar, free)
        // 14: Fundament (trajectory required)
        // 15: Assessment (trajectory required)
        // 16: Familie en Systeem (trajectory elective)
        // 17: Groepsbehandeling (trajectory elective)
        // 18: Harm Reduction (trajectory elective)

        // === TRAJECTORY 1: COHORT - Verslavingsspecialist ===
        $cohortData = [
            'title' => 'Traject Verslavingsspecialist',
            'post_content' => '<p>Word een gecertificeerd verslavingsspecialist met dit intensieve cohort-traject.</p>
<h3>Verplichte cursussen</h3>
<ul><li>Verslavingskunde: Fundament (2 dagen)</li><li>Assessment en Diagnostiek (2 dagen)</li><li>Motiverende Gespreksvoering (1 dag)</li><li>CGT bij Verslaving (3 dagen)</li></ul>
<h3>Keuzecursussen (kies 2 uit 3)</h3>
<ul><li>Familie en Systeem</li><li>Groepsbehandeling</li><li>Harm Reduction</li></ul>',
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
            echo "  + Cohort trajectory: Traject Verslavingsspecialist (ID: {$cohortId})\n";
        } else {
            echo "  ! Cohort trajectory failed: {$cohortId->get_error_message()}\n";
        }

        // === TRAJECTORY 2: SELF-PACED - Preventiewerker ===
        $selfPacedData = [
            'title' => 'Traject Preventiewerker',
            'post_content' => '<p>Flexibel traject voor preventiewerkers. Combineer online cursussen met praktijkdagen, in je eigen tempo.</p>',
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
            echo "  + Self-paced trajectory: Traject Preventiewerker (ID: {$selfPacedId})\n";
        } else {
            echo "  ! Self-paced trajectory failed: {$selfPacedId->get_error_message()}\n";
        }

        // === TRAJECTORY 3: BLENDED - Kennismakingstraject ===
        $blendedData = [
            'title' => 'Kennismakingstraject Verslavingszorg',
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
            ['code' => 'VAD-MEMBER', 'discount_type' => 'percentage', 'discount_value' => 15, 'usage_limit' => 100],
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
                    $completion = new EnrollmentCompletion();
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

                    $completion = new EnrollmentCompletion();
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
                    $regId = $this->regRepo->create([
                        'user_id' => $userId,
                        'edition_id' => $editionId,
                        'status' => RegistrationStatus::Confirmed->value,
                        'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
                    ]);

                    if (!is_wp_error($regId)) {
                        $this->created['registrations'][] = $regId;
                        echo "  + Registration: {$user->user_login} -> edition {$editionId}\n";
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
                    $completion = new EnrollmentCompletion();
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
            'name'         => 'VAD vzw',
            'address'      => 'Vanderlindenstraat 15',
            'postal_code'  => '1030',
            'city'         => 'Brussel',
            'country'      => 'België',
            'vat'          => 'BE0420.798.935',
            'email'        => 'info@vad.be',
            'phone'        => '+32 2 423 03 33',
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
        echo "      - E-learning: Basiskennis Verslavingszorg (free, open)\n";
        echo "      - E-learning: Alcohol en Gezondheid (free, 90-day expiry)\n";
        echo "    Closed (Stride enrollment form + sessions):\n";
        echo "      - E-learning: Cannabis - Feiten en Fabels (€75, form)\n";
        echo "      - E-learning: Gaming en Schermverslaving (€65, form)\n";
        echo "      - E-learning: Medicatie bij Verslaving (€125, full + new cohort)\n";
        echo "\n";

        echo "  IN-PERSON (6 total, various configs):\n";
        echo "    - MGV Basistraining (2 editions: Brussel + Antwerpen, single day)\n";
        echo "    - CGT bij Verslaving (2 editions, 3-day spread over weeks, 1 almost full)\n";
        echo "    - Masterclass Crisisinterventie (1 FULL, 1 open, single session)\n";
        echo "    - Preventie in de Praktijk (session SLOTS + selection deadline)\n";
        echo "    - Workshop Mindfulness (1 cancelled, 1 rescheduled)\n";
        echo "    - Gratis Introductie (free, single morning session)\n";
        echo "\n";

        echo "  HYBRID (1 total):\n";
        echo "    - Dual Diagnose (klassikaal + e-learning + webinar mixed sessions)\n";
        echo "\n";

        echo "  WEBINAR (2 total):\n";
        echo "    - Webinarreeks: Actuele Thema's (4 weekly sessions)\n";
        echo "    - Gratis Webinar: Lachgas (free, single lunchtime session)\n";
        echo "\n";

        echo "  TRAJECTORY COURSES (5 total):\n";
        echo "    Required: Fundament, Assessment en Diagnostiek\n";
        echo "    Electives: Familie en Systeem, Groepsbehandeling, Harm Reduction\n";
        echo "\n";

        echo "=== Trajectories ===\n";
        echo "  1. Traject Verslavingsspecialist (cohort, 4 required + choose 2 of 3)\n";
        echo "  2. Traject Preventiewerker (self-paced, 3 required + 2 optional)\n";
        echo "  3. Kennismakingstraject (free entry, 2 required + 2 optional)\n";
        echo "\n";

        echo "=== Post-Course Completion ===\n";
        echo "  - MGV Basistraining (Brussel): post_evaluation + post_documents\n";
        echo "  - CGT bij Verslaving (Antwerpen): post_evaluation + post_documents + post_approval\n";
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
        echo "  - VAD-MEMBER (15% off)\n";
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
