<?php
/**
 * Stride LMS - Development Seed Script
 *
 * Run with: ddev exec wp eval-file scripts/seed.php
 *
 * Creates comprehensive test data for frontend testing:
 * - 10 courses (2 online, 8 in-person)
 * - Various edition configurations (single/multiple editions, all statuses)
 * - Sessions with different types (in_person, online, webinar, assignment)
 * - 2 trajectories (cohort and self-paced) with courses
 * - Registrations, quotes and vouchers
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: ddev exec wp eval-file scripts/seed.php\n";
    exit(1);
}

// Prevent accidental runs in production
if (defined('WP_ENV') && WP_ENV === 'production') {
    echo "ERROR: Cannot run seed script in production!\n";
    exit(1);
}

use Stride\Domain\RegistrationStatus;
use Stride\Domain\SessionType;
use Stride\Domain\TrajectoryMode;
use Stride\Domain\TrajectoryStatus;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryService;
use Stride\Modules\Trajectory\TrajectoryEnrollmentRepository;

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
    private ?TrajectoryEnrollmentRepository $trajectoryEnrollmentRepo = null;

    // Current admin user to subscribe
    private int $adminUserId = 1;

    public function run(): void {
        echo "\n=== Stride LMS Comprehensive Seed ===\n\n";

        $this->initServices();
        $this->createUsers();
        $this->createCourses();
        $this->createTrajectories();
        $this->createVouchers();
        $this->createEnrollmentsAndQuotes();
        $this->createTrajectoryTestData();

        $this->saveSeedManifest();
        $this->printSummary();
    }

    private function initServices(): void {
        if (function_exists('ntdst_get')) {
            $this->editionService = ntdst_get(EditionService::class);
            $this->editionRepository = ntdst_get(EditionRepository::class);
            $this->sessionService = ntdst_get(SessionService::class);
            $this->regRepo = ntdst_get(RegistrationRepository::class);
            $this->trajectoryService = ntdst_get(TrajectoryService::class);
            $this->trajectoryEnrollmentRepo = ntdst_get(TrajectoryEnrollmentRepository::class);
        }
    }

    private function createUsers(): void {
        echo "Creating users...\n";

        $users = [
            ['login' => 'seed_admin', 'email' => 'seed_admin@seed.test', 'role' => 'administrator', 'first' => 'Admin', 'last' => 'Seed'],
            ['login' => 'seed_instructor', 'email' => 'instructor@seed.test', 'role' => 'group_leader', 'first' => 'Jan', 'last' => 'De Trainer'],
            ['login' => 'seed_student1', 'email' => 'student1@seed.test', 'role' => 'subscriber', 'first' => 'Pieter', 'last' => 'Janssen', 'is_member' => true],
            ['login' => 'seed_student2', 'email' => 'student2@seed.test', 'role' => 'subscriber', 'first' => 'Anna', 'last' => 'De Vries', 'is_member' => true],
            ['login' => 'seed_student3', 'email' => 'student3@seed.test', 'role' => 'subscriber', 'first' => 'Thomas', 'last' => 'Bakker', 'is_member' => false],
        ];

        foreach ($users as $userData) {
            $existing = get_user_by('login', $userData['login']);
            if ($existing) {
                $this->created['users'][] = $existing->ID;
                echo "  - User {$userData['login']} exists (ID: {$existing->ID})\n";
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

        // ===== COURSE DEFINITIONS =====
        $courses = [
            // === 1. SINGLE EDITION COURSE (in-person, 1 day) ===
            [
                'title' => 'Motiverende Gespreksvoering',
                'description' => 'Leer de technieken van motiverende gespreksvoering. Een praktijkgerichte cursus voor professionals die werken met cliënten in de verslavingszorg.',
                'type' => 'in-person',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+2 weeks')),
                        'price' => 295.00,
                        'price_non_member' => 345.00,
                        'capacity' => 16,
                        'venue' => 'VAD Brussel',
                        'speakers' => 'Dr. Marie De Vries',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:30', 'end' => '16:30', 'type' => SESSION_TYPE_IN_PERSON],
                        ],
                    ],
                ],
            ],

            // === 2. MULTI-DAY COURSE (3 days, spread over weeks) ===
            [
                'title' => 'Cognitieve Gedragstherapie bij Verslaving',
                'description' => 'Intensieve driedaagse cursus over CGT-technieken specifiek voor verslavingszorg. Inclusief casusbespreking en praktijkoefeningen.',
                'type' => 'in-person',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+3 weeks')),
                        'price' => 695.00,
                        'price_non_member' => 795.00,
                        'capacity' => 12,
                        'venue' => 'Conferentiecentrum Antwerpen',
                        'speakers' => 'Prof. Jan Janssen, Dr. Els Peters',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON],
                            ['date_offset' => 7, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON],
                            ['date_offset' => 14, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON],
                        ],
                    ],
                    // Second edition - almost full
                    [
                        'start_date' => date('Y-m-d', strtotime('+2 months')),
                        'price' => 695.00,
                        'price_non_member' => 795.00,
                        'capacity' => 12,
                        'registered' => 10, // almost full
                        'venue' => 'VAD Gent',
                        'speakers' => 'Prof. Jan Janssen',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON],
                            ['date_offset' => 7, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON],
                            ['date_offset' => 14, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON],
                        ],
                    ],
                ],
            ],

            // === 3. HYBRID COURSE (in-person + online) ===
            [
                'title' => 'Dual Diagnose: Theorie en Praktijk',
                'description' => 'Hybride cursus met zowel klassikale sessies als online modules. Leer omgaan met cliënten met een dubbele diagnose.',
                'type' => 'hybrid',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+4 weeks')),
                        'price' => 550.00,
                        'price_non_member' => 650.00,
                        'capacity' => 20,
                        'venue' => 'VAD Brussel + Online',
                        'speakers' => 'Dr. Katrien Maes',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'location' => 'VAD Brussel'],
                            ['date_offset' => 3, 'start' => '00:00', 'end' => '23:59', 'type' => SESSION_TYPE_ONLINE, 'title' => 'E-learning module 1'],
                            ['date_offset' => 7, 'start' => '14:00', 'end' => '16:00', 'type' => SESSION_TYPE_WEBINAR, 'title' => 'Live Q&A webinar'],
                            ['date_offset' => 10, 'start' => '00:00', 'end' => '23:59', 'type' => SESSION_TYPE_ONLINE, 'title' => 'E-learning module 2'],
                            ['date_offset' => 14, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'location' => 'VAD Brussel'],
                        ],
                    ],
                ],
            ],

            // === 4. WEBINAR SERIES ===
            [
                'title' => 'Webinarreeks: Actuele Themas Verslavingszorg',
                'description' => 'Reeks van 4 interactieve webinars over actuele onderwerpen in de verslavingszorg. Volg live of bekijk de opname achteraf.',
                'type' => 'webinar',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+1 week')),
                        'price' => 150.00,
                        'price_non_member' => 180.00,
                        'capacity' => 100,
                        'venue' => 'Online (Zoom)',
                        'speakers' => 'Diverse sprekers',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '19:00', 'end' => '20:30', 'type' => SESSION_TYPE_WEBINAR, 'title' => 'Webinar 1: Gaming verslaving'],
                            ['date_offset' => 7, 'start' => '19:00', 'end' => '20:30', 'type' => SESSION_TYPE_WEBINAR, 'title' => 'Webinar 2: Social media & jongeren'],
                            ['date_offset' => 14, 'start' => '19:00', 'end' => '20:30', 'type' => SESSION_TYPE_WEBINAR, 'title' => 'Webinar 3: Medicatie en verslaving'],
                            ['date_offset' => 21, 'start' => '19:00', 'end' => '20:30', 'type' => SESSION_TYPE_WEBINAR, 'title' => 'Webinar 4: Terugvalpreventie'],
                        ],
                    ],
                ],
            ],

            // === 5. ONLINE SELF-PACED (e-learning) ===
            // Online courses do NOT have editions - they are always available
            [
                'title' => 'E-learning: Basiskennis Verslavingszorg',
                'description' => 'Volledig online cursus die je in je eigen tempo volgt. Ideaal als introductie of opfrisser. Inclusief certificaat na afronding.',
                'type' => 'online',
                'editions' => [], // No editions for online courses
            ],

            // === 6. ANOTHER ONLINE COURSE ===
            [
                'title' => 'E-learning: Alcohol en Gezondheid',
                'description' => 'Verdiepende online module over de effecten van alcohol. Met video-interviews, quizzen en praktijkcases.',
                'type' => 'online',
                'editions' => [], // No editions for online courses
            ],

            // === 7. FULL EDITION (sold out) ===
            [
                'title' => 'Masterclass Crisisinterventie',
                'description' => 'Intensieve masterclass voor ervaren professionals. Zeer populair - vaak snel vol!',
                'type' => 'in-person',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+3 weeks')),
                        'price' => 450.00,
                        'price_non_member' => 525.00,
                        'capacity' => 8,
                        'registered' => 8, // FULL
                        'venue' => 'VAD Brussel',
                        'speakers' => 'Dr. Paul Verhaeghe',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON],
                        ],
                    ],
                    // Future edition with spots
                    [
                        'start_date' => date('Y-m-d', strtotime('+3 months')),
                        'price' => 450.00,
                        'price_non_member' => 525.00,
                        'capacity' => 8,
                        'venue' => 'VAD Brussel',
                        'speakers' => 'Dr. Paul Verhaeghe',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON],
                        ],
                    ],
                ],
            ],

            // === 8. CANCELLED EDITION ===
            [
                'title' => 'Workshop Mindfulness in Behandeling',
                'description' => 'Praktische workshop over het integreren van mindfulness-technieken in de behandeling.',
                'type' => 'in-person',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+2 weeks')),
                        'price' => 195.00,
                        'price_non_member' => 225.00,
                        'capacity' => 15,
                        'venue' => 'VAD Leuven',
                        'speakers' => 'Leen Smits',
                        'status' => 'cancelled',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '13:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON],
                        ],
                    ],
                    // New date available
                    [
                        'start_date' => date('Y-m-d', strtotime('+6 weeks')),
                        'price' => 195.00,
                        'price_non_member' => 225.00,
                        'capacity' => 15,
                        'venue' => 'VAD Leuven',
                        'speakers' => 'Leen Smits',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '13:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON],
                        ],
                    ],
                ],
            ],

            // === 9. COURSE WITH OPTIONAL SESSIONS ===
            [
                'title' => 'Preventie in de Praktijk',
                'description' => 'Cursus over preventieve interventies met optionele verdiepingsmodules.',
                'type' => 'in-person',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+5 weeks')),
                        'price' => 395.00,
                        'price_non_member' => 450.00,
                        'capacity' => 20,
                        'venue' => 'VAD Brussel',
                        'speakers' => 'Team VAD Preventie',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 1: Basisprincipes', 'optional' => false],
                            ['date_offset' => 1, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 2: Praktijkoefeningen', 'optional' => false],
                            ['date_offset' => 7, 'start' => '09:00', 'end' => '12:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Verdieping: Jongeren', 'optional' => true],
                            ['date_offset' => 7, 'start' => '13:00', 'end' => '16:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Verdieping: Ouderen', 'optional' => true],
                        ],
                    ],
                ],
            ],

            // === 10. FREE COURSE ===
            [
                'title' => 'Introductie VAD Methodieken',
                'description' => 'Gratis introductiecursus voor nieuwe medewerkers. Kennismaking met de werkwijze en methodieken van VAD.',
                'type' => 'in-person',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+1 week')),
                        'price' => 0.00,
                        'price_non_member' => 0.00,
                        'capacity' => 30,
                        'venue' => 'VAD Brussel',
                        'speakers' => 'Diverse VAD medewerkers',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:30', 'end' => '12:30', 'type' => SESSION_TYPE_IN_PERSON],
                        ],
                    ],
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
        update_post_meta($courseId, '_sfwd-courses', ['course_price_type' => 'open']);
        update_post_meta($courseId, '_course_type', $courseData['type']);

        $this->created['courses'][] = $courseId;
        echo "  + Course: {$courseData['title']} (ID: {$courseId})\n";

        // Create lessons
        $lessonIds = $this->createLessonsForCourse($courseId, $courseData['title']);

        // Create editions
        foreach ($courseData['editions'] as $editionData) {
            $this->createEdition($courseId, $courseData['title'], $editionData);
        }
    }

    private function createEdition(int $courseId, string $courseTitle, array $data): void {
        if (!$this->editionRepository) return;

        $postTitle = $courseTitle . ' - ' . date('j M Y', strtotime($data['start_date']));
        $endDate = $data['end_date'] ?? null;
        if (!$endDate && !empty($data['sessions'])) {
            $maxOffset = max(array_column($data['sessions'], 'date_offset'));
            $endDate = date('Y-m-d', strtotime($data['start_date'] . " +{$maxOffset} days"));
        }
        $endDate = $endDate ?? $data['start_date'];

        $result = $this->editionRepository->create([
            'post_title' => $postTitle,
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

        // Simulate registrations if specified
        if (!empty($data['registered']) && $this->regRepo) {
            for ($i = 0; $i < $data['registered']; $i++) {
                // Create dummy registrations to fill capacity
                global $wpdb;
                $wpdb->insert(
                    $wpdb->prefix . 'vad_registrations',
                    [
                        'user_id' => 0, // dummy
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
        echo "    + Edition: {$data['start_date']} at {$data['venue']} (ID: {$editionId})\n";

        // Create sessions
        foreach ($data['sessions'] as $sessionData) {
            $this->createSession($editionId, $data, $sessionData);
        }
    }

    private function createSession(int $editionId, array $editionData, array $sessionData): void {
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

        $sessionId = $this->sessionService->createSession($createData);

        if (is_wp_error($sessionId)) {
            echo "      ! Session failed: {$sessionId->get_error_message()}\n";
            return;
        }

        update_post_meta($sessionId, self::SEED_META_KEY, true);
        $this->created['sessions'][] = $sessionId;

        $typeEmoji = match($sessionData['type']) {
            SESSION_TYPE_ONLINE => '💻',
            SESSION_TYPE_WEBINAR => '📹',
            SESSION_TYPE_ASSIGNMENT => '📝',
            default => '🏢',
        };
        $title = $sessionData['title'] ?? $sessionData['type'];
        echo "      {$typeEmoji} {$sessionDate} {$sessionData['start']}-{$sessionData['end']}: {$title}\n";
    }

    private function createLessonsForCourse(int $courseId, string $courseTitle): array {
        $lessonIds = [];
        $lessons = ['Introductie', 'Theorie', 'Praktijk', 'Evaluatie'];

        foreach ($lessons as $index => $title) {
            $lessonId = wp_insert_post([
                'post_title' => $title,
                'post_type' => 'sfwd-lessons',
                'post_status' => 'publish',
                'post_content' => "Les: {$title} voor {$courseTitle}",
                'menu_order' => $index + 1,
            ]);

            if (!is_wp_error($lessonId)) {
                update_post_meta($lessonId, self::SEED_META_KEY, true);
                update_post_meta($lessonId, 'course_id', $courseId);
                update_post_meta($lessonId, '_sfwd-lessons', ['sfwd-lessons_course' => $courseId]);
                $lessonIds[] = $lessonId;
            }
        }

        // Link lessons to course using LearnDash's expected structure
        if (!empty($lessonIds) && class_exists('LDLMS_Factory_Post')) {
            $courseStepsObj = LDLMS_Factory_Post::course_steps($courseId);
            if ($courseStepsObj) {
                // LearnDash expects steps grouped by post type
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
        echo "\nCreating trajectories...\n";

        if (!$this->trajectoryService) {
            echo "  ! TrajectoryService not available\n";
            return;
        }

        if (count($this->created['courses']) < 6) {
            echo "  ! Not enough courses for trajectories\n";
            return;
        }

        $courseIds = $this->created['courses'];

        // === COHORT TRAJECTORY ===
        $cohortData = [
            'post_title' => 'Traject Verslavingsspecialist',
            'post_content' => 'Volledige opleiding tot verslavingsspecialist. Dit cohort-traject volg je samen met een vaste groep. Je voltooit alle verplichte cursussen en kiest 2 keuzecursussen.',
            'mode' => TrajectoryMode::Cohort->value,
            'status' => TrajectoryStatus::Open->value,
            'enrollment_deadline' => date('Y-m-d', strtotime('+1 month')),
            'choice_available_date' => date('Y-m-d', strtotime('+2 weeks')),
            'choice_deadline' => date('Y-m-d', strtotime('+3 weeks')),
            'capacity' => 15,
            'price' => 1495.00,
            'price_non_member' => 1795.00,
            'courses' => json_encode([
                ['course_id' => $courseIds[0], 'required' => true],
                ['course_id' => $courseIds[1], 'required' => true],
                ['course_id' => $courseIds[2], 'required' => true],
                ['course_id' => $courseIds[3], 'required' => false, 'group' => 'Keuze (2)'],
                ['course_id' => $courseIds[4], 'required' => false, 'group' => 'Keuze (2)'],
                ['course_id' => $courseIds[5], 'required' => false, 'group' => 'Keuze (2)'],
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

        // === SELF-PACED TRAJECTORY ===
        $selfPacedData = [
            'post_title' => 'Traject Preventiewerker',
            'post_content' => 'Flexibel traject voor preventiewerkers. Volg de cursussen in je eigen tempo, wanneer het jou uitkomt. Ideaal te combineren met je werk.',
            'mode' => TrajectoryMode::SelfPaced->value,
            'status' => TrajectoryStatus::Open->value,
            'enrollment_deadline' => '', // no deadline
            'capacity' => 0, // unlimited
            'price' => 895.00,
            'price_non_member' => 1095.00,
            'courses' => json_encode([
                ['course_id' => $courseIds[4], 'required' => true],
                ['course_id' => $courseIds[5], 'required' => true],
                ['course_id' => $courseIds[8], 'required' => true],
                ['course_id' => $courseIds[9], 'required' => false, 'group' => 'Optioneel'],
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

        // Get open editions (skip cancelled) - meta uses _ntdst_ prefix
        $openEditions = [];
        foreach ($this->created['editions'] as $editionId) {
            $status = get_post_meta($editionId, '_ntdst_status', true);
            if ($status === 'open') {
                $openEditions[] = $editionId;
            }
        }

        if (empty($openEditions)) {
            echo "  ! No open editions\n";
            return;
        }

        // Enroll admin in first 3 editions
        $enrollEditions = array_slice($openEditions, 0, 3);

        foreach ($enrollEditions as $editionId) {
            // Create registration
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
                }
            }

            // Create quote
            $this->createQuoteForEdition($editionId, $this->adminUserId);
        }

        // Also enroll seed students
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

                // Create quote for student
                $this->createQuoteForEdition($editionId, $userId);
            }
        }
    }

    private function createQuoteForEdition(int $editionId, int $userId): void {
        $user = get_user_by('ID', $userId);
        $price = (float) (get_post_meta($editionId, 'price', true) ?: 299.00);
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

    /**
     * Create trajectory-specific test data for E2E tests.
     *
     * Creates:
     * - test-trajectory (cohort mode, open status)
     * - seed_enrolled_user (enrolled in test-trajectory)
     * - seed_completed_user (completed the trajectory)
     */
    private function createTrajectoryTestData(): void {
        echo "\nCreating trajectory test data for E2E tests...\n";

        // Get or create test trajectory
        $testTrajectory = get_page_by_path('test-trajectory', OBJECT, 'vad_trajectory');
        if (!$testTrajectory) {
            $testTrajectoryId = wp_insert_post([
                'post_type' => 'vad_trajectory',
                'post_title' => 'Test Trajectory',
                'post_name' => 'test-trajectory',
                'post_status' => 'publish',
                'post_content' => 'A test trajectory for E2E testing. This trajectory is used to verify enrollment flows.',
            ]);

            if (is_wp_error($testTrajectoryId)) {
                echo "  ! Failed to create test-trajectory: {$testTrajectoryId->get_error_message()}\n";
                return;
            }

            // Set trajectory meta using Data Manager if available
            if (function_exists('ntdst_data')) {
                $trajectoryModel = ntdst_data()->get('vad_trajectory');
                if ($trajectoryModel) {
                    $trajectoryModel->updateMetaBatch($testTrajectoryId, [
                        'mode' => TrajectoryMode::Cohort->value,
                        'status' => TrajectoryStatus::Open->value,
                        'price' => 500,
                        'price_non_member' => 600,
                        'enrollment_deadline' => date('Y-m-d', strtotime('+30 days')),
                        'capacity' => 20,
                    ]);
                }
            } else {
                // Fallback to direct meta
                update_post_meta($testTrajectoryId, '_ntdst_mode', TrajectoryMode::Cohort->value);
                update_post_meta($testTrajectoryId, '_ntdst_status', TrajectoryStatus::Open->value);
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
        $enrolledUser = get_user_by('email', 'seed_enrolled_user@seed.test');
        if (!$enrolledUser) {
            $enrolledUserId = wp_insert_user([
                'user_login' => 'seed_enrolled_user',
                'user_email' => 'seed_enrolled_user@seed.test',
                'user_pass' => 'seedpass123',
                'first_name' => 'Enrolled',
                'last_name' => 'User',
                'display_name' => 'Enrolled User',
                'role' => 'subscriber',
            ]);

            if (is_wp_error($enrolledUserId)) {
                echo "  ! Failed to create seed_enrolled_user: {$enrolledUserId->get_error_message()}\n";
            } else {
                update_user_meta($enrolledUserId, self::SEED_META_KEY, true);
                $this->created['users'][] = $enrolledUserId;
                echo "  + Created seed_enrolled_user@seed.test (ID: {$enrolledUserId})\n";
            }
        } else {
            $enrolledUserId = $enrolledUser->ID;
            if (!in_array($enrolledUserId, $this->created['users'], true)) {
                $this->created['users'][] = $enrolledUserId;
            }
            echo "  - seed_enrolled_user@seed.test already exists (ID: {$enrolledUserId})\n";
        }

        // Enroll user in trajectory
        if ($this->trajectoryEnrollmentRepo && isset($enrolledUserId) && !is_wp_error($enrolledUserId)) {
            $existingEnrollment = $this->trajectoryEnrollmentRepo->findByUserAndTrajectory($enrolledUserId, $testTrajectoryId);
            if (!$existingEnrollment) {
                $enrollmentResult = $this->trajectoryEnrollmentRepo->create([
                    'user_id' => $enrolledUserId,
                    'trajectory_id' => $testTrajectoryId,
                    'status' => 'enrolled',
                ]);
                if (!is_wp_error($enrollmentResult)) {
                    echo "  + Enrolled seed_enrolled_user in test-trajectory\n";
                } else {
                    echo "  ! Failed to enroll: {$enrollmentResult->get_error_message()}\n";
                }
            } else {
                echo "  - seed_enrolled_user already enrolled in test-trajectory\n";
            }
        }

        // Create completed test user
        $completedUser = get_user_by('email', 'seed_completed_user@seed.test');
        if (!$completedUser) {
            $completedUserId = wp_insert_user([
                'user_login' => 'seed_completed_user',
                'user_email' => 'seed_completed_user@seed.test',
                'user_pass' => 'seedpass123',
                'first_name' => 'Completed',
                'last_name' => 'User',
                'display_name' => 'Completed User',
                'role' => 'subscriber',
            ]);

            if (is_wp_error($completedUserId)) {
                echo "  ! Failed to create seed_completed_user: {$completedUserId->get_error_message()}\n";
            } else {
                update_user_meta($completedUserId, self::SEED_META_KEY, true);
                $this->created['users'][] = $completedUserId;
                echo "  + Created seed_completed_user@seed.test (ID: {$completedUserId})\n";
            }
        } else {
            $completedUserId = $completedUser->ID;
            if (!in_array($completedUserId, $this->created['users'], true)) {
                $this->created['users'][] = $completedUserId;
            }
            echo "  - seed_completed_user@seed.test already exists (ID: {$completedUserId})\n";
        }

        // Enroll completed user with completed status
        if ($this->trajectoryEnrollmentRepo && isset($completedUserId) && !is_wp_error($completedUserId)) {
            $existingEnrollment = $this->trajectoryEnrollmentRepo->findByUserAndTrajectory($completedUserId, $testTrajectoryId);
            if (!$existingEnrollment) {
                $enrollmentResult = $this->trajectoryEnrollmentRepo->create([
                    'user_id' => $completedUserId,
                    'trajectory_id' => $testTrajectoryId,
                    'status' => 'completed',
                ]);
                if (!is_wp_error($enrollmentResult)) {
                    // Update with completion timestamp
                    $this->trajectoryEnrollmentRepo->update($enrollmentResult, [
                        'completed_at' => current_time('mysql'),
                    ]);
                    echo "  + Enrolled seed_completed_user in test-trajectory (completed)\n";
                } else {
                    echo "  ! Failed to enroll completed user: {$enrollmentResult->get_error_message()}\n";
                }
            } else {
                echo "  - seed_completed_user already enrolled in test-trajectory\n";
            }
        }

        echo "Trajectory test data complete.\n";
    }

    private function saveSeedManifest(): void {
        update_option('stride_seed_manifest', $this->created);
        update_option('stride_seed_timestamp', current_time('mysql'));
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
        echo "Test credentials: seedpass123\n";
        echo "Admin user (ID 1) enrolled in 3 courses with quotes.\n\n";
        echo "Voucher codes: WELKOM2026, VAD-MEMBER, GRATIS-INTRO, KORTING50, SEEDVOUCHER10\n\n";
        echo "E2E Test Users:\n";
        echo "  - seed_enrolled_user@seed.test (enrolled in test-trajectory)\n";
        echo "  - seed_completed_user@seed.test (completed test-trajectory)\n\n";
        echo "To cleanup: ddev exec bash -c 'FORCE_UNSEED=1 wp eval-file scripts/unseed.php'\n";
    }
}

// Run
$seeder = new StrideSeedData();
$seeder->run();
